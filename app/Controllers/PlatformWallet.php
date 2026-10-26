<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\DataTablesQuery;
use App\Libraries\MasterTenantRepository;
use App\Libraries\TenantConnection;
use App\Libraries\TenantContext;
use App\Libraries\WalletRateCard;
use App\Libraries\WalletReportService;
use App\Libraries\WalletService;
use App\Libraries\WhatsAppCountries;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Platform super admin: Meta WhatsApp rate card (all clients) and each client's wallet.
 */
class PlatformWallet extends BaseController
{
    public function rates(): string|ResponseInterface
    {
        if ($denied = $this->denyUnlessPrimary()) {
            return $denied;
        }

        return view('platform/wallet/rates', [
            'pageTitle'    => 'WhatsApp rates',
            'navActive'    => 'wa-rates',
            'platformName' => (string) session('platform_admin_name'),
            'rates'        => (new WalletRateCard())->all(),
            'fx'           => (new WalletRateCard())->fxAll(),
            'fxUpdated'    => (new WalletRateCard())->fxUpdatedAt(),
        ]);
    }

    public function saveRate(): ResponseInterface
    {
        if ($denied = $this->denyUnlessPrimary()) {
            return $denied;
        }

        $code = strtolower(trim((string) $this->request->getPost('country_code')));
        $name = trim((string) $this->request->getPost('country_name'));
        $row = [
            'country_code' => $code,
            'country_name' => $name !== '' ? $name : WhatsAppCountries::nameOf($code),
            'currency'     => (string) $this->request->getPost('currency'),
        ];
        foreach (WalletRateCard::CATEGORIES as $cat) {
            $row[$cat] = (string) $this->request->getPost($cat);
        }

        if (! (new WalletRateCard())->save($row)) {
            return redirect()->back()->withInput()->with('error', 'Enter a calling code (digits only, or "other") and valid rates.');
        }

        log_activity('update', 'platform', 'Saved WhatsApp rate for +' . $row['country_code'], [
            'platform_admin_id' => (int) session('platform_admin_id'),
            'rate'              => $row,
        ]);

        return redirect()->to('/platform/wa-rates')->with('success', 'Rate saved.');
    }

    /**
     * Save every row edited in the rates grid in one go (rates[code][field]).
     */
    public function saveRatesBulk(): ResponseInterface
    {
        if ($denied = $this->denyUnlessPrimary()) {
            return $denied;
        }

        $rows = $this->request->getPost('rates');
        if (! is_array($rows) || $rows === []) {
            return redirect()->to('/platform/wa-rates')->with('error', 'No changes to save.');
        }

        $card  = new WalletRateCard();
        $saved = 0;
        $bad   = [];
        foreach ($rows as $code => $fields) {
            $code = strtolower(trim((string) $code));
            if (! is_array($fields)) {
                continue;
            }
            $row = [
                'country_code' => $code,
                'country_name' => WhatsAppCountries::nameOf($code),
                'currency'     => (string) ($fields['currency'] ?? 'INR'),
            ];
            foreach (WalletRateCard::CATEGORIES as $cat) {
                $row[$cat] = (string) ($fields[$cat] ?? 0);
            }
            if ($card->save($row)) {
                $saved++;
            } else {
                $bad[] = $row['country_name'] ?: $code;
            }
        }

        log_activity('update', 'platform', 'Updated WhatsApp rates (' . $saved . ' countries)', [
            'platform_admin_id' => (int) session('platform_admin_id'),
            'failed'            => $bad,
        ]);

        if ($bad !== []) {
            return redirect()->to('/platform/wa-rates')->with('error', 'Saved ' . $saved . '. Not saved (check the rates): ' . implode(', ', $bad) . '.');
        }

        return redirect()->to('/platform/wa-rates')->with('success', $saved . ' ' . ($saved === 1 ? 'country' : 'countries') . ' updated.');
    }

