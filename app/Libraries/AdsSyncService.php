<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\AdCampaignModel;
use App\Models\AdMetricDailyModel;
use App\Models\NotificationModel;
use Throwable;

/**
 * Pulls the review status and the daily numbers of live campaigns from Meta (cron `ads:sync`, every
 * 30 minutes). Test-mode campaigns are skipped: no made-up numbers are ever stored. Meta amounts are
 * read as whole currency units with two decimals (true for INR).
 */
class AdsSyncService
{
    private const MAX_CAMPAIGNS = 50;
    private const NEAR_CAP      = 0.9;
    private const REJECTED_TEXT = 'Meta did not approve this ad. Open Ads Manager to see the reason, change the ad and create the campaign again.';

    public function __construct(private ?AdsService $ads = null)
    {
        $this->ads ??= new AdsService();
    }

    /**
     * @return array{campaigns: int, metrics: int, alerts: int, skipped: string}
     */
    public function run(): array
    {
        $out = ['campaigns' => 0, 'metrics' => 0, 'alerts' => 0, 'skipped' => ''];

        $conn = $this->ads->connection();
        if (! $this->ads->isLive() || $conn === null) {
            $out['skipped'] = 'not live';

            return $out;
        }

        $campaigns = (new AdCampaignModel())
            ->whereIn('status', ['active', 'paused'])
            ->where('is_mock', 0)
            ->where('meta_campaign_id IS NOT NULL', null, false)
            ->orderBy('id', 'ASC')
            ->findAll(self::MAX_CAMPAIGNS);

        foreach ($campaigns as $c) {
            try {
                $out['campaigns']++;
                $out['alerts'] += $this->syncStatus($c, $conn['token']);
                $out['metrics'] += $this->syncMetrics($c, $conn['token']);
                $out['alerts']  += $this->capAlert($c);
            } catch (Throwable $e) {
                log_message('error', 'AdsSyncService campaign ' . (int) $c['id'] . ' failed: ' . $e::class);
            }
        }

        return $out;
    }

