<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\SelfHealingSchema;
use CodeIgniter\Model;

/**
 * Per-recipient email events: sent, failed, open, click, delivery, bounce, complaint.
 * One row per (log_id, email, event_type); repeats bump event_count.
 */
class EmailRecipientEventModel extends Model
{
    use SelfHealingSchema;

    public const TYPE_SENT      = 'sent';
    public const TYPE_FAILED    = 'failed';
    public const TYPE_OPEN      = 'open';
    public const TYPE_CLICK     = 'click';
    public const TYPE_DELIVERY  = 'delivery';
    public const TYPE_BOUNCE    = 'bounce';
    public const TYPE_COMPLAINT = 'complaint';

    protected $table            = 'email_recipient_events';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $protectFields    = true;
    protected $allowedFields    = [
        'log_id',
        'campaign_id',
        'email',
        'event_type',
        'detail',
        'message_id',
        'event_count',
        'first_at',
        'last_at',
    ];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    public function record(string $email, string $type, int $logId = 0, ?int $campaignId = null, ?string $detail = null, ?string $messageId = null): bool
    {
        $messageId = $messageId !== null && trim($messageId) !== '' ? mb_substr(trim($messageId), 0, 100) : null;
        $email = strtolower(trim($email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $now    = date('Y-m-d H:i:s');
        $logId  = max(0, $logId);
        $detail = $detail !== null ? mb_substr($detail, 0, 255) : null;

        $existing = $this->where('log_id', $logId)
            ->where('email', $email)
            ->where('event_type', $type)
            ->first();

        if ($existing) {
            return (bool) $this->db->table($this->table)
                ->where('id', (int) $existing['id'])
                ->set('event_count', 'event_count + 1', false)
                ->set('last_at', $now)
                ->set('updated_at', $now)
                ->set('detail', $detail ?? ($existing['detail'] ?? null))
                ->set('message_id', $messageId ?? ($existing['message_id'] ?? null))
                ->update();
        }

        return (bool) $this->insert([
            'log_id'      => $logId,
            'campaign_id' => $campaignId,
            'email'       => $email,
            'event_type'  => $type,
            'detail'      => $detail,
            'message_id'  => $messageId,
            'event_count' => 1,
            'first_at'    => $now,
            'last_at'     => $now,
        ]);
    }

    /**
     * Unique recipients per event type for one send log.
     *
     * @return array<string, int>
     */
    public function uniqueCountsForLog(int $logId): array
    {
        $rows = $this->select('event_type, COUNT(DISTINCT email) AS total')
            ->where('log_id', $logId)
            ->groupBy('event_type')
            ->findAll();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['event_type']] = (int) $row['total'];
        }

        return $out;
    }

    /**
     * Store the per-recipient outcome of a send (sent + provider message id, or failed + reason).
     *
     * Understands provider result data in three shapes:
     *  - ['recipients' => [['email', 'ok', 'message_id', 'error'], ...]]  (detailed, e.g. Amazon SES bulk)
     *  - ['MessageId' => '...'] for a single recipient
     *  - ['failed' => [['email', 'message'], ...]]  → everyone else counts as sent
     *
     * @param list<string>        $recipients
     * @param array<string, mixed> $resultData
     */
    public function recordSendResults(int $logId, ?int $campaignId, array $recipients, bool $ok, array $resultData, string $message = ''): void
    {
        if ($logId <= 0 || $recipients === []) {
            return;
        }

        if (is_array($resultData['recipients'] ?? null)) {
            foreach ($resultData['recipients'] as $r) {
                $sent = ! empty($r['ok']);
                $this->record(
                    (string) ($r['email'] ?? ''),
                    $sent ? self::TYPE_SENT : self::TYPE_FAILED,
                    $logId,
                    $campaignId,
                    $sent ? null : (string) ($r['error'] ?? 'Send failed'),
                    $sent ? (string) ($r['message_id'] ?? '') : null
                );
            }

            return;
        }

        if (count($recipients) === 1) {
            $this->record(
                $recipients[0],
                $ok ? self::TYPE_SENT : self::TYPE_FAILED,
                $logId,
                $campaignId,
                $ok ? null : ($message !== '' ? $message : 'Send failed'),
                $ok ? (string) ($resultData['MessageId'] ?? '') : null
            );

            return;
        }

        $failed = [];
        foreach ((array) ($resultData['failed'] ?? []) as $f) {
            if (is_array($f) && ! empty($f['email'])) {
                $failed[strtolower(trim((string) $f['email']))] = (string) ($f['message'] ?? 'Send failed');
            }
        }

        foreach ($recipients as $email) {
            $key = strtolower(trim($email));
            if (isset($failed[$key])) {
                $this->record($email, self::TYPE_FAILED, $logId, $campaignId, $failed[$key]);
            } elseif ($ok || $failed !== []) {
                $this->record($email, self::TYPE_SENT, $logId, $campaignId);
            } else {
                $this->record($email, self::TYPE_FAILED, $logId, $campaignId, $message !== '' ? $message : 'Send failed');
            }
        }
    }

