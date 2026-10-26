<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\EmailLogModel;
use CodeIgniter\Database\BaseBuilder;
use DateTimeImmutable;

/**
 * One source for Analytics / Reports / Delivery numbers.
 *
 * WhatsApp figures are campaign sends only (messages.campaign_id set).
 * Inbox chat is excluded. A reply counts only when it arrives from a contact
 * after that contact was sent the campaign.
 */
class ReportService
{
    public const RECIPIENT_PREVIEW = 100;

    /**
     * @return array{from:string,to:string,from_utc:string,to_utc:string}
     */
    public function dateRange(?string $from, ?string $to): array
    {
        $from = trim((string) $from);
        $to   = trim((string) $to);
        if ($from === '') {
            $from = (new DateTimeImmutable('now', AppDateTime::appZone()))
                ->modify('first day of this month')
                ->format('Y-m-d');
        }
        if ($to === '') {
            $to = function_exists('app_today_ymd') ? app_today_ymd() : date('Y-m-d');
        }

        try {
            $start = new DateTimeImmutable($from, AppDateTime::appZone());
            $end   = new DateTimeImmutable($to, AppDateTime::appZone());
        } catch (\Throwable) {
            $start = (new DateTimeImmutable('now', AppDateTime::appZone()))->modify('first day of this month');
            $end   = new DateTimeImmutable('now', AppDateTime::appZone());
            $from  = $start->format('Y-m-d');
            $to    = $end->format('Y-m-d');
        }

        if ($end < $start) {
            [$from, $to] = [$to, $from];
        }

        [$fromUtc, $toUtc] = AppDateTime::rangeBoundsUtc($from, $to);

        return [
            'from'     => $from,
            'to'       => $to,
            'from_utc' => $fromUtc,
            'to_utc'   => $toUtc,
        ];
    }

    /**
     * @param array{from:string,to:string,from_utc:string,to_utc:string} $range
     * @return array{
     *   summary:array<string,int|float>,
     *   daily:list<array<string,int|string>>,
     *   campaigns:list<array<string,int|float|string>>,
     *   recipients:list<array<string,mixed>>,
     *   recipient_total:int,
     *   campaign_options:list<array{id:int,name:string}>
     * }
     */
    public function whatsappReport(array $range, ?int $campaignId, bool $tables = true): array
    {
        $campaignId = ($campaignId !== null && $campaignId > 0) ? $campaignId : null;
        $summary    = $this->whatsappSummary($range['from_utc'], $range['to_utc'], $campaignId);
        $replies    = $this->whatsappRepliesByCampaign($range['from_utc'], $range['to_utc'], $campaignId);
        $summary['replies'] = array_sum($replies);

        // $tables = false: the page tables are server-side DataTables (Reports::campaignsData / recipientsData),
        // so the breakdown and recipient lists are not loaded here. Exports keep the default (full data).
        $breakdown  = $tables ? $this->whatsappCampaignBreakdown($range['from_utc'], $range['to_utc'], $campaignId, $replies) : [];
        $recipients = $tables ? $this->whatsappRecipients($range['from_utc'], $range['to_utc'], $campaignId, self::RECIPIENT_PREVIEW) : ['rows' => [], 'total' => 0];

        return [
            'summary'          => $summary,
            'daily'            => $this->whatsappDaily($range['from'], $range['to'], $range['from_utc'], $range['to_utc'], $campaignId),
            'campaigns'        => $breakdown,
            'recipients'       => $recipients['rows'],
            'recipient_total'  => $recipients['total'],
            'campaign_options' => $this->whatsappCampaignOptions(),
        ];
    }

    /**
     * @param array{from:string,to:string,from_utc:string,to_utc:string} $range
     * @return array{
     *   summary:array<string,int|float>,
     *   daily:list<array<string,int|string>>,
     *   campaigns:list<array<string,int|float|string>>,
     *   logs:list<array<string,mixed>>,
     *   unsubscribes:list<array<string,mixed>>,
     *   campaign_options:list<array{id:int,name:string}>
     * }
     */
    public function emailReport(array $range, ?int $campaignId, bool $tables = true): array
    {
        $campaignId = ($campaignId !== null && $campaignId > 0) ? $campaignId : null;
        $summary    = $this->emailSummary($range['from_utc'], $range['to_utc'], $campaignId);
        $unsubs     = $this->emailUnsubscribes($range['from_utc'], $range['to_utc'], $campaignId, 20);
        $summary['unsubscribes'] = $unsubs['count'];

        return [
            'summary'          => $summary,
            'daily'            => $this->emailDaily($range['from'], $range['to'], $range['from_utc'], $range['to_utc'], $campaignId),
            'campaigns'        => $tables ? $this->emailCampaignBreakdown($range['from_utc'], $range['to_utc'], $campaignId) : [],
            'logs'             => $tables ? $this->emailLogs($range['from_utc'], $range['to_utc'], $campaignId, 50) : [],
            'unsubscribes'     => $unsubs['rows'],
            'campaign_options' => $this->emailCampaignOptions(),
        ];
    }

