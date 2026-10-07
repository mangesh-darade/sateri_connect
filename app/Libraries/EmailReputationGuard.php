<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\EmailHtmlCampaignModel;
use App\Models\EmailRecipientEventModel;
use RuntimeException;

/**
 * Protects the tenant's sending reputation (Amazon SES / Gmail / Yahoo limits).
 *
 * Over the last WINDOW_DAYS: hard-bounce rate above 5% or complaint rate above 0.1%
 * blocks new bulk / campaign sends and pauses running campaigns.
 */
class EmailReputationGuard
{
    public const MAX_BOUNCE_RATE    = 0.05;
    public const MAX_COMPLAINT_RATE = 0.001;
    public const WINDOW_DAYS        = 7;
    /** Rates on tiny volumes are noise (1 bounce in 10 sends = 10%). */
    public const MIN_SENDS = 100;

    /** @var array<string, array<string, mixed>> */
    protected static array $cache = [];

    /**
     * @return array{healthy: bool, sent: int, bounces: int, complaints: int, bounce_rate: float,
     *               complaint_rate: float, window_days: int, message: string}
     */
    public function status(bool $fresh = false): array
    {
        $key = (string) db_connect()->getDatabase();
        if (! $fresh && isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $sent = $bounces = $complaints = 0;
        try {
            $since = date('Y-m-d H:i:s', strtotime('-' . self::WINDOW_DAYS . ' days'));
            $rows  = db_connect()->table('email_recipient_events')
                ->select('event_type, COUNT(*) AS total')
                ->where('first_at >=', $since)
                ->groupStart()
                    ->whereIn('event_type', [EmailRecipientEventModel::TYPE_SENT, EmailRecipientEventModel::TYPE_COMPLAINT])
                    ->orGroupStart()
                        ->where('event_type', EmailRecipientEventModel::TYPE_BOUNCE)
                        // Soft (transient) bounces such as a full mailbox do not count against reputation.
                        ->groupStart()
                            ->where('detail IS NULL', null, false)
                            ->orNotLike('detail', 'Transient', 'after')
                        ->groupEnd()
                    ->groupEnd()
                ->groupEnd()
                ->groupBy('event_type')
                ->get()
                ->getResultArray();
            foreach ($rows as $row) {
                match ((string) $row['event_type']) {
                    EmailRecipientEventModel::TYPE_SENT      => $sent = (int) $row['total'],
                    EmailRecipientEventModel::TYPE_BOUNCE    => $bounces = (int) $row['total'],
                    EmailRecipientEventModel::TYPE_COMPLAINT => $complaints = (int) $row['total'],
                    default                                  => null,
                };
            }
        } catch (\Throwable $e) {
            log_message('error', 'Email reputation check failed: {msg}', ['msg' => $e->getMessage()]);
        }

        return self::$cache[$key] = $this->evaluate($sent, $bounces, $complaints);
    }

    /**
     * @return array{healthy: bool, sent: int, bounces: int, complaints: int, bounce_rate: float,
     *               complaint_rate: float, window_days: int, message: string}
     */
    public function evaluate(int $sent, int $bounces, int $complaints): array
    {
        $bounceRate    = $sent > 0 ? $bounces / $sent : 0.0;
        $complaintRate = $sent > 0 ? $complaints / $sent : 0.0;
        $enough        = $sent >= self::MIN_SENDS;

        $problems = [];
        if ($enough && $bounceRate > self::MAX_BOUNCE_RATE) {
            $problems[] = sprintf('bounce rate is %.1f%% (limit 5%%)', $bounceRate * 100);
        }
        if ($enough && $complaintRate > self::MAX_COMPLAINT_RATE) {
            $problems[] = sprintf('spam complaint rate is %.2f%% (limit 0.1%%)', $complaintRate * 100);
        }

        $message = $problems === [] ? '' : sprintf(
            'Bulk and campaign emails are paused: your %s over the last %d days. Remove bounced / inactive addresses '
            . '(run them through the List Verifier) and send only to people who opted in. Sending resumes automatically once the rates are back under the limits.',
            implode(' and ', $problems),
            self::WINDOW_DAYS
        );

        return [
            'healthy'        => $problems === [],
            'sent'           => $sent,
            'bounces'        => $bounces,
            'complaints'     => $complaints,
            'bounce_rate'    => round($bounceRate * 100, 2),
            'complaint_rate' => round($complaintRate * 100, 3),
            'window_days'    => self::WINDOW_DAYS,
            'message'        => $message,
        ];
    }

    /**
     * Throws (and pauses running campaigns) when the reputation limits are exceeded.
     *
     * @throws RuntimeException
     */
    public function assertHealthy(): void
    {
        $status = $this->status();
        if ($status['healthy']) {
            return;
        }

        $this->pauseRunningCampaigns($status);

        throw new RuntimeException($status['message']);
    }

    /**
     * @param array<string, mixed> $status
     */
    public function pauseRunningCampaigns(array $status): int
    {
        try {
            $model = model(EmailHtmlCampaignModel::class);
            $ids   = $model->select('id')->whereIn('status', ['queued', 'sending'])->findColumn('id') ?: [];
            if ($ids === []) {
                return 0;
            }

            $model->whereIn('id', $ids)->set([
                'status'     => 'paused',
                'last_error' => mb_substr((string) $status['message'], 0, 1000),
            ])->update();

            log_activity('email_campaigns_auto_paused', 'emails', 'Paused ' . count($ids) . ' email campaign(s): sending reputation limits exceeded', [
                'campaign_ids'   => array_map('intval', $ids),
                'bounce_rate'    => $status['bounce_rate'] ?? null,
                'complaint_rate' => $status['complaint_rate'] ?? null,
            ]);

            return count($ids);
        } catch (\Throwable $e) {
            log_message('error', 'Auto-pause email campaigns failed: {msg}', ['msg' => $e->getMessage()]);

            return 0;
        }
    }
}
