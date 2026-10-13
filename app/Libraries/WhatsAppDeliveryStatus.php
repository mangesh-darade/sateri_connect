<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * WhatsApp delivery status moves forward only: sent → delivered → read.
 * Failed replaces a send that never reached the phone. It does not replace
 * delivered or read. Meta reports these states on the message status webhook.
 */
class WhatsAppDeliveryStatus
{
    /** @var array<string, int> */
    private const RANK = [
        'pending'   => 10,
        'queued'    => 20,
        'sent'      => 30,
        'delivered' => 40,
        'read'      => 50,
    ];

    public static function shouldAdvance(string $current, string $next): bool
    {
        $current = strtolower(trim($current));
        $next    = strtolower(trim($next));
        if ($next === '' || $next === $current) {
            return false;
        }
        if ($current === 'read' || $current === 'failed') {
            return false;
        }
        if ($next === 'failed') {
            return in_array($current, ['', 'pending', 'queued', 'sent'], true);
        }
        if (! isset(self::RANK[$next])) {
            return false;
        }

        return self::RANK[$next] > (self::RANK[$current] ?? 0);
    }

    /**
     * Latest status a customer should see, using the stored recipient row,
     * the outbound message row, and delivery timestamps.
     */
    public static function resolve(
        string $contactStatus,
        ?string $messageStatus = null,
        ?string $deliveredAt = null,
        ?string $readAt = null
    ): string {
        $best = strtolower(trim($contactStatus));
        if ($best === '') {
            $best = 'pending';
        }

        $messageStatus = strtolower(trim((string) $messageStatus));
        if ($messageStatus !== '' && self::shouldAdvance($best, $messageStatus)) {
            $best = $messageStatus;
        }
        if (trim((string) $readAt) !== '' && self::shouldAdvance($best, 'read')) {
            $best = 'read';
        } elseif (trim((string) $deliveredAt) !== '' && self::shouldAdvance($best, 'delivered')) {
            $best = 'delivered';
        }

        return $best;
    }

    /**
     * Move campaign recipients forward when the message row or a timestamp
     * is already ahead of the recipient row. Returns how many rows changed.
     */
    public function syncCampaign(int $campaignId): int
    {
        if ($campaignId <= 0) {
            return 0;
        }

        $rows = db_connect()->query(
            "SELECT cc.id, cc.status, cc.delivered_at, cc.read_at,
                    (
                        SELECT m.status
                        FROM messages m
                        WHERE m.direction = 'outbound'
                          AND (
                                (cc.wa_message_id IS NOT NULL AND cc.wa_message_id <> ''
                                    AND (m.wa_message_id = cc.wa_message_id OR m.wamid = cc.wa_message_id))
                             OR (m.campaign_id = cc.campaign_id AND m.contact_id = cc.contact_id)
                          )
                        ORDER BY m.id DESC
                        LIMIT 1
                    ) AS message_status
             FROM campaign_contacts cc
             WHERE cc.campaign_id = ?",
            [$campaignId]
        )->getResultArray();

        $updated = 0;
        $model   = model(\App\Models\CampaignContactModel::class);
        $now     = date('Y-m-d H:i:s');

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $next = self::resolve(
                (string) ($row['status'] ?? ''),
                isset($row['message_status']) ? (string) $row['message_status'] : null,
                isset($row['delivered_at']) ? (string) $row['delivered_at'] : null,
                isset($row['read_at']) ? (string) $row['read_at'] : null
            );
            if (! self::shouldAdvance((string) ($row['status'] ?? ''), $next)) {
                continue;
            }

            $data = ['status' => $next];
            if ($next === 'delivered' && trim((string) ($row['delivered_at'] ?? '')) === '') {
                $data['delivered_at'] = $now;
            }
            if ($next === 'read') {
                if (trim((string) ($row['read_at'] ?? '')) === '') {
                    $data['read_at'] = $now;
                }
                if (trim((string) ($row['delivered_at'] ?? '')) === '') {
                    $data['delivered_at'] = $now;
                }
            }

            if ($model->update((int) $row['id'], $data)) {
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Latest campaign recipients with the status Meta last reported for that customer.
     *
     * @return list<array{name:string,mobile:string,status:string,campaign_id:int,campaign_name:string}>
     */
    public function recentRecipients(int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));
        $rows  = db_connect()->query(
            "SELECT cc.status, cc.delivered_at, cc.read_at, cc.campaign_id,
                    contacts.name AS contact_name, contacts.mobile,
                    campaigns.name AS campaign_name,
                    (
                        SELECT m.status
                        FROM messages m
                        WHERE m.direction = 'outbound'
                          AND (
                                (cc.wa_message_id IS NOT NULL AND cc.wa_message_id <> ''
                                    AND (m.wa_message_id = cc.wa_message_id OR m.wamid = cc.wa_message_id))
                             OR (m.campaign_id = cc.campaign_id AND m.contact_id = cc.contact_id)
                          )
                        ORDER BY m.id DESC
                        LIMIT 1
                    ) AS message_status
             FROM campaign_contacts cc
             LEFT JOIN contacts ON contacts.id = cc.contact_id
             LEFT JOIN campaigns ON campaigns.id = cc.campaign_id
             ORDER BY cc.id DESC
             LIMIT {$limit}"
        )->getResultArray();

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['contact_name'] ?? ''));
            $out[] = [
                'name'          => $name !== '' ? $name : 'Unknown recipient',
                'mobile'        => (string) ($row['mobile'] ?? ''),
                'status'        => self::resolve(
                    (string) ($row['status'] ?? ''),
                    isset($row['message_status']) ? (string) $row['message_status'] : null,
                    isset($row['delivered_at']) ? (string) $row['delivered_at'] : null,
                    isset($row['read_at']) ? (string) $row['read_at'] : null
                ),
                'campaign_id'   => (int) ($row['campaign_id'] ?? 0),
                'campaign_name' => (string) ($row['campaign_name'] ?? ''),
            ];
        }

        return $out;
    }
}