    /**
     * Exchange rates: what one unit of each rate-card currency is worth in the wallet currency (INR).
     */
    public function saveFx(): ResponseInterface
    {
        if ($denied = $this->denyUnlessPrimary()) {
            return $denied;
        }

        $fx = $this->request->getPost('fx');
        $result = (new WalletRateCard())->saveFx(is_array($fx) ? $fx : []);

        log_activity('update', 'platform', 'Updated WhatsApp exchange rates (' . $result['saved'] . ')', [
            'platform_admin_id' => (int) session('platform_admin_id'),
            'rates'             => is_array($fx) ? $fx : [],
        ]);

        if ($result['bad'] !== []) {
            return redirect()->to('/platform/wa-rates')->with('error', 'Saved ' . $result['saved'] . '. Not saved (enter a number above 0): ' . implode(', ', $result['bad']) . '.');
        }

        return redirect()->to('/platform/wa-rates')->with('success', $result['saved'] . ' exchange ' . ($result['saved'] === 1 ? 'rate' : 'rates') . ' saved.');
    }

    /**
     * "Fetch latest rates": fill every exchange rate from the public daily feed.
     */
    public function refreshFx(): ResponseInterface
    {
        if ($denied = $this->denyUnlessPrimary()) {
            return $denied;
        }

        $result = (new WalletRateCard())->refreshFxFromWeb();
        log_activity('update', 'platform', 'Fetched WhatsApp exchange rates', [
            'platform_admin_id' => (int) session('platform_admin_id'),
            'ok'                => $result['ok'],
            'saved'             => $result['saved'],
        ]);

        return redirect()->to('/platform/wa-rates')->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function importRates(): ResponseInterface
    {
        if ($denied = $this->denyUnlessPrimary()) {
            return $denied;
        }

        $file = $this->request->getFile('rate_card');
        if ($file === null || ! $file->isValid() || $file->getSize() > 1_048_576
            || ! in_array(strtolower($file->getClientExtension()), ['csv', 'txt'], true)) {
            return redirect()->back()->with('error', 'Choose the Meta rate card CSV (up to 1 MB).');
        }

        $currency = strtoupper(substr(trim((string) $this->request->getPost('currency')), 0, 3)) ?: 'INR';
        $result   = (new WalletRateCard())->importCsv($file->getTempName(), $currency);
        if (! $result['ok']) {
            return redirect()->back()->with('error', $result['message']);
        }

        log_activity('update', 'platform', 'Imported WhatsApp rate card (' . $result['imported'] . ' countries)', [
            'platform_admin_id' => (int) session('platform_admin_id'),
            'skipped'           => $result['skipped'],
        ]);

        $msg = $result['message'];
        if ($result['skipped'] !== []) {
            $msg .= ' Skipped (regional or unknown rows, set them by country): ' . implode(', ', array_slice($result['skipped'], 0, 15)) . '.';
        }

        return redirect()->to('/platform/wa-rates')->with('success', $msg);
    }

    public function deleteRate(string $code): ResponseInterface
    {
        if ($denied = $this->denyUnlessPrimary()) {
            return $denied;
        }

        (new WalletRateCard())->delete($code);
        log_activity('delete', 'platform', 'Deleted WhatsApp rate for +' . $code, ['platform_admin_id' => (int) session('platform_admin_id')]);

        return redirect()->to('/platform/wa-rates')->with('success', 'Rate removed.');
    }

    public function report(): string|ResponseInterface
    {
        $svc     = new WalletReportService();
        $filters = $svc->filters($this->request->getGet());
        $adminId = (int) session('platform_admin_id');
        // KPIs only: the tables load their own pages through reportClients() / reportTransactions().
        $report  = $svc->report($filters, $adminId, 1);

        $this->cacheClients($filters, $adminId, $report['clients'] ?? []);

        return view('platform/wallet/report', [
            'pageTitle'    => 'Wallet report',
            'navActive'    => 'wa-report',
            'platformName' => (string) session('platform_admin_name'),
            'filters'      => $filters,
            'report'       => $report,
            'tenants'      => (new MasterTenantRepository())->listActiveTenants($adminId),
            'query'        => http_build_query(array_filter($filters, static fn (string $v): bool => $v !== '')),
            'exportQuery'  => http_build_query(array_filter(array_diff_key($filters, array_flip(['page', 'per', 'csort', 'cdir'])), static fn (string $v): bool => $v !== '')),
        ]);
    }

    /** Rate card rows for the rates grid (DataTables protocol). Primary platform admin only, like the page. */
    public function ratesData(): ResponseInterface
    {
        if (! (new MasterTenantRepository())->isPrimaryPlatformAdmin((int) session('platform_admin_id'))) {
            return $this->response->setStatusCode(403)->setJSON(['error' => 'Not allowed.']);
        }
        (new WalletRateCard())->ensureTable();

        $out = DataTablesQuery::respond($this->request, [
            'builder'  => static fn () => MasterTenantRepository::masterConnection()->table('wa_rates'),
            'columns'  => [0 => "IF(country_code = 'other', 'zzzz', country_name)"],
            'search'   => ['country_name', 'country_code'],
            'filters'  => static function (\CodeIgniter\Database\BaseBuilder $b, array $get): void {
                $zero = 'marketing <= 0 AND utility <= 0 AND authentication <= 0 AND service <= 0';
                if (($get['status'] ?? '') === 'unset') {
                    $b->where($zero, null, false);
                } elseif (($get['status'] ?? '') === 'priced') {
                    $b->where("NOT ({$zero})", null, false);
                }
            },
            'order'    => [0, 'asc'],
            'tiebreak' => 'country_code',
            'map'      => static function (array $r): array {
                $fmt = static fn ($v): string => rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.') ?: '0';

                return [
                    'code'           => (string) $r['country_code'],
                    'name'           => (string) ($r['country_name'] ?: 'Other countries'),
                    'currency'       => (string) $r['currency'],
                    'marketing'      => $fmt($r['marketing']),
                    'utility'        => $fmt($r['utility']),
                    'authentication' => $fmt($r['authentication']),
                    'service'        => $fmt($r['service']),
                    'unset'          => WalletRateCard::isUnset($r),
                    'delete_url'     => site_url('platform/wa-rates/' . rawurlencode((string) $r['country_code']) . '/delete'),
                ];
            },
        ]);

        return $this->response->setJSON($out);
    }

    /** Server-side page of the cross-client transactions table. Same filters and ownership rules as the report. */
    public function reportTransactions(): ResponseInterface
    {
        $svc  = new WalletReportService();
        $get  = $this->request->getGet();
        $f    = $svc->filters($get);
        $cols = [0 => 'date', 1 => 'client', 2 => 'type', 3 => 'category', 4 => 'country', 5 => 'amount', 6 => 'balance'];
        $ord  = (int) ($get['order'][0]['column'] ?? 0);
        $page = $svc->transactionsPage(
            $f,
            (int) session('platform_admin_id'),
            (int) ($get['start'] ?? 0),
            (int) ($get['length'] ?? 25),
            (string) ($get['search']['value'] ?? ''),
            $cols[$ord] ?? 'date',
            (string) ($get['order'][0]['dir'] ?? 'desc')
        );

        $native = static fn (float $n, string $c, int $dp = 2): string => ($c === 'INR' ? '₹' : $c . ' ') . number_format($n, $dp);
        $data   = array_map(static function (array $r) use ($native): array {
            $cc  = (string) ($r['country_code'] ?? '');
            $cn  = $cc !== '' ? WhatsAppCountries::nameOf($cc) : '';
            $cur = (string) ($r['currency'] ?? 'INR');
            $ts  = strtotime((string) $r['created_at']) ?: 0;

            return [
                'date'     => date('d M Y', $ts),
                'time'     => date('H:i', $ts),
                'client'   => (string) $r['tenant_name'],
                'type'     => (string) $r['type'],
                'category' => (string) ($r['category'] ?? ''),
                'country'  => $cc !== '' ? ($cn !== '' ? $cn : '+' . $cc) : '',
                'incoming' => in_array($r['type'], ['credit', 'refund'], true),
                'amount'   => $native((float) $r['amount'], $cur, 4),
                'balance'  => $native((float) $r['balance_after'], $cur),
                'details'  => (string) ($r['description'] ?? ''),
            ];
        }, $page['rows']);

        return $this->response->setJSON([
            'draw'            => (int) ($get['draw'] ?? 0),
            'recordsTotal'    => $page['total'],
            'recordsFiltered' => $page['filtered'],
            'data'            => $data,
        ]);
    }

    /**
     * Server-side page of the By-client table. The per-client figures come from the report already collected
     * for the page (cached for a few minutes per admin and filter set), so paging does not re-query every client DB.
     */
    public function reportClients(): ResponseInterface
    {
        $svc     = new WalletReportService();
        $get     = $this->request->getGet();
        $f       = $svc->filters($get);
        $adminId = (int) session('platform_admin_id');

        $clients = null;
        try {
            $hit     = cache($this->clientsCacheKey($f, $adminId));
            $clients = is_array($hit) ? $hit : null;
        } catch (\Throwable $e) {
            $clients = null;
        }
        if ($clients === null) {
            $clients = $svc->report($f, $adminId, 1)['clients'] ?? [];
            $this->cacheClients($f, $adminId, $clients);
        }

        $total = count($clients);
        $q     = strtolower(trim((string) ($get['search']['value'] ?? '')));
        if ($q !== '') {
            $clients = array_values(array_filter($clients, static fn (array $c): bool => str_contains(strtolower((string) $c['name'] . ' ' . (string) $c['key'] . ' ' . (string) $c['currency']), $q)));
        }
        $max = 0.0;
        foreach ($clients as $c) {
            $max = max($max, (float) $c['net_spent']);
        }

        $sortKeys = [0 => 'name', 1 => 'balance', 2 => 'recharged', 3 => 'net_spent', 4 => 'margin', 6 => 'messages', 7 => 'sent', 8 => 'delivered', 9 => 'failed', 10 => 'replies'];
        $sk  = $sortKeys[(int) ($get['order'][0]['column'] ?? 3)] ?? 'net_spent';
        $mul = strtolower((string) ($get['order'][0]['dir'] ?? 'desc')) === 'asc' ? 1 : -1;
        usort($clients, static fn (array $a, array $b): int => ($sk === 'name' ? strcasecmp((string) $a['name'], (string) $b['name']) : ((float) $a[$sk] <=> (float) $b[$sk])) * $mul);

        $start  = max(0, (int) ($get['start'] ?? 0));
        $length = max(1, min(100, (int) ($get['length'] ?? 25)));
        $native = static fn (float $n, string $c): string => ($c === 'INR' ? '₹' : $c . ' ') . number_format($n, 2);
        $data   = array_map(static function (array $c) use ($native, $max): array {
            $cur = (string) $c['currency'];

            return [
                'name'          => (string) $c['name'],
                'url'           => site_url('platform/clients/' . rawurlencode((string) $c['key']) . '/wallet'),
                'currency'      => $cur,
                'enabled'       => (bool) $c['enabled'],
                'balance'       => $native((float) $c['balance'], $cur),
                'recharged'     => $native((float) $c['recharged'], $cur),
                'net_spent'     => $native((float) $c['net_spent'], $cur),
                'margin'        => $native((float) $c['margin'], $cur),
                'margin_pos'    => (float) $c['margin'] > 0,
                'share'         => $max > 0 ? round(((float) $c['net_spent'] / $max) * 100, 2) : 0,
                'messages'      => number_format((int) $c['messages']),
                'sent'          => number_format((int) $c['sent']),
                'delivered'     => number_format((int) $c['delivered']),
                'delivered_pct' => (int) $c['sent'] > 0 ? number_format(((int) $c['delivered'] / (int) $c['sent']) * 100, 0) . '%' : '',
                'failed'        => number_format((int) $c['failed']),
                'failed_bad'    => (int) $c['failed'] > 0,
                'replies'       => number_format((int) $c['replies']),
            ];
        }, array_slice($clients, $start, $length));

        return $this->response->setJSON([
            'draw'            => (int) ($get['draw'] ?? 0),
            'recordsTotal'    => $total,
            'recordsFiltered' => count($clients),
            'data'            => $data,
        ]);
    }

    /** @param array<string, string> $f */
    protected function clientsCacheKey(array $f, int $adminId): string
    {
        return 'wr_clients_' . md5($adminId . '|' . json_encode(array_diff_key($f, array_flip(['page', 'per', 'sort', 'dir', 'csort', 'cdir']))));
    }

    /**
     * @param array<string, string> $f
     * @param list<array<string, mixed>> $clients
     */
    protected function cacheClients(array $f, int $adminId, array $clients): void
    {
        try {
            cache()->save($this->clientsCacheKey($f, $adminId), $clients, 300);
        } catch (\Throwable $e) {
            log_message('warning', 'Wallet report client cache save failed: {msg}', ['msg' => $e->getMessage()]);
        }
    }

    public function reportCsv(): ResponseInterface
    {
        $svc     = new WalletReportService();
        $filters = $svc->filters($this->request->getGet());
        $data    = $svc->report($filters, (int) session('platform_admin_id'), WalletReportService::EXPORT_LIMIT);

        // A cell that starts with = + - @ would run as a formula in Excel.
        $safe = static fn (string $v): string => preg_match('/^[=+\-@\t\r]/', $v) === 1 ? "'" . $v : $v;

        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Date', 'Client', 'Type', 'Category', 'Country code', 'Currency', 'Amount', 'Balance after', 'Reference', 'Details']);
        foreach ($data['rows'] as $r) {
            fputcsv($out, [
                $r['created_at'], $safe((string) $r['tenant_name']), $r['type'], $r['category'], $r['country_code'], $r['currency'] ?? '',
                $r['amount'], $r['balance_after'], $safe((string) $r['reference']), $safe((string) $r['description']),
            ]);
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        log_activity('export', 'platform', 'Exported wallet report', ['filters' => $filters, 'platform_admin_id' => (int) session('platform_admin_id')]);

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="wallet-report-' . $filters['from'] . '-to-' . $filters['to'] . '.csv"')
            ->setBody("\xEF\xBB\xBF" . $csv);
    }

    public function client(string $key): string|ResponseInterface
    {
        $tenant = $this->switchToClient($key);
        if ($tenant instanceof ResponseInterface) {
            return $tenant;
        }

        $wallet = new WalletService();

        return view('platform/wallet/client', [
            'pageTitle'    => 'Wallet · ' . (string) ($tenant['name'] ?? $key),
            'navActive'    => 'clients',
            'platformName' => (string) session('platform_admin_name'),
            'tenant'       => $tenant,
            'wallet'       => $wallet->wallet(),
            'freeUsed'    => $wallet->freeServiceUsed(),
            'freeLimit'    => WalletService::FREE_SERVICE_PER_MONTH,
        ]);
    }

    /** Server-side rows for the transactions table (DataTables protocol). */
    public function transactionsData(string $key): ResponseInterface
    {
        $tenant = $this->switchToClient($key);
        if ($tenant instanceof ResponseInterface) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'Client not found.']);
        }

