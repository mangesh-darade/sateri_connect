<?php

declare(strict_types=1);

namespace App\Libraries;

use Config\Database;
use Throwable;

/**
 * Wallet reports from wallet_transactions: one client (current tenant DB) or every client DB a platform
 * super admin owns.
 *
 * Money words: recharged = credits, spent = message debits, refunded = refunds, deducted = manual
 * corrections. Net spent = spent - refunded.
 */
class WalletReportService
{
    public const TABLE_LIMIT = 300;
    public const EXPORT_LIMIT = 50000;

    /** Sortable transaction columns => ledger column. */
    public const SORT_COLUMNS = [
        'date' => 'created_at', 'type' => 'type', 'category' => 'category', 'country' => 'country_code',
        'amount' => 'amount', 'balance' => 'balance_after', 'client' => 'tenant_name',
    ];
    public const CLIENT_SORTS = ['name', 'balance', 'recharged', 'net_spent', 'margin', 'messages', 'sent', 'delivered', 'failed', 'replies'];
    public const PAGE_SIZES   = [10, 25, 50, 100];
    public const DEFAULT_PER  = 25;

    public function __construct(
        protected MasterTenantRepository $master = new MasterTenantRepository(),
        protected TenantConnection $connection = new TenantConnection(),
    ) {
    }

    /**
     * Report for the client whose DB is the current default connection (client login).
     *
     * @param array<string, string> $f
     *
     * @return array<string, mixed>
     */
    public function forCurrentTenant(array $f, int $rowLimit = self::TABLE_LIMIT, bool $lean = false): array
    {
        $key = (string) (TenantContext::get() ?: session('tenant_key') ?: '');

        return $this->assemble([$this->collect(Database::connect(), $key, $key, $f, $rowLimit, null, $lean)], $rowLimit, false);
    }

    /**
     * Deposits table (server-side DataTable): credit rows in the date range.
     *
     * @param array<string, string> $f
     */
    public function depositsTable(array $f): \CodeIgniter\Database\BaseBuilder
    {
        return Database::connect()->table('wallet_transactions t')
            ->select('t.id, t.created_at, t.description, t.amount, t.balance_after')
            ->where('t.type', 'credit')
            ->where('t.created_at >=', $f['from'] . ' 00:00:00')->where('t.created_at <=', $f['to'] . ' 23:59:59');
    }

    /** SQL for the "Source" column (same rules as sourceOf()). */
    public const SOURCE_SQL = "CASE WHEN t.type = 'credit' THEN 'Recharge' WHEN t.category = 'manual' THEN 'Manual deduction' "
        . "WHEN m.id IS NULL THEN (CASE WHEN t.type = 'refund' THEN 'Refund' ELSE 'Other' END) "
        . "WHEN m.campaign_id IS NOT NULL AND m.campaign_id <> 0 THEN 'Campaign' "
        . "WHEN m.message_type = 'template' THEN 'Template message' ELSE 'Chat message' END";

    /**
     * Ledger table (server-side DataTable): wallet rows with recipient, campaign, template and message status
     * joined in SQL (the ledger keeps Meta's message id in `reference`). Filters match filtered().
     *
     * @param array<string, string> $f
     */
    public function transactionsTable(array $f): \CodeIgniter\Database\BaseBuilder
    {
        $b = Database::connect()->table('wallet_transactions t')
            ->select('t.id, t.created_at, t.type, t.category, t.country_code, t.description, t.amount, t.balance_after, t.reference, '
                . self::SOURCE_SQL . ' AS source, m.status AS msg_status, c.name AS recipient_name, c.mobile AS recipient_mobile, cp.name AS campaign_name, tpl.name AS template_name', false)
            ->join('messages m', 'm.wamid = t.reference', 'left')
            ->join('contacts c', 'c.id = m.contact_id', 'left')
            ->join('campaigns cp', 'cp.id = m.campaign_id', 'left')
            ->join('templates tpl', 'tpl.id = cp.template_id', 'left')
            ->where('t.created_at >=', $f['from'] . ' 00:00:00')->where('t.created_at <=', $f['to'] . ' 23:59:59');
        foreach (['type', 'category'] as $k) {
            if (($f[$k] ?? '') !== '') {
                $b->where('t.' . $k, $f[$k]);
            }
        }
        if (($f['country'] ?? '') !== '') {
            $b->where('t.country_code', $f['country']);
        }

        return $b;
    }