    /**
     * Broadcast results grouped by the WhatsApp template they used. Only template sends that belong to
     * a campaign are counted; template messages sent by automations or one by one are not tracked here.
     *
     * @param array{from:string,to:string,from_utc:string,to_utc:string} $range
     *
     * @return list<array<string, mixed>>
     */
    public function whatsappTemplateReport(array $range): array
    {
        $db = db_connect();
        if (! $db->tableExists('messages') || ! $db->tableExists('campaigns') || ! $db->tableExists('templates')) {
            return [];
        }

        $rows = $db->table('messages m')
            ->select("t.id, t.name, t.language, t.category, t.status AS template_status,
                COUNT(DISTINCT c.id) AS campaigns,
                SUM(CASE WHEN m.status IN ('sent','delivered','read') THEN 1 ELSE 0 END) AS sent,
                SUM(CASE WHEN m.status IN ('delivered','read') THEN 1 ELSE 0 END) AS delivered,
                SUM(CASE WHEN m.status = 'read' THEN 1 ELSE 0 END) AS `read`,
                SUM(CASE WHEN m.status = 'failed' THEN 1 ELSE 0 END) AS failed", false)
            ->join('campaigns c', 'c.id = m.campaign_id')
            ->join('templates t', 't.id = c.template_id')
            ->where('m.direction', 'outbound')
            ->where('m.created_at >=', $range['from_utc'])
            ->where('m.created_at <=', $range['to_utc'])
            ->groupBy('t.id, t.name, t.language, t.category, t.status')
            ->orderBy('sent', 'DESC')
            ->get()->getResultArray();

        foreach ($rows as &$r) {
            $sent  = (int) $r['sent'];
            $total = $sent + (int) $r['failed'];
            $r['delivery_rate'] = $sent > 0 ? round(((int) $r['delivered'] / $sent) * 100, 1) : 0.0;
            $r['read_rate']     = $sent > 0 ? round(((int) $r['read'] / $sent) * 100, 1) : 0.0;
            $r['fail_rate']     = $total > 0 ? round(((int) $r['failed'] / $total) * 100, 1) : 0.0;
        }
        unset($r);

        return $rows;
    }

    /**
     * @return array{sent:int,delivered:int,read:int,failed:int,pending:int,replies:int,sent_only:int,delivered_only:int,delivery_rate:float,read_rate:float,fail_rate:float}
     */
    public function whatsappSummary(string $fromUtc, string $toUtc, ?int $campaignId = null): array
    {
        $builder = db_connect()->table('messages')
            ->select("
                SUM(CASE WHEN status IN ('sent','delivered','read') THEN 1 ELSE 0 END) AS sent,
                SUM(CASE WHEN status IN ('delivered','read') THEN 1 ELSE 0 END) AS delivered,
                SUM(CASE WHEN status = 'read' THEN 1 ELSE 0 END) AS `read`,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent_only,
                SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) AS delivered_only
            ", false)
            ->where('direction', 'outbound')
            ->where('campaign_id >', 0)
            ->where('created_at >=', $fromUtc)
            ->where('created_at <=', $toUtc);

        if ($campaignId !== null && $campaignId > 0) {
            $builder->where('campaign_id', $campaignId);
        }

        $row  = $builder->get()->getRowArray() ?: [];
        $sent = (int) ($row['sent'] ?? 0);
        $delivered = (int) ($row['delivered'] ?? 0);
        $read = (int) ($row['read'] ?? 0);
        $failed = (int) ($row['failed'] ?? 0);

        return [
            'sent'            => $sent,
            'delivered'       => $delivered,
            'read'            => $read,
            'failed'          => $failed,
            'pending'         => (int) ($row['pending'] ?? 0),
            'replies'         => 0,
            'sent_only'       => (int) ($row['sent_only'] ?? 0),
            'delivered_only'  => (int) ($row['delivered_only'] ?? 0),
            'delivery_rate'   => $this->rate($delivered, $sent),
            'read_rate'       => $this->rate($read, $delivered),
            'fail_rate'       => $this->rate($failed, $sent + $failed),
        ];
    }

    /**
     * @return list<array{date:string,label:string,sent:int,delivered:int,failed:int,pending:int}>
     */
    public function whatsappDaily(string $fromYmd, string $toYmd, string $fromUtc, string $toUtc, ?int $campaignId = null): array
    {
        $offset = $this->sqlOffset($fromYmd);
        $daySql = "DATE(CONVERT_TZ(created_at, '+00:00', " . db_connect()->escape($offset) . '))';

        $builder = db_connect()->table('messages')
            ->select("
                {$daySql} AS day,
                SUM(CASE WHEN status IN ('sent','delivered','read') THEN 1 ELSE 0 END) AS sent,
                SUM(CASE WHEN status IN ('delivered','read') THEN 1 ELSE 0 END) AS delivered,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending
            ", false)
            ->where('direction', 'outbound')
            ->where('campaign_id >', 0)
            ->where('created_at >=', $fromUtc)
            ->where('created_at <=', $toUtc)
            ->groupBy('day');

        if ($campaignId !== null && $campaignId > 0) {
            $builder->where('campaign_id', $campaignId);
        }

        $byDay = [];
        foreach ($builder->get()->getResultArray() as $row) {
            $day = (string) ($row['day'] ?? '');
            if ($day === '') {
                continue;
            }
            $byDay[$day] = [
                'sent'      => (int) ($row['sent'] ?? 0),
                'delivered' => (int) ($row['delivered'] ?? 0),
                'failed'    => (int) ($row['failed'] ?? 0),
                'pending'   => (int) ($row['pending'] ?? 0),
            ];
        }

        return $this->fillDays($fromYmd, $toYmd, $byDay, ['sent', 'delivered', 'failed', 'pending']);
    }

    /**
     * @param array<int,int> $repliesByCampaign
     * @return list<array{id:int,name:string,sent_count:int,delivered_count:int,read_count:int,failed_count:int,pending_count:int,reply_count:int,delivery_rate:float}>
     */
    public function whatsappCampaignBreakdown(string $fromUtc, string $toUtc, ?int $campaignId, array $repliesByCampaign = []): array
    {
        $builder = db_connect()->table('messages')
            ->select("
                campaign_id,
                SUM(CASE WHEN status IN ('sent','delivered','read') THEN 1 ELSE 0 END) AS sent_count,
                SUM(CASE WHEN status IN ('delivered','read') THEN 1 ELSE 0 END) AS delivered_count,
                SUM(CASE WHEN status = 'read' THEN 1 ELSE 0 END) AS read_count,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count
            ", false)
            ->where('direction', 'outbound')
            ->where('campaign_id >', 0)
            ->where('created_at >=', $fromUtc)
            ->where('created_at <=', $toUtc)
            ->groupBy('campaign_id');

        if ($campaignId !== null && $campaignId > 0) {
            $builder->where('campaign_id', $campaignId);
        }

        $live = [];
        foreach ($builder->get()->getResultArray() as $row) {
            $id = (int) ($row['campaign_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $live[$id] = $row;
        }

        $ids = array_values(array_unique(array_merge(array_keys($live), array_keys($repliesByCampaign))));
        if ($campaignId !== null && $campaignId > 0 && ! in_array($campaignId, $ids, true)) {
            $ids[] = $campaignId;
        }

        $names = $this->namesById('campaigns', $ids);
        $out   = [];
        foreach ($ids as $id) {
            $row = $live[$id] ?? [];
            $sent = (int) ($row['sent_count'] ?? 0);
            $delivered = (int) ($row['delivered_count'] ?? 0);
            $replies = (int) ($repliesByCampaign[$id] ?? 0);
            $failed = (int) ($row['failed_count'] ?? 0);
            $pending = (int) ($row['pending_count'] ?? 0);
            if ($campaignId === null && ($sent + $delivered + $failed + $pending + $replies) === 0) {
                continue;
            }
            $out[] = [
                'id'              => $id,
                'name'            => $names[$id] ?? ('Campaign #' . $id),
                'sent_count'      => $sent,
                'delivered_count' => $delivered,
                'read_count'      => (int) ($row['read_count'] ?? 0),
                'failed_count'    => $failed,
                'pending_count'   => $pending,
                'reply_count'     => $replies,
                'delivery_rate'   => $this->rate($delivered, $sent),
            ];
        }

        usort($out, static fn (array $a, array $b): int => ($b['sent_count'] <=> $a['sent_count']) ?: strcmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * @return array{total:int,rows:list<array<string,mixed>>}
     */
    public function whatsappRecipients(string $fromUtc, string $toUtc, ?int $campaignId, ?int $limit = self::RECIPIENT_PREVIEW, int $offset = 0): array
    {
        $total = (int) $this->recipientBuilder($fromUtc, $toUtc, $campaignId)->countAllResults();

        $builder = $this->recipientBuilder($fromUtc, $toUtc, $campaignId)
            ->select('campaign_contacts.contact_id, campaign_contacts.campaign_id, campaign_contacts.status, campaign_contacts.error_message, campaign_contacts.sent_at, campaign_contacts.delivered_at, campaign_contacts.read_at, contacts.name, contacts.mobile, campaigns.name AS campaign_name')
            ->orderBy('COALESCE(campaign_contacts.sent_at, campaign_contacts.created_at)', 'DESC', false);

        if ($limit !== null && $limit > 0) {
            $builder->limit($limit, max(0, $offset));
        }

        $rows = [];
        foreach ($builder->get()->getResultArray() as $row) {
            $rows[] = [
                'contact_id'    => (int) ($row['contact_id'] ?? 0),
                'campaign_id'   => (int) ($row['campaign_id'] ?? 0),
                'name'          => (string) ($row['name'] ?? ''),
                'mobile'        => (string) ($row['mobile'] ?? ''),
                'status'        => (string) ($row['status'] ?? ''),
                'campaign_name' => (string) ($row['campaign_name'] ?? ''),
                'sent_at'       => $row['sent_at'] ?? null,
                'delivered_at'  => $row['delivered_at'] ?? null,
                'read_at'       => $row['read_at'] ?? null,
                'error_message' => (string) ($row['error_message'] ?? ''),
            ];
        }

        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * @return array{campaign_id:int,campaign_name:string,total:int,page:int,per_page:int,contacts:list<array<string,mixed>>}
     */
    public function campaignContactsPage(int $campaignId, string $fromUtc, string $toUtc, int $page, int $perPage): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(500, $perPage));
        $bundle  = $this->whatsappRecipients($fromUtc, $toUtc, $campaignId, $perPage, ($page - 1) * $perPage);
        $name    = $this->namesById('campaigns', [$campaignId])[$campaignId] ?? '';

        $contacts = [];
        foreach ($bundle['rows'] as $row) {
            $contacts[] = [
                'contact_id'    => $row['contact_id'],
                'name'          => $row['name'],
                'mobile'        => $row['mobile'],
                'status'        => $row['status'],
                'sent_at'       => format_app_datetime($row['sent_at'] ?? null),
                'delivered_at'  => format_app_datetime($row['delivered_at'] ?? null),
                'read_at'       => format_app_datetime($row['read_at'] ?? null),
                'error_message' => $row['error_message'],
            ];
        }

        return [
            'campaign_id'   => $campaignId,
            'campaign_name' => $name,
            'total'         => $bundle['total'],
            'page'          => $page,
            'per_page'      => $perPage,
            'contacts'      => $contacts,
        ];
    }

    /**
     * @return array{sent:int,failed:int,queued:int,total:int,opened:int,clicked:int,open_rate:float,click_rate:float,ctor:float,fail_rate:float,unsubscribes:int}
     */
    public function emailSummary(string $fromUtc, string $toUtc, ?int $campaignId = null): array
    {
        $row = $this->emailAggregate($fromUtc, $toUtc, $campaignId, false);
        $sent = (int) ($row['sent'] ?? 0);
        $opened = (int) ($row['opened'] ?? 0);
        $clicked = (int) ($row['clicked'] ?? 0);
        $failed = (int) ($row['failed'] ?? 0);

        return [
            'sent'         => $sent,
            'failed'       => $failed,
            'queued'       => (int) ($row['queued'] ?? 0),
            'total'        => (int) ($row['total'] ?? 0),
            'opened'       => $opened,
            'clicked'      => $clicked,
            'open_rate'    => $this->rate($opened, $sent),
            'click_rate'   => $this->rate($clicked, $sent),
            'ctor'         => $this->rate($clicked, $opened),
            'fail_rate'    => $this->rate($failed, $sent + $failed),
            'unsubscribes' => 0,
        ];
    }

    /**
     * @return list<array{date:string,label:string,sent:int,failed:int,queued:int}>
     */
    public function emailDaily(string $fromYmd, string $toYmd, string $fromUtc, string $toUtc, ?int $campaignId = null): array
    {
        if (! db_connect()->tableExists('email_logs')) {
            return $this->fillDays($fromYmd, $toYmd, [], ['sent', 'failed', 'queued']);
        }

        $offset = $this->sqlOffset($fromYmd);
        $daySql = "DATE(CONVERT_TZ(created_at, '+00:00', " . db_connect()->escape($offset) . '))';

        $builder = db_connect()->table('email_logs')
            ->select("
                {$daySql} AS day,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) AS queued
            ", false)
            ->where('created_at >=', $fromUtc)
            ->where('created_at <=', $toUtc)
            ->groupBy('day');

        $this->applyEmailCampaignFilter($builder, $campaignId);

        $byDay = [];
        foreach ($builder->get()->getResultArray() as $row) {
            $day = (string) ($row['day'] ?? '');
            if ($day === '') {
                continue;
            }
            $byDay[$day] = [
                'sent'   => (int) ($row['sent'] ?? 0),
                'failed' => (int) ($row['failed'] ?? 0),
                'queued' => (int) ($row['queued'] ?? 0),
            ];
        }

        return $this->fillDays($fromYmd, $toYmd, $byDay, ['sent', 'failed', 'queued']);
    }

    /**
     * @return list<array{id:int,name:string,sent_count:int,queued_count:int,failed_count:int,opened_count:int,clicked_count:int,open_rate:float}>
     */
    public function emailCampaignBreakdown(string $fromUtc, string $toUtc, ?int $campaignId = null): array
    {
        if (! db_connect()->tableExists('email_logs')) {
            return [];
        }

        $builder = db_connect()->table('email_logs')
            ->select("
                html_campaign_id,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent_count,
                SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) AS queued_count,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
                SUM(CASE WHEN open_count > 0 THEN 1 ELSE 0 END) AS opened_count,
                SUM(CASE WHEN click_count > 0 THEN 1 ELSE 0 END) AS clicked_count
            ", false)
            ->where('created_at >=', $fromUtc)
            ->where('created_at <=', $toUtc)
            ->where('html_campaign_id >', 0)
            ->groupBy('html_campaign_id');

        if ($campaignId !== null && $campaignId > 0) {
            $builder->where('html_campaign_id', $campaignId);
        }

        $live = [];
        $ids  = [];
        foreach ($builder->get()->getResultArray() as $row) {
            $id = (int) ($row['html_campaign_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $live[$id] = $row;
            $ids[] = $id;
        }

        if ($campaignId !== null && $campaignId > 0 && ! isset($live[$campaignId])) {
            $ids[] = $campaignId;
        }

        $names = $this->emailNamesById($ids);
        $out   = [];
        foreach ($ids as $id) {
            $row = $live[$id] ?? [];
            $sent = (int) ($row['sent_count'] ?? 0);
            $out[] = [
                'id'            => $id,
                'name'          => $names[$id] ?? ('Campaign #' . $id),
                'sent_count'    => $sent,
                'queued_count'  => (int) ($row['queued_count'] ?? 0),
                'failed_count'  => (int) ($row['failed_count'] ?? 0),
                'opened_count'  => (int) ($row['opened_count'] ?? 0),
                'clicked_count' => (int) ($row['clicked_count'] ?? 0),
                'open_rate'     => $this->rate((int) ($row['opened_count'] ?? 0), $sent),
            ];
        }

        if ($campaignId === null) {
            $other = $this->emailAggregate($fromUtc, $toUtc, null, true);
            $otherTotal = (int) ($other['total'] ?? 0);
            if ($otherTotal > 0) {
                $sent = (int) ($other['sent'] ?? 0);
                $out[] = [
                    'id'            => 0,
                    'name'          => 'Single, bulk, and drip',
                    'sent_count'    => $sent,
                    'queued_count'  => (int) ($other['queued'] ?? 0),
                    'failed_count'  => (int) ($other['failed'] ?? 0),
                    'opened_count'  => (int) ($other['opened'] ?? 0),
                    'clicked_count' => (int) ($other['clicked'] ?? 0),
                    'open_rate'     => $this->rate((int) ($other['opened'] ?? 0), $sent),
                ];
            }
        }

        usort($out, static fn (array $a, array $b): int => ($b['sent_count'] <=> $a['sent_count']) ?: strcmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function emailLogs(string $fromUtc, string $toUtc, ?int $campaignId, int $limit = 50): array
    {
        if (! db_connect()->tableExists('email_logs')) {
            return [];
        }

        $builder = model(EmailLogModel::class)
            ->where('created_at >=', $fromUtc)
            ->where('created_at <=', $toUtc)
            ->orderBy('id', 'DESC');

        if ($campaignId !== null && $campaignId > 0) {
            $builder->where('html_campaign_id', $campaignId);
        }

        $logs = [];
        foreach ($builder->findAll(max(1, $limit)) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $logs[] = [
                'id'          => (int) ($row['id'] ?? 0),
                'created_at'  => (string) ($row['created_at'] ?? ''),
                'kind'        => (string) ($row['kind'] ?? ''),
                'to_email'    => (string) ($row['to_email'] ?? ''),
                'subject'     => (string) ($row['subject'] ?? ''),
                'provider'    => (string) ($row['provider'] ?? ''),
                'status'      => (string) ($row['status'] ?? ''),
                'open_count'  => (int) ($row['open_count'] ?? 0),
                'click_count' => (int) ($row['click_count'] ?? 0),
            ];
        }

        return $logs;
    }

    // ------------------------------------------------------------------------------------------
    // Server-side DataTables builders. Aggregated tables are a derived table (inner GROUP BY, outer
    // plain SELECT) so DataTablesQuery can count, search, sort and page them in SQL.
    // ------------------------------------------------------------------------------------------

    private function derived(BaseBuilder $inner, string $alias = 'x'): BaseBuilder
    {
        return db_connect()->newQuery()->fromSubquery($inner, $alias);
    }

    /**
     * One row per campaign: id, name, sent_count, delivered_count, read_count, failed_count, pending_count.
     * Replies are attached per page by the controller.
     */
    public function whatsappCampaignTable(string $fromUtc, string $toUtc, ?int $campaignId): BaseBuilder
    {
        $inner = db_connect()->table('messages m')
            ->select("m.campaign_id AS id, COALESCE(MAX(c.name), CONCAT('Campaign #', m.campaign_id)) AS name,
                SUM(CASE WHEN m.status IN ('sent','delivered','read') THEN 1 ELSE 0 END) AS sent_count,
                SUM(CASE WHEN m.status IN ('delivered','read') THEN 1 ELSE 0 END) AS delivered_count,
                SUM(CASE WHEN m.status = 'read' THEN 1 ELSE 0 END) AS read_count,
                SUM(CASE WHEN m.status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
                SUM(CASE WHEN m.status = 'pending' THEN 1 ELSE 0 END) AS pending_count", false)
            ->join('campaigns c', 'c.id = m.campaign_id', 'left')
            ->where('m.direction', 'outbound')
            ->where('m.campaign_id >', 0)
            ->where('m.created_at >=', $fromUtc)
            ->where('m.created_at <=', $toUtc)
            ->groupBy('m.campaign_id');
        if ($campaignId !== null && $campaignId > 0) {
            $inner->where('m.campaign_id', $campaignId);
        }

        return $this->derived($inner);
    }

    /** Campaign recipients in the range, with contact and campaign names. */
    public function whatsappRecipientTable(string $fromUtc, string $toUtc, ?int $campaignId): BaseBuilder
    {
        return $this->recipientBuilder($fromUtc, $toUtc, $campaignId)
            ->select('campaign_contacts.id, campaign_contacts.contact_id, campaign_contacts.campaign_id, campaign_contacts.status, campaign_contacts.error_message, campaign_contacts.sent_at, campaign_contacts.delivered_at, campaign_contacts.read_at, contacts.name, contacts.mobile, campaigns.name AS campaign_name');
    }

    /**
     * One row per email campaign; sends without a campaign (single, bulk, drip) are one row with id 0 when no
     * campaign filter is active. Columns: id, name, sent_count, queued_count, failed_count, opened_count, clicked_count.
     */
    public function emailCampaignTable(string $fromUtc, string $toUtc, ?int $campaignId): BaseBuilder
    {
        $inner = db_connect()->table('email_logs l')
            ->select("COALESCE(l.html_campaign_id, 0) AS id,
                CASE WHEN COALESCE(l.html_campaign_id, 0) = 0 THEN 'Single, bulk, and drip'
                     ELSE COALESCE(NULLIF(TRIM(MAX(h.name)), ''), NULLIF(TRIM(MAX(h.subject)), ''), CONCAT('Campaign #', l.html_campaign_id)) END AS name,
                SUM(CASE WHEN l.status = 'sent' THEN 1 ELSE 0 END) AS sent_count,
                SUM(CASE WHEN l.status = 'queued' THEN 1 ELSE 0 END) AS queued_count,
                SUM(CASE WHEN l.status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
                SUM(CASE WHEN l.open_count > 0 THEN 1 ELSE 0 END) AS opened_count,
                SUM(CASE WHEN l.click_count > 0 THEN 1 ELSE 0 END) AS clicked_count", false)
            ->join('email_html_campaigns h', 'h.id = l.html_campaign_id', 'left')
            ->where('l.created_at >=', $fromUtc)
            ->where('l.created_at <=', $toUtc)
            ->groupBy('COALESCE(l.html_campaign_id, 0)', false);
        if ($campaignId !== null && $campaignId > 0) {
            $inner->where('l.html_campaign_id', $campaignId);
        }

        return $this->derived($inner);
    }

    /** Individual email sends in the range. */
    public function emailLogTable(string $fromUtc, string $toUtc, ?int $campaignId): BaseBuilder
    {
        $b = db_connect()->table('email_logs l')
            ->select('l.id, l.created_at, l.kind, l.to_email, l.subject, l.status, l.open_count, l.click_count')
            ->where('l.created_at >=', $fromUtc)
            ->where('l.created_at <=', $toUtc);
        if ($campaignId !== null && $campaignId > 0) {
            $b->where('l.html_campaign_id', $campaignId);
        }

        return $b;
    }

    /**
     * @param array{from_utc:string,to_utc:string} $range
     */
    private function templateInner(array $range): BaseBuilder
    {
        return db_connect()->table('messages m')
            ->select("t.id, t.name, t.language, t.category, t.status AS template_status,
                COUNT(DISTINCT c.id) AS campaigns,
                SUM(CASE WHEN m.status IN ('sent','delivered','read') THEN 1 ELSE 0 END) AS sent,
                SUM(CASE WHEN m.status IN ('delivered','read') THEN 1 ELSE 0 END) AS delivered,
                SUM(CASE WHEN m.status = 'read' THEN 1 ELSE 0 END) AS `read`,
                SUM(CASE WHEN m.status = 'failed' THEN 1 ELSE 0 END) AS failed", false)
            ->join('campaigns c', 'c.id = m.campaign_id')
            ->join('templates t', 't.id = c.template_id')
            ->where('m.direction', 'outbound')
            ->where('m.created_at >=', $range['from_utc'])
            ->where('m.created_at <=', $range['to_utc'])
            ->groupBy('t.id, t.name, t.language, t.category, t.status');
    }

    /**
     * One row per template, same figures as whatsappTemplateReport() but pageable in SQL.
     *
     * @param array{from_utc:string,to_utc:string} $range
     */
    public function whatsappTemplateTable(array $range): BaseBuilder
    {
        return $this->derived($this->templateInner($range));
    }

    /**
     * Totals for the template report cards, in one query.
     *
     * @param array{from_utc:string,to_utc:string} $range
     *
     * @return array{templates:int,campaigns:int,sent:int,delivered:int,read:int,failed:int}
     */
    public function whatsappTemplateTotals(array $range): array
    {
        $out = ['templates' => 0, 'campaigns' => 0, 'sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0];
        $db  = db_connect();
        if (! $db->tableExists('messages') || ! $db->tableExists('campaigns') || ! $db->tableExists('templates')) {
            return $out;
        }

        $row = $this->derived($this->templateInner($range))
            ->select('COUNT(*) AS templates, COALESCE(SUM(x.campaigns),0) AS campaigns, COALESCE(SUM(x.sent),0) AS sent, COALESCE(SUM(x.delivered),0) AS delivered, COALESCE(SUM(x.`read`),0) AS `read`, COALESCE(SUM(x.failed),0) AS failed', false)
            ->get()->getRowArray() ?: [];
        foreach ($out as $k => $_) {
            $out[$k] = (int) ($row[$k] ?? 0);
        }

        return $out;
    }

    /**
     * @return array<int,int>
     */
    public function whatsappRepliesByCampaign(string $fromUtc, string $toUtc, ?int $campaignId, ?array $ids = null): array
    {
        $sql = "
            SELECT attributed.campaign_id, COUNT(*) AS reply_count
            FROM (
                SELECT m.id,
                    (
                        SELECT cc.campaign_id
                        FROM campaign_contacts cc
                        WHERE cc.contact_id = m.contact_id
                          AND cc.sent_at IS NOT NULL
                          AND cc.sent_at <= m.created_at
                        ORDER BY cc.sent_at DESC, cc.id DESC
                        LIMIT 1
                    ) AS campaign_id
                FROM messages m
                WHERE m.direction = 'inbound'
                  AND m.created_at >= ?
                  AND m.created_at <= ?
            ) attributed
            WHERE attributed.campaign_id IS NOT NULL
        ";
        $binds = [$fromUtc, $toUtc];
        if ($campaignId !== null && $campaignId > 0) {
            $sql .= ' AND attributed.campaign_id = ?';
            $binds[] = $campaignId;
        }
        if ($ids !== null) {
            $ids = array_values(array_filter(array_map('intval', $ids)));
            if ($ids === []) {
                return [];
            }
            $sql .= ' AND attributed.campaign_id IN (' . implode(',', $ids) . ')';
        }
        $sql .= ' GROUP BY attributed.campaign_id';

        $out = [];
        foreach (db_connect()->query($sql, $binds)->getResultArray() as $row) {
            $id = (int) ($row['campaign_id'] ?? 0);
            if ($id > 0) {
                $out[$id] = (int) ($row['reply_count'] ?? 0);
            }
        }

        return $out;
    }

    /**
     * @return list<array{id:int,name:string}>
     */
    private function whatsappCampaignOptions(): array
    {
        $out = [];
        foreach (db_connect()->table('campaigns')->select('id, name')->orderBy('name', 'ASC')->get()->getResultArray() as $row) {
            $out[] = ['id' => (int) ($row['id'] ?? 0), 'name' => (string) ($row['name'] ?? '')];
        }

        return $out;
    }

    /**
     * @return list<array{id:int,name:string}>
     */
    private function emailCampaignOptions(): array
    {
        if (! db_connect()->tableExists('email_html_campaigns')) {
            return [];
        }

        $out = [];
        foreach (db_connect()->table('email_html_campaigns')->select('id, name, subject')->orderBy('name', 'ASC')->get()->getResultArray() as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                $name = trim((string) ($row['subject'] ?? ''));
            }
            $out[] = ['id' => (int) ($row['id'] ?? 0), 'name' => $name !== '' ? $name : ('Campaign #' . (int) ($row['id'] ?? 0))];
        }

        return $out;
    }

    private function recipientBuilder(string $fromUtc, string $toUtc, ?int $campaignId)
    {
        $builder = db_connect()->table('campaign_contacts')
            ->join('contacts', 'contacts.id = campaign_contacts.contact_id', 'left')
            ->join('campaigns', 'campaigns.id = campaign_contacts.campaign_id', 'left')
            ->groupStart()
                ->groupStart()
                    ->where('campaign_contacts.sent_at >=', $fromUtc)
                    ->where('campaign_contacts.sent_at <=', $toUtc)
                ->groupEnd()
                ->orGroupStart()
                    ->where('campaign_contacts.sent_at', null)
                    ->where('campaign_contacts.created_at >=', $fromUtc)
                    ->where('campaign_contacts.created_at <=', $toUtc)
                ->groupEnd()
            ->groupEnd();

        if ($campaignId !== null && $campaignId > 0) {
            $builder->where('campaign_contacts.campaign_id', $campaignId);
        }

        return $builder;
    }

    /**
     * @return array{sent:int,failed:int,queued:int,total:int,opened:int,clicked:int}
     */
    private function emailAggregate(string $fromUtc, string $toUtc, ?int $campaignId, bool $onlyWithoutCampaign): array
    {
        $empty = ['sent' => 0, 'failed' => 0, 'queued' => 0, 'total' => 0, 'opened' => 0, 'clicked' => 0];
        if (! db_connect()->tableExists('email_logs')) {
            return $empty;
        }

        $builder = db_connect()->table('email_logs')
            ->select("
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) AS queued,
                SUM(CASE WHEN open_count > 0 THEN 1 ELSE 0 END) AS opened,
                SUM(CASE WHEN click_count > 0 THEN 1 ELSE 0 END) AS clicked,
                COUNT(*) AS total
            ", false)
            ->where('created_at >=', $fromUtc)
            ->where('created_at <=', $toUtc);

        if ($onlyWithoutCampaign) {
            $builder->groupStart()
                ->where('html_campaign_id', null)
                ->orWhere('html_campaign_id', 0)
            ->groupEnd();
        } else {
            $this->applyEmailCampaignFilter($builder, $campaignId);
        }

        $row = $builder->get()->getRowArray() ?: [];

        return [
            'sent'    => (int) ($row['sent'] ?? 0),
            'failed'  => (int) ($row['failed'] ?? 0),
            'queued'  => (int) ($row['queued'] ?? 0),
            'total'   => (int) ($row['total'] ?? 0),
            'opened'  => (int) ($row['opened'] ?? 0),
            'clicked' => (int) ($row['clicked'] ?? 0),
        ];
    }

    private function applyEmailCampaignFilter($builder, ?int $campaignId): void
    {
        if ($campaignId !== null && $campaignId > 0) {
            $builder->where('html_campaign_id', $campaignId);
        }
    }

    /**
     * @return array{count:int,rows:list<array<string,mixed>>}
     */
    private function emailUnsubscribes(string $fromUtc, string $toUtc, ?int $campaignId, int $limit): array
    {
        $db = db_connect();
        if (! $db->tableExists('email_unsubscribes')) {
            return ['count' => 0, 'rows' => []];
        }

        $countBuilder = $db->table('email_unsubscribes')->where('is_deleted', 0)
            ->where('created_at >=', $fromUtc)
            ->where('created_at <=', $toUtc);
        $listBuilder = $db->table('email_unsubscribes')->where('is_deleted', 0)
            ->where('created_at >=', $fromUtc)
            ->where('created_at <=', $toUtc);
        if ($campaignId !== null && $campaignId > 0 && $db->fieldExists('campaign_id', 'email_unsubscribes')) {
            $countBuilder->where('campaign_id', $campaignId);
            $listBuilder->where('campaign_id', $campaignId);
        }

        $count = (int) $countBuilder->countAllResults();
        $rows  = $listBuilder->orderBy('id', 'DESC')->limit($limit)->get()->getResultArray();

        return ['count' => $count, 'rows' => $rows];
    }

    /**
     * @param array<int,array<string,int>> $byDay
     * @param list<string> $fields
     * @return list<array<string,int|string>>
     */
    private function fillDays(string $fromYmd, string $toYmd, array $byDay, array $fields): array
    {
        try {
            $start = new DateTimeImmutable($fromYmd, AppDateTime::appZone());
            $end   = new DateTimeImmutable($toYmd, AppDateTime::appZone());
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');
            $row = ['date' => $key, 'label' => $day->format('M j')];
            foreach ($fields as $field) {
                $row[$field] = (int) ($byDay[$key][$field] ?? 0);
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array<int,string>
     */
    private function namesById(string $table, array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === [] || ! db_connect()->tableExists($table)) {
            return [];
        }

        $out = [];
        foreach (db_connect()->table($table)->select('id, name')->whereIn('id', $ids)->get()->getResultArray() as $row) {
            $out[(int) $row['id']] = (string) ($row['name'] ?? '');
        }

        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array<int,string>
     */
    private function emailNamesById(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === [] || ! db_connect()->tableExists('email_html_campaigns')) {
            return [];
        }

        $out = [];
        foreach (db_connect()->table('email_html_campaigns')->select('id, name, subject')->whereIn('id', $ids)->get()->getResultArray() as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                $name = trim((string) ($row['subject'] ?? ''));
            }
            $out[(int) $row['id']] = $name !== '' ? $name : ('Campaign #' . (int) $row['id']);
        }

        return $out;
    }

    private function sqlOffset(string $ymd): string
    {
        try {
            $local = new DateTimeImmutable($ymd . ' 12:00:00', AppDateTime::appZone());
        } catch (\Throwable) {
            $local = new DateTimeImmutable('now', AppDateTime::appZone());
        }

        return $local->format('P');
    }

    private function rate(int $part, int $whole): float
    {
        return $whole > 0 ? round(($part / $whole) * 100, 1) : 0.0;
    }
}