    /**
     * The "sent" row for a provider message id (used to map bounces/complaints back to a send).
     *
     * @return array<string, mixed>|null
     */
    public function findSentByMessageId(string $messageId): ?array
    {
        $messageId = trim($messageId);
        if ($messageId === '') {
            return null;
        }

        $row = $this->where('message_id', $messageId)->where('event_type', self::TYPE_SENT)->first();

        return is_array($row) ? $row : null;
    }

    /**
     * One row per recipient of a send with the latest status of every event type.
     *
     * @param list<string> $fallbackEmails used for sends recorded before per-recipient tracking existed
     * @param array{send?: string, error?: ?string} $fallbackResult whole-send outcome applied to fallback rows
     *
     * @return list<array{email: string, send: string, error: ?string, message_id: ?string, delivered_at: ?string,
     *                    bounce: ?string, bounced_at: ?string, complaint_at: ?string, opens: int, opened_at: ?string,
     *                    clicks: int, clicked_at: ?string, status: string}>
     */
    public function recipientsForLog(int $logId, array $fallbackEmails = [], array $fallbackResult = []): array
    {
        $rows = $logId > 0 ? $this->where('log_id', $logId)->orderBy('email', 'ASC')->findAll() : [];

        $out = [];
        foreach ($fallbackEmails as $email) {
            $email = strtolower(trim($email));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $r = $this->blankRecipient($email);
                if (in_array($fallbackResult['send'] ?? '', ['sent', 'failed'], true)) {
                    $r['send']  = $fallbackResult['send'];
                    $r['error'] = $r['send'] === 'failed' ? ($fallbackResult['error'] ?? null) : null;
                }
                $out[$email] = $r;
            }
        }

        foreach ($rows as $row) {
            $email = (string) $row['email'];
            $r     = $out[$email] ?? $this->blankRecipient($email);
            $at    = (string) ($row['last_at'] ?? $row['first_at'] ?? '');

            switch ((string) $row['event_type']) {
                case self::TYPE_SENT:
                    $r['send']       = 'sent';
                    $r['message_id'] = $row['message_id'] ?? null;
                    break;
                case self::TYPE_FAILED:
                    $r['send']  = 'failed';
                    $r['error'] = $row['detail'] ?? null;
                    break;
                case self::TYPE_DELIVERY:
                    $r['delivered_at'] = $at;
                    break;
                case self::TYPE_BOUNCE:
                    $r['bounce']     = $row['detail'] ?? 'Bounced';
                    $r['bounced_at'] = $at;
                    break;
                case self::TYPE_COMPLAINT:
                    $r['complaint_at'] = $at;
                    break;
                case self::TYPE_OPEN:
                    $r['opens']     = (int) $row['event_count'];
                    $r['opened_at'] = (string) ($row['first_at'] ?? $at);
                    break;
                case self::TYPE_CLICK:
                    $r['clicks']     = (int) $row['event_count'];
                    $r['clicked_at'] = (string) ($row['first_at'] ?? $at);
                    break;
            }
            $out[$email] = $r;
        }

        foreach ($out as &$r) {
            $r['status'] = match (true) {
                $r['send'] === 'failed'      => 'failed',
                $r['complaint_at'] !== null  => 'complaint',
                $r['bounced_at'] !== null    => 'bounced',
                $r['clicks'] > 0             => 'clicked',
                $r['opens'] > 0              => 'opened',
                $r['delivered_at'] !== null  => 'delivered',
                $r['send'] === 'sent'        => 'sent',
                default                      => 'unknown',
            };
        }
        unset($r);

        return array_values($out);
    }

    /**
     * @return array<string, mixed>
     */
    protected function blankRecipient(string $email): array
    {
        return [
            'email'        => $email,
            'send'         => 'unknown',
            'error'        => null,
            'message_id'   => null,
            'delivered_at' => null,
            'bounce'       => null,
            'bounced_at'   => null,
            'complaint_at' => null,
            'opens'        => 0,
            'opened_at'    => null,
            'clicks'       => 0,
            'clicked_at'   => null,
            'status'       => 'unknown',
        ];
    }
}