    /**
     * Deposits (recharges) of the current client in the date range, newest first.
     *
     * @param array<string, string> $f
     *
     * @return array{rows: list<array<string, mixed>>, total: float, count: int, largest: float, average: float, last_at: string, truncated: bool}
     */
    public function deposits(array $f, int $limit = self::TABLE_LIMIT): array
    {
        $out = ['rows' => [], 'total' => 0.0, 'count' => 0, 'largest' => 0.0, 'average' => 0.0, 'last_at' => '', 'truncated' => false];
        try {
            $db = Database::connect();
            if (! $db->tableExists('wallet_transactions')) {
                return $out;
            }

            $base = static fn () => $db->table('wallet_transactions')->where('type', 'credit')
                ->where('created_at >=', $f['from'] . ' 00:00:00')->where('created_at <=', $f['to'] . ' 23:59:59');

            $sum = $base()->select('COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total, COALESCE(MAX(amount), 0) AS largest, MAX(created_at) AS last_at', false)
                ->get()->getRowArray() ?: [];
            $out['count']   = (int) ($sum['n'] ?? 0);
            $out['total']   = (float) ($sum['total'] ?? 0);
            $out['largest'] = (float) ($sum['largest'] ?? 0);
            $out['last_at'] = (string) ($sum['last_at'] ?? '');
            $out['average'] = $out['count'] > 0 ? $out['total'] / $out['count'] : 0.0;

            // $limit <= 0: summary only (the page table is a server-side DataTable, see depositsTable()).
            if ($limit > 0) {
                $rows = $base()->orderBy('id', 'DESC')->limit($limit + 1)->get()->getResultArray();
                $out['truncated'] = count($rows) > $limit;
                $out['rows']      = array_slice($rows, 0, $limit);
            }
        } catch (Throwable $e) {
            log_message('error', 'WalletReportService::deposits failed: {msg}', ['msg' => $e->getMessage()]);
        }

        return $out;
    }

    /**
     * Report across all clients owned by the platform admin.
     *
     * @param array<string, string> $f
     *
     * @return array<string, mixed>
     */
    public function report(array $f, ?int $platformAdminId, int $rowLimit = self::TABLE_LIMIT, bool $paged = false): array
    {
        $parts = [];
        $per   = $paged ? ((int) $f['per'] ?: self::DEFAULT_PER) : $rowLimit;
        $page  = $paged ? max(1, (int) $f['page']) : 1;
        $sort  = ['col' => $f['sort'] !== '' ? $f['sort'] : 'date', 'dir' => $f['dir'] !== '' ? $f['dir'] : ($f['sort'] === '' || $f['sort'] === 'date' || $f['sort'] === 'amount' || $f['sort'] === 'balance' ? 'desc' : 'asc')];
        // Each client DB returns its own top (page * per) rows already ordered, so any page can be merged exactly.
        $fetch = $paged ? $page * $per : $rowLimit;
        foreach ($this->master->listActiveTenants($platformAdminId) as $tenant) {
            $key = strtolower(trim((string) ($tenant['key'] ?? '')));
            if ($key === '' || ($f['tenant'] !== '' && $f['tenant'] !== $key)) {
                continue;
            }

            try {
                if (! $this->connection->apply($key, 'platform-wallet-report')) {
                    continue;
                }
                $parts[] = $this->collect(Database::connect(), $key, (string) ($tenant['name'] ?? $key), $f, $fetch, $sort);
            } catch (Throwable $e) {
                log_message('error', 'WalletReportService [{key}]: {msg}', ['key' => $key, 'msg' => $e->getMessage()]);
            }
        }

        return $this->assemble($parts, $rowLimit, true, $paged ? ['sort' => $sort, 'page' => $page, 'per' => $per, 'csort' => $f['csort'], 'cdir' => $f['cdir']] : null);
    }