        $get  = $this->request->getGet();
        $cols = ['created_at', 'type', 'description', 'amount', 'balance_after'];
        $ord  = (int) ($get['order'][0]['column'] ?? 0);
        $page = (new WalletService())->transactionsPage([
            'start'  => (int) ($get['start'] ?? 0),
            'length' => (int) ($get['length'] ?? 10),
            'search' => (string) ($get['search']['value'] ?? ''),
            'type'   => (string) ($get['type'] ?? ''),
            'order'  => $cols[$ord] ?? 'created_at',
            'dir'    => (string) ($get['order'][0]['dir'] ?? 'desc'),
        ]);

        $data = WalletService::tableRows($page['rows']);

        return $this->response->setJSON([
            'draw'            => (int) ($get['draw'] ?? 0),
            'recordsTotal'    => $page['total'],
            'recordsFiltered' => $page['filtered'],
            'data'            => $data,
        ]);
    }

    public function action(string $key): ResponseInterface
    {
        $tenant = $this->switchToClient($key);
        if ($tenant instanceof ResponseInterface) {
            return $this->request->isAJAX()
                ? $this->response->setStatusCode(404)->setJSON(['ok' => false, 'message' => 'Client not found.'])
                : $tenant;
        }

        $wallet = new WalletService();
        $action = (string) $this->request->getPost('action');
        $amount = round((float) $this->request->getPost('amount'), 2);
        $note   = trim((string) $this->request->getPost('note'));
        $adminId = (int) session('platform_admin_id');

        $result = match ($action) {
            'settings' => $wallet->updateSettings(
                (float) $this->request->getPost('low_balance_alert'),
                (float) $this->request->getPost('tax_percent'),
                $this->request->getPost('markup_percent') !== null ? (float) $this->request->getPost('markup_percent') : null,
                [
                    'daily_cap'         => (float) $this->request->getPost('daily_cap'),
                    'monthly_cap'       => (float) $this->request->getPost('monthly_cap'),
                    'auto_topup_amount' => (float) $this->request->getPost('auto_topup_amount'),
                ]
            ),
            'enable'  => $wallet->setEnabled(true),
            'disable' => $wallet->setEnabled(false),
            'credit'  => $wallet->credit($amount, $note !== '' ? $note : 'Recharge by platform admin', $adminId),
            'debit'   => $note === ''
                ? ['ok' => false, 'status' => 422, 'message' => 'A note is required when deducting money.']
                : $wallet->manualDebit($amount, $note, $adminId),
            default   => ['ok' => false, 'status' => 422, 'message' => 'Unknown action.'],
        };

        if (! $result['ok']) {
            if ($this->request->isAJAX()) {
                return $this->response->setStatusCode((int) ($result['status'] ?? 422) ?: 422)
                    ->setJSON(['ok' => false, 'message' => (string) $result['message']]);
            }

            return redirect()->back()->withInput()->with('error', (string) $result['message']);
        }

        log_activity('update', 'platform', 'Wallet ' . $action . ' for client ' . $tenant['key'], [
            'tenant_key'        => $tenant['key'],
            'amount'            => in_array($action, ['credit', 'debit'], true) ? $amount : null,
            'note'              => $note,
            'platform_admin_id' => $adminId,
        ]);

        // The All clients page reads a cached copy; update this client's row so the new balance shows at once.
        try {
            (new \App\Libraries\ClientStatsCache())->refresh($tenant);
        } catch (\Throwable $e) {
            log_message('warning', 'Stats cache refresh after wallet action failed: {msg}', ['msg' => $e->getMessage()]);
        }

        if ($this->request->isAJAX()) {
            // Fresh read, so the page can update itself without a reload.
            $fresh = new WalletService();
            $w     = $fresh->wallet();

            return $this->response->setJSON([
                'ok'      => true,
                'message' => (string) $result['message'],
                'wallet'  => [
                    'balance'           => number_format((float) ($w['balance'] ?? 0), 2),
                    'enabled'           => (int) ($w['enabled'] ?? 0) === 1,
                    'markup_percent'    => (string) ($w['markup_percent'] ?? 0),
                    'tax_percent'       => (string) ($w['tax_percent'] ?? 0),
                    'daily_cap'         => (string) ($w['daily_cap'] ?? 0),
                    'monthly_cap'       => (string) ($w['monthly_cap'] ?? 0),
                    'low_balance_alert' => (string) ($w['low_balance_alert'] ?? 0),
                    'auto_topup_amount' => (string) ($w['auto_topup_amount'] ?? 0),
                ],
            ]);
        }

        $page = (string) $this->request->getPost('back') === 'list' ? '/platform/clients/all'
            : ((string) $this->request->getPost('back') === 'show'
                ? '/platform/clients/' . rawurlencode((string) $tenant['key'])
                : '/platform/clients/' . rawurlencode((string) $tenant['key']) . '/wallet');

        return redirect()->to($page)->with('success', (string) $result['message']);
    }

    /**
     * Point the request at one client database. Returns the tenant row, or a redirect on failure.
     *
     * @return array<string, mixed>|ResponseInterface
     */
    protected function switchToClient(string $key): array|ResponseInterface
    {
        $key  = strtolower(trim($key));
        $repo = new MasterTenantRepository();
        $tenant = $repo->findActiveTenant($key);
        if ($tenant === null || ! $repo->platformAdminOwnsTenant((int) session('platform_admin_id'), $key)) {
            return redirect()->to('/platform/clients')->with('error', 'Client not found.');
        }
        if (! (new TenantConnection())->apply($key, 'platform')) {
            return redirect()->to('/platform/clients')->with('error', 'Cannot connect to the client database.');
        }
        TenantContext::set($key, 'platform');

        return $tenant;
    }

    protected function denyUnlessPrimary(): ?ResponseInterface
    {
        if ((new MasterTenantRepository())->isPrimaryPlatformAdmin((int) session('platform_admin_id'))) {
            return null;
        }

        return redirect()->to('/platform/clients')->with('error', 'Only the main platform super admin can change WhatsApp rates.');
    }
}