    /**
     * One day of Meta insights as a metrics row, or null when it has no date.
     *
     * @param array<string, mixed> $row
     *
     * @return array{metric_date: string, spend_minor: int, impressions: int, reach: int, clicks: int, conversations: int, cost_per_conversation_minor: int}|null
     */
    public static function parseInsightsRow(array $row): ?array
    {
        $date = (string) ($row['date_start'] ?? '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        $spend = (int) round((float) ($row['spend'] ?? 0) * 100);
        $convs = 0;
        foreach ((array) ($row['actions'] ?? []) as $a) {
            if (is_array($a) && str_contains((string) ($a['action_type'] ?? ''), 'messaging_conversation_started')) {
                $convs += (int) ($a['value'] ?? 0);
            }
        }

        return [
            'metric_date'                 => $date,
            'spend_minor'                 => max(0, $spend),
            'impressions'                 => max(0, (int) ($row['impressions'] ?? 0)),
            'reach'                       => max(0, (int) ($row['reach'] ?? 0)),
            'clicks'                      => max(0, (int) ($row['clicks'] ?? 0)),
            'conversations'               => $convs,
            'cost_per_conversation_minor' => $convs > 0 ? (int) round($spend / $convs) : 0,
        ];
    }

    /**
     * Local status for a Meta `effective_status`, or null to leave the campaign as it is.
     */
    public static function mapEffectiveStatus(string $meta): ?string
    {
        return match (strtoupper($meta)) {
            'ACTIVE'                                       => 'active',
            'PAUSED', 'CAMPAIGN_PAUSED', 'ADSET_PAUSED'    => 'paused',
            'DISAPPROVED', 'WITH_ISSUES'                   => 'rejected',
            'ARCHIVED', 'DELETED'                          => 'ended',
            default                                        => null,
        };
    }

    /**
     * @param array<string, mixed> $c
     *
     * @return int alerts sent
     */
    private function syncStatus(array $c, string $token): int
    {
        $campaigns = new AdCampaignModel();
        $id        = (int) $c['id'];

        // The ad end date has passed: it is over whatever Meta says.
        if ($c['status'] === 'active' && ! empty($c['ends_at']) && strtotime((string) $c['ends_at']) < time()) {
            $campaigns->update($id, ['status' => 'ended']);

            return 0;
        }

        $res = $this->ads->read((string) ($c['meta_ad_id'] ?: $c['meta_campaign_id']), $token, ['fields' => 'effective_status']);
        if (! $res['ok']) {
            return $this->readFailedAlert($c, (string) $res['message']);
        }
        $new = self::mapEffectiveStatus((string) ($res['data']['effective_status'] ?? ''));
        if ($new === null || $new === $c['status']) {
            return 0;
        }

        $campaigns->update($id, ['status' => $new, 'last_error' => $new === 'rejected' ? self::REJECTED_TEXT : null]);
        log_activity('sync', 'ads', 'Ad campaign status changed to ' . $new, ['campaign_id' => $id]);

        if ($new === 'rejected') {
            $this->notify($c, 'Ad rejected', '"' . $c['name'] . '": ' . self::REJECTED_TEXT, 'danger');

            return 1;
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $c
     *
     * @return int rows written
     */
    private function syncMetrics(array $c, string $token): int
    {
        $res = $this->ads->read((string) $c['meta_campaign_id'] . '/insights', $token, [
            'fields'         => 'spend,impressions,reach,clicks,actions,date_start',
            'time_increment' => 1,
            'date_preset'    => 'last_7d',
            'limit'          => 10,
        ]);
        if (! $res['ok']) {
            return 0;
        }

        $model = new AdMetricDailyModel();
        $n     = 0;
        foreach ((array) ($res['data']['data'] ?? []) as $row) {
            $m = is_array($row) ? self::parseInsightsRow($row) : null;
            if ($m === null) {
                continue;
            }
            $m['campaign_id'] = (int) $c['id'];
            $existing         = $model->where('campaign_id', $m['campaign_id'])->where('metric_date', $m['metric_date'])->first();
            if ($existing) {
                $model->update((int) $existing['id'], $m);
            } else {
                $model->insert($m);
            }
            $n++;
        }

        return $n;
    }

    /**
     * Once a day per campaign: today's spend is close to (or above) the client's daily limit.
     *
     * @param array<string, mixed> $c
     */
    private function capAlert(array $c): int
    {
        $cap = (new AdsBudgetGuard())->capMinor();
        if ($cap <= 0) {
            return 0;
        }
        $today = (new AdMetricDailyModel())->where('campaign_id', (int) $c['id'])->where('metric_date', date('Y-m-d'))->first();
        if (! $today || (int) $today['spend_minor'] < (int) round($cap * self::NEAR_CAP)) {
            return 0;
        }

        $cache  = service('cache');
        $marker = 'ads_cap_alert_' . md5((string) db_connect()->getDatabase()) . '_' . (int) $c['id'] . '_' . date('Ymd');
        if ($cache->get($marker) !== null) {
            return 0;
        }
        $cache->save($marker, 1, 86400);

        $this->notify($c, 'Ad spend near your limit', '"' . $c['name'] . '" has spent ' . number_format((int) $today['spend_minor'] / 100, 2) . ' today. Your daily limit is ' . number_format($cap / 100, 2) . '.', 'warning');

        return 1;
    }

    /**
     * Meta could not be read (expired token, no access): the spend alerts depend on this sync, so say so once a day.
     *
     * @param array<string, mixed> $c
     *
     * @return int alerts sent
     */
    private function readFailedAlert(array $c, string $reason): int
    {
        $cache  = service('cache');
        $marker = 'ads_sync_failed_' . md5((string) db_connect()->getDatabase()) . '_' . date('Ymd');
        if ($cache->get($marker) !== null) {
            return 0;
        }
        $cache->save($marker, 1, 86400);

        $this->notify($c, 'Ads could not be checked', 'Your ads could not be read from Meta (' . mb_substr($reason, 0, 120) . '). Spend and status alerts are paused until you reconnect the ad account in Ads settings.', 'danger');

        return 1;
    }

    /**
     * @param array<string, mixed> $c
     */
    private function notify(array $c, string $title, string $message, string $type): void
    {
        model(NotificationModel::class)->notifyChatUsers(
            $title,
            $message,
            site_url('ads'),
            ! empty($c['created_by']) ? (int) $c['created_by'] : null,
            $type
        );
    }
}