    /**
     * One page of ledger rows across every client DB the admin owns (DataTables data endpoint).
     * Each client DB counts, searches and returns only its own top (start + length) rows, already ordered, so
     * the merged page is exact without loading every row. Rows are merged and sliced in PHP because the
     * clients live in separate databases.
     *
     * @param array<string, string> $f  filters() output
     *
     * @return array{total:int, filtered:int, rows:list<array<string, mixed>>}
     */
    public function transactionsPage(array $f, ?int $platformAdminId, int $start, int $length, string $search, string $sortCol, string $dir): array
    {
        $start  = max(0, min($start, 100000));
        $length = max(1, min(100, $length));
        $dirSql = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';
        $col    = self::SORT_COLUMNS[$sortCol] ?? 'created_at';
        $search = trim($search);
        $need   = $start + $length;
        $card   = new WalletRateCard();

        $total = 0;
        $filtered = 0;
        $rows  = [];
        foreach ($this->master->listActiveTenants($platformAdminId) as $tenant) {
            $key = strtolower(trim((string) ($tenant['key'] ?? '')));
            if ($key === '' || ($f['tenant'] !== '' && $f['tenant'] !== $key)) {
                continue;
            }

            try {
                if (! $this->connection->apply($key, 'platform-wallet-report')) {
                    continue;
                }
                $db = Database::connect();
                if (! $db->tableExists('wallet_transactions')) {
                    continue;
                }
                $wallet = $db->tableExists('wallets') ? $db->table('wallets')->where('id', 1)->get()->getRowArray() : null;
                $base   = Currency::base();
                $cur    = strtoupper((string) ($wallet['currency'] ?? $base));
                $factor = 1.0;
                if ($cur !== $base) {
                    $conv   = $card->convert(1.0, $cur, $base);
                    $factor = $conv ?? 1.0;
                }

                $total += (int) $this->filtered($db->table('wallet_transactions'), $f)->countAllResults();

                $scoped = function () use ($db, $f, $search) {
                    $b = $this->filtered($db->table('wallet_transactions'), $f);
                    if ($search !== '') {
                        $b->groupStart()->like('description', $search)->orLike('reference', $search)
                            ->orLike('category', $search)->orLike('type', $search)->orLike('country_code', $search)->groupEnd();
                    }

                    return $b;
                };
                $filtered += $search === '' ? 0 : (int) $scoped()->countAllResults();

                $q = $scoped();
                if ($col !== 'tenant_name') {
                    $q->orderBy($col, $dirSql);
                }
                foreach ($q->orderBy('created_at', 'DESC')->orderBy('id', 'DESC')->limit($need)->get()->getResultArray() as $r) {
                    $r['tenant_key']  = $key;
                    $r['tenant_name'] = (string) ($tenant['name'] ?? $key);
                    $r['currency']    = $cur;
                    $r['amount_inr']  = (float) $r['amount'] * $factor;
                    $rows[] = $r;
                }
            } catch (Throwable $e) {
                log_message('error', 'WalletReportService::transactionsPage [{key}]: {msg}', ['key' => $key, 'msg' => $e->getMessage()]);
            }
        }
        if ($search === '') {
            $filtered = $total;
        }

        $mul = $dirSql === 'ASC' ? 1 : -1;
        $key = ['created_at' => 'created_at', 'type' => 'type', 'category' => 'category', 'country_code' => 'country_code', 'amount' => 'amount_inr', 'balance_after' => 'balance_after', 'tenant_name' => 'tenant_name'][$col] ?? 'created_at';
        $num = in_array($key, ['amount_inr', 'balance_after'], true);
        usort($rows, static function (array $a, array $b) use ($key, $num, $mul): int {
            $c = $num ? ((float) $a[$key] <=> (float) $b[$key]) : strcasecmp((string) ($a[$key] ?? ''), (string) ($b[$key] ?? ''));

            return ($c * $mul) ?: (strcmp((string) $b['created_at'], (string) $a['created_at']) ?: ((int) $b['id'] <=> (int) $a['id']));
        });

        return ['total' => $total, 'filtered' => $filtered, 'rows' => array_slice($rows, $start, $length)];
    }

    /**
     * Normalise and validate request filters. Dates default to the last 30 days.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, string>
     */
    public function filters(array $input): array
    {
        $input = array_filter($input, 'is_scalar'); // ?type[]=x must not trigger "Array to string" warnings
        $date = static function (mixed $v, string $fallback): string {
            $v = trim((string) $v);

            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 && strtotime($v) !== false ? $v : $fallback;
        };
        $type    = strtolower(trim((string) ($input['type'] ?? '')));
        $cat     = strtolower(trim((string) ($input['category'] ?? '')));
        $country = strtolower(trim((string) ($input['country'] ?? '')));
        $sort    = strtolower(trim((string) ($input['sort'] ?? '')));
        $dir     = strtolower(trim((string) ($input['dir'] ?? '')));
        $csort   = strtolower(trim((string) ($input['csort'] ?? '')));
        $cdir    = strtolower(trim((string) ($input['cdir'] ?? '')));
        $page    = (int) ($input['page'] ?? 1);
        $per     = (int) ($input['per'] ?? 0);

        return [
            'from'     => $date($input['from'] ?? '', date('Y-m-d', strtotime('-29 days'))),
            'to'       => $date($input['to'] ?? '', date('Y-m-d')),
            'tenant'   => strtolower(trim((string) ($input['tenant'] ?? ''))),
            'type'     => in_array($type, ['credit', 'debit', 'refund'], true) ? $type : '',
            'category' => in_array($cat, ['marketing', 'utility', 'authentication', 'service', 'manual'], true) ? $cat : '',
            'country'  => preg_match('/^(\d{1,4}|other)$/', $country) === 1 ? $country : '',
            // Table order and paging; '' means the default (newest first, page 1, 25 rows).
            'sort'     => isset(self::SORT_COLUMNS[$sort]) ? $sort : '',
            'dir'      => in_array($dir, ['asc', 'desc'], true) ? $dir : '',
            'csort'    => in_array($csort, self::CLIENT_SORTS, true) ? $csort : '',
            'cdir'     => in_array($cdir, ['asc', 'desc'], true) ? $cdir : '',
            'page'     => $page > 1 ? (string) min($page, 100000) : '',
            'per'      => in_array($per, self::PAGE_SIZES, true) && $per !== self::DEFAULT_PER ? (string) $per : '',
        ];
    }

    /**
     * Numbers and rows for one client DB, or null when it has no wallet tables yet.
     *
     * @param array<string, string> $f
     *
     * @return array<string, mixed>|null
     */
    protected function collect(object $db, string $key, string $name, array $f, int $rowLimit, ?array $sort = null, bool $lean = false): ?array
    {
        if (! $db->tableExists('wallet_transactions')) {
            return null;
        }

        $wallet = $db->tableExists('wallets') ? $db->table('wallets')->where('id', 1)->get()->getRowArray() : null;
        $c = ['key' => $key, 'name' => $name, 'currency' => (string) ($wallet['currency'] ?? Currency::base()), 'balance' => (float) ($wallet['balance'] ?? 0), 'enabled' => (int) ($wallet['enabled'] ?? 0),
              'recharged' => 0.0, 'spent' => 0.0, 'refunded' => 0.0, 'deducted' => 0.0, 'messages' => 0,
              'sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0, 'replies' => 0,
              'billed' => 0.0, 'cost' => 0.0, 'tax' => 0.0, 'margin' => 0.0];
        $cats = [];
        $countries = [];
        $free = 0;

        foreach ($this->sumByTypeCategory($db, $f) as $r) {
            $amount = (float) $r['amount'];
            $count  = (int) $r['n'];
            $cat    = (string) ($r['category'] ?? '');
            if ($r['type'] === 'credit') {
                $c['recharged'] += $amount;
            } elseif ($r['type'] === 'refund') {
                $c['refunded'] += $amount;
            } elseif ($cat === 'manual') {
                $c['deducted'] += $amount;
            } else {
                $c['spent'] += $amount;
                if ($amount > 0) {
                    $c['messages'] += $count;
                    $cats[$cat]['messages'] = ($cats[$cat]['messages'] ?? 0) + $count;
                    $cats[$cat]['amount']   = ($cats[$cat]['amount'] ?? 0.0) + $amount;
                } else {
                    $free += $count;
                }
            }
        }
        $c['net_spent'] = $c['spent'] - $c['refunded'];
        // $lean skips the per-client message and margin figures (client report page and exports do not show them).
        if (! $lean) {
            foreach ($this->messageCounts($db, $f) as $k => $v) {
                $c[$k] = $v;
            }
            foreach ($this->marginFigures($db, $f) as $k => $v) {
                $c[$k] = $v;
            }
        }

        foreach ($this->sumByCountry($db, $f) as $r) {
            $code = (string) ($r['country_code'] ?? '');
            $countries[$code]['messages'] = (int) $r['n'];
            $countries[$code]['amount']   = (float) $r['amount'];
        }

        $rows = [];
        $col = self::SORT_COLUMNS[$sort['col'] ?? 'date'] ?? 'created_at';
        $dir = strtoupper((string) ($sort['dir'] ?? 'desc')) === 'ASC' ? 'ASC' : 'DESC';
        $rowsTotal = 0;
        // $rowLimit <= 0: summary only; the page ledger is a server-side DataTable (see transactionsTable()).
        if ($rowLimit > 0) {
            $q = $this->filtered($db->table('wallet_transactions'), $f);
            if ($col !== 'tenant_name') {
                $q->orderBy($col, $dir);
            }
            $q->orderBy('created_at', 'DESC')->orderBy('id', 'DESC')->limit($rowLimit + 1);
            foreach ($q->get()->getResultArray() as $r) {
                $r['tenant_key']  = $key;
                $r['tenant_name'] = $name;
                $r['currency']    = $c['currency'];
                $rows[] = $r;
            }
            $this->attachMessages($db, $rows);
            $rowsTotal = (int) $this->filtered($db->table('wallet_transactions'), $f)->countAllResults();
        }

        return ['client' => $c, 'cats' => $cats, 'countries' => $countries, 'rows' => $rows, 'free' => $free, 'campaigns' => $this->sumByCampaign($db, $f), 'sources' => $this->sumBySource($db, $f),
                'rows_total' => $rowsTotal];
    }

    /**
     * Add recipient, campaign and delivery status to each ledger row. The ledger keeps Meta's message id in
     * `reference`, so no extra column is needed.
     *
     * @param list<array<string, mixed>> $rows
     */
    protected function attachMessages(object $db, array &$rows): void
    {
        $refs = array_values(array_unique(array_filter(array_column($rows, 'reference'), static fn ($v): bool => $v !== null && $v !== '')));
        if ($refs === [] || ! $db->tableExists('messages')) {
            foreach ($rows as &$r) {
                $r['source'] = $this->sourceOf($r, null);
            }
            unset($r);

            return;
        }

        $map = [];
        foreach (array_chunk($refs, 500) as $chunk) {
            $found = $db->table('messages m')
                ->select('m.wamid, m.status AS msg_status, m.message_type, m.campaign_id, c.name AS recipient_name, c.mobile AS recipient_mobile, cp.name AS campaign_name, tpl.name AS template_name', false)
                ->join('contacts c', 'c.id = m.contact_id', 'left')
                ->join('campaigns cp', 'cp.id = m.campaign_id', 'left')
                ->join('templates tpl', 'tpl.id = cp.template_id', 'left')
                ->whereIn('m.wamid', $chunk)->get()->getResultArray();
            foreach ($found as $m) {
                $map[(string) $m['wamid']] = $m;
            }
        }

        foreach ($rows as &$r) {
            $m = $map[(string) ($r['reference'] ?? '')] ?? null;
            $r['recipient_name']   = $m['recipient_name'] ?? null;
            $r['recipient_mobile'] = $m['recipient_mobile'] ?? null;
            $r['campaign_name']    = $m['campaign_name'] ?? null;
            $r['msg_status']       = $m['msg_status'] ?? null;
            $r['template_name']    = $m['template_name'] ?? null;
            $r['source']           = $this->sourceOf($r, $m);
        }
        unset($r);
    }

    /**
     * Billed to the client (before GST), Meta's cost and the platform margin, refunds netted off. Only debits
     * that recorded their Meta cost count, so older rows without it do not distort the margin.
     *
     * @param array<string, string> $f
     *
     * @return array{billed: float, cost: float, tax: float, margin: float}
     */
    protected function marginFigures(object $db, array $f): array
    {
        $out = ['billed' => 0.0, 'cost' => 0.0, 'tax' => 0.0, 'margin' => 0.0];
        if (! $db->fieldExists('base_amount', 'wallet_transactions')) {
            return $out;
        }

        $rows = $this->filtered($db->table('wallet_transactions'), $f)
            ->select('type, COALESCE(SUM(amount), 0) AS a, COALESCE(SUM(base_amount), 0) AS b, COALESCE(SUM(tax_amount), 0) AS t', false)
            ->whereIn('type', ['debit', 'refund'])->where('base_amount IS NOT NULL', null, false)
            ->groupBy('type')->get()->getResultArray();
        foreach ($rows as $r) {
            $sign = $r['type'] === 'refund' ? -1 : 1;
            $out['billed'] += $sign * ((float) $r['a'] - (float) $r['t']);
            $out['cost']   += $sign * (float) $r['b'];
            $out['tax']    += $sign * (float) $r['t'];
        }
        $out['margin'] = $out['billed'] - $out['cost'];

        return $out;
    }

    /**
     * WhatsApp message activity of one client in the date range: what was sent, delivered, read, failed and answered.
     * Sent counts every outbound message that left the platform (sent, delivered and read).
     *
     * @param array<string, string> $f
     *
     * @return array{sent: int, delivered: int, read: int, failed: int, replies: int}
     */
    protected function messageCounts(object $db, array $f): array
    {
        $out = ['sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0, 'replies' => 0];
        if (! $db->tableExists('messages')) {
            return $out;
        }

        $rows = $db->table('messages')
            ->select('direction, status, COUNT(*) AS n', false)
            ->where('channel', 'whatsapp')
            ->where('created_at >=', $f['from'] . ' 00:00:00')->where('created_at <=', $f['to'] . ' 23:59:59')
            ->groupBy('direction, status')->get()->getResultArray();
        foreach ($rows as $r) {
            $n = (int) $r['n'];
            if ($r['direction'] === 'inbound') {
                $out['replies'] += $n;
                continue;
            }
            if (in_array($r['status'], ['sent', 'delivered', 'read'], true)) {
                $out['sent'] += $n;
            }
            if (in_array($r['status'], ['delivered', 'read'], true)) {
                $out['delivered'] += $n;
            }
            if ($r['status'] === 'read') {
                $out['read'] += $n;
            }
            if ($r['status'] === 'failed') {
                $out['failed'] += $n;
            }
        }

        return $out;
    }

    /** What produced this ledger row: a campaign, a one-to-one template, a chat reply, or a wallet action. */
    protected function sourceOf(array $row, ?array $msg): string
    {
        if ($row['type'] === 'credit') {
            return 'Recharge';
        }
        if (($row['category'] ?? '') === 'manual') {
            return 'Manual deduction';
        }
        if ($msg === null) {
            return $row['type'] === 'refund' ? 'Refund' : 'Other';
        }
        if (! empty($msg['campaign_id'])) {
            return 'Campaign';
        }

        return ($msg['message_type'] ?? '') === 'template' ? 'Template message' : 'Chat message';
    }

    /**
     * Paid debits by source and category.
     *
     * @param array<string, string> $f
     *
     * @return list<array{source: string, category: string, messages: int, amount: float}>
     */
    protected function sumBySource(object $db, array $f): array
    {
        if (! $db->tableExists('messages')) {
            return [];
        }

        $case = "CASE WHEN m.id IS NULL THEN 'Other' WHEN m.campaign_id IS NOT NULL THEN 'Campaign' WHEN m.message_type = 'template' THEN 'Template message' ELSE 'Chat message' END";
        $b = $db->table('wallet_transactions t')
            ->select("$case AS source, t.category, COUNT(*) AS n, COALESCE(SUM(t.amount), 0) AS amount", false)
            ->join('messages m', 'm.wamid = t.reference', 'left')
            ->where('t.type', 'debit')->where('t.amount >', 0)->where('t.category !=', 'manual')
            ->where('t.created_at >=', $f['from'] . ' 00:00:00')->where('t.created_at <=', $f['to'] . ' 23:59:59');
        if ($f['category'] !== '') {
            $b->where('t.category', $f['category']);
        }
        if ($f['country'] !== '') {
            $b->where('t.country_code', $f['country']);
        }

        $out = [];
        foreach ($b->groupBy('source, t.category')->get()->getResultArray() as $r) {
            $out[] = ['source' => (string) $r['source'], 'category' => (string) $r['category'], 'messages' => (int) $r['n'], 'amount' => (float) $r['amount']];
        }

        return $out;
    }

    /**
     * Money spent per campaign (paid debits). Messages sent outside a campaign are grouped as one line.
     *
     * @param array<string, string> $f
     *
     * @return list<array{campaign: string, messages: int, amount: float}>
     */
    protected function sumByCampaign(object $db, array $f): array
    {
        if (! $db->tableExists('messages')) {
            return [];
        }

        $b = $db->table('wallet_transactions t')
            ->select("COALESCE(cp.name, 'Direct / chat messages') AS campaign, COUNT(*) AS n, COALESCE(SUM(t.amount), 0) AS amount", false)
            ->join('messages m', 'm.wamid = t.reference', 'left')
            ->join('campaigns cp', 'cp.id = m.campaign_id', 'left')
            ->where('t.type', 'debit')->where('t.amount >', 0)->where('t.category !=', 'manual')
            ->where('t.created_at >=', $f['from'] . ' 00:00:00')->where('t.created_at <=', $f['to'] . ' 23:59:59');
        if ($f['category'] !== '') {
            $b->where('t.category', $f['category']);
        }
        if ($f['country'] !== '') {
            $b->where('t.country_code', $f['country']);
        }

        $out = [];
        foreach ($b->groupBy('campaign')->orderBy('amount', 'DESC')->limit(50)->get()->getResultArray() as $r) {
            $out[] = ['campaign' => (string) $r['campaign'], 'messages' => (int) $r['n'], 'amount' => (float) $r['amount']];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>|null> $parts
     *
     * @return array{
     *   totals: array<string, float|int>,
     *   clients: list<array<string, mixed>>,
     *   categories: list<array<string, mixed>>,
     *   countries: list<array<string, mixed>>,
     *   rows: list<array<string, mixed>>,
     *   truncated: bool,
     *   mixed: bool,
     *   unconverted: list<string>
     * }
     */
    protected function assemble(array $parts, int $rowLimit, bool $toInr = false, ?array $opts = null): array
    {
        $totals = ['billed' => 0.0, 'cost' => 0.0, 'tax' => 0.0, 'margin' => 0.0, 'sent' => 0, 'delivered' => 0, 'failed' => 0, 'replies' => 0, 'recharged' => 0.0, 'spent' => 0.0, 'refunded' => 0.0, 'deducted' => 0.0, 'net_spent' => 0.0, 'messages' => 0, 'free' => 0, 'balance' => 0.0];
        $clients = [];
        $cats = [];
        $countries = [];
        $rows = [];
        $campaigns = [];
        $sources = [];
        $rowsTotal = 0;
        $mixed = false;
        $unconverted = [];
        $card = new WalletRateCard();

        foreach ($parts as $p) {
            if ($p === null) {
                continue;
            }

            // Clients can have different wallet currencies; platform totals are shown in the base currency.
            $factor = 1.0;
            $cur    = strtoupper((string) ($p['client']['currency'] ?? Currency::base()));
            if ($toInr && $cur !== Currency::base()) {
                $mixed = true;
                $f = $card->convert(1.0, $cur, Currency::base());
                if ($f === null) {
                    $unconverted[$cur] = true;
                } else {
                    $factor = $f;
                }
            }

            $clients[] = $p['client'];
            $totals['free'] += $p['free'];
            $totals['messages'] += $p['client']['messages'];
            foreach (['sent', 'delivered', 'failed', 'replies'] as $mk) {
                $totals[$mk] += (int) ($p['client'][$mk] ?? 0);
            }
            foreach (['recharged', 'spent', 'refunded', 'deducted', 'balance', 'billed', 'cost', 'tax', 'margin'] as $k) {
                $totals[$k] += ($p['client'][$k] ?? 0) * $factor;
            }
            foreach ($p['cats'] as $k => $v) {
                $cats[$k]['messages'] = ($cats[$k]['messages'] ?? 0) + $v['messages'];
                $cats[$k]['amount']   = ($cats[$k]['amount'] ?? 0.0) + $v['amount'] * $factor;
            }
            foreach ($p['countries'] as $k => $v) {
                $countries[$k]['messages'] = ($countries[$k]['messages'] ?? 0) + $v['messages'];
                $countries[$k]['amount']   = ($countries[$k]['amount'] ?? 0.0) + $v['amount'] * $factor;
            }
            foreach ($p['rows'] as &$pr) {
                $pr['amount_inr'] = (float) $pr['amount'] * $factor;
            }
            unset($pr);
            array_push($rows, ...$p['rows']);
            $rowsTotal += (int) ($p['rows_total'] ?? 0);
            array_push($campaigns, ...($p['campaigns'] ?? []));
            array_push($sources, ...($p['sources'] ?? []));
        }
        $totals['net_spent'] = $totals['spent'] - $totals['refunded'];

        $page = 1;
        $per  = $rowLimit;
        if ($opts !== null) {
            $col  = (string) $opts['sort']['col'];
            $mul  = $opts['sort']['dir'] === 'asc' ? 1 : -1;
            $page = (int) $opts['page'];
            $per  = (int) $opts['per'];
            $key  = ['date' => 'created_at', 'type' => 'type', 'category' => 'category', 'country' => 'country_code', 'amount' => 'amount_inr', 'balance' => 'balance_after', 'client' => 'tenant_name'][$col] ?? 'created_at';
            $num  = in_array($key, ['amount_inr', 'balance_after'], true);
            usort($rows, static function (array $a, array $b) use ($key, $num, $mul): int {
                $c = $num ? ((float) $a[$key] <=> (float) $b[$key]) : strcasecmp((string) ($a[$key] ?? ''), (string) ($b[$key] ?? ''));

                return ($c * $mul) ?: (strcmp((string) $b['created_at'], (string) $a['created_at']) ?: ((int) $b['id'] <=> (int) $a['id']));
            });
            $rows      = array_slice($rows, ($page - 1) * $per, $per);
            $truncated = false;
        } else {
            usort($rows, static fn (array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']) ?: ((int) $b['id'] <=> (int) $a['id']));
            $truncated = count($rows) > $rowLimit;
            if ($truncated) {
                $rows = array_slice($rows, 0, $rowLimit);
            }
        }

        $cs   = (string) ($opts['csort'] ?? '');
        $cmul = (string) ($opts['cdir'] ?? '') === 'asc' ? 1 : -1;
        if ($cs === 'name') {
            usort($clients, static fn (array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']) * ((string) ($opts['cdir'] ?? '') === 'desc' ? -1 : 1));
        } elseif ($cs !== '') {
            usort($clients, static fn (array $a, array $b): int => ((float) $a[$cs] <=> (float) $b[$cs]) * $cmul);
        } else {
            usort($clients, static fn (array $a, array $b): int => $b['net_spent'] <=> $a['net_spent']);
        }

        return [
            'totals'     => $totals,
            'clients'    => $clients,
            'categories' => $this->flatten($cats, 'category'),
            'countries'  => $this->flatten($countries, 'country_code'),
            'rows'       => $rows,
            'campaigns'  => $campaigns,
            'sources'    => $sources,
            'truncated'  => $truncated,
            'total_rows' => $rowsTotal,
            'page'       => $page,
            'per'        => $per,
            'pages'      => $opts !== null ? max(1, (int) ceil($rowsTotal / max(1, $per))) : 1,
            'mixed'      => $mixed,
            'unconverted' => array_keys($unconverted),
        ];
    }

    /**
     * @param \CodeIgniter\Database\BaseBuilder $b
     * @param array<string, string> $f
     *
     * @return \CodeIgniter\Database\BaseBuilder
     */
    protected function filtered($b, array $f)
    {
        $b->where('created_at >=', $f['from'] . ' 00:00:00')->where('created_at <=', $f['to'] . ' 23:59:59');
        foreach (['type', 'category'] as $k) {
            if ($f[$k] !== '') {
                $b->where($k, $f[$k]);
            }
        }
        if ($f['country'] !== '') {
            $b->where('country_code', $f['country']);
        }

        return $b;
    }

    /**
     * @param array<string, string> $f
     *
     * @return list<array<string, mixed>>
     */
    protected function sumByTypeCategory(object $db, array $f): array
    {
        return $this->filtered($db->table('wallet_transactions'), $f)
            ->select('type, category, COUNT(*) AS n, COALESCE(SUM(amount), 0) AS amount', false)
            ->groupBy('type, category')->get()->getResultArray();
    }

    /**
     * @param array<string, string> $f
     *
     * @return list<array<string, mixed>>
     */
    protected function sumByCountry(object $db, array $f): array
    {
        return $this->filtered($db->table('wallet_transactions'), $f)
            ->select('country_code, COUNT(*) AS n, COALESCE(SUM(amount), 0) AS amount', false)
            ->where('type', 'debit')->where('amount >', 0)
            ->groupBy('country_code')->get()->getResultArray();
    }

    /**
     * @param array<string, array<string, mixed>> $map
     *
     * @return list<array<string, mixed>>
     */
    protected function flatten(array $map, string $label): array
    {
        $out = [];
        foreach ($map as $k => $v) {
            $out[] = [$label => (string) $k, 'messages' => (int) ($v['messages'] ?? 0), 'amount' => (float) ($v['amount'] ?? 0)];
        }
        usort($out, static fn (array $a, array $b): int => $b['amount'] <=> $a['amount']);

        return $out;
    }
}
