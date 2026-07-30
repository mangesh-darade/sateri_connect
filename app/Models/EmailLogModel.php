<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class EmailLogModel extends Model
{
    protected $table            = 'email_logs';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $protectFields    = true;
    protected $allowedFields    = [
        'kind',
        'provider',
        'to_email',
        'subject',
        'status',
        'builder_id',
        'html_campaign_id',
        'drip_id',
        'message',
        'meta_json',
        'created_by',
    ];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * @param array<string, mixed> $meta
     */
    public function record(
        string $kind,
        string $status,
        string $subject,
        ?string $toEmail = null,
        ?string $provider = null,
        ?string $message = null,
        array $meta = [],
        ?int $userId = null,
        ?int $builderId = null,
        ?int $campaignId = null,
        ?int $dripId = null
    ): int {
        return (int) $this->insert([
            'kind'             => $kind,
            'provider'         => $provider,
            'to_email'         => $toEmail,
            'subject'          => $subject,
            'status'           => $status,
            'builder_id'       => $builderId,
            'html_campaign_id' => $campaignId,
            'drip_id'          => $dripId,
            'message'          => $message,
            'meta_json'        => $meta !== [] ? json_encode($meta) : null,
            'created_by'       => $userId,
        ], true);
    }

    /**
     * @return array{sent:int,failed:int,queued:int,total:int}
     */
    public function summary(string $from, string $to): array
    {
        $row = $this->db->table($this->table)
            ->select("
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) AS queued,
                COUNT(*) AS total
            ", false)
            ->where('created_at >=', $from)
            ->where('created_at <=', $to)
            ->get()
            ->getRowArray();

        return [
            'sent'   => (int) ($row['sent'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
            'queued' => (int) ($row['queued'] ?? 0),
            'total'  => (int) ($row['total'] ?? 0),
        ];
    }

    /**
     * @return list<array{date:string,sent:int,failed:int}>
     */
    public function daily(string $from, string $to): array
    {
        try {
            $start = new \DateTimeImmutable($from, \App\Libraries\AppDateTime::appZone());
            $end   = new \DateTimeImmutable($to, \App\Libraries\AppDateTime::appZone());
        } catch (\Throwable $e) {
            return [];
        }

        if ($end < $start) {
            return [];
        }

        [$rangeStart, $rangeEnd] = \App\Libraries\AppDateTime::rangeBoundsUtc($from, $to);

        $rows = $this->db->table($this->table)
            ->select('created_at, status')
            ->where('created_at >=', $rangeStart)
            ->where('created_at <=', $rangeEnd)
            ->get()
            ->getResultArray();

        $byDay = [];
        foreach ($rows as $row) {
            $day = \App\Libraries\AppDateTime::format($row['created_at'] ?? null, 'Y-m-d', '');
            if ($day === '') {
                continue;
            }
            if (! isset($byDay[$day])) {
                $byDay[$day] = ['sent' => 0, 'failed' => 0];
            }
            $status = (string) ($row['status'] ?? '');
            if ($status === 'sent') {
                $byDay[$day]['sent']++;
            } elseif ($status === 'failed') {
                $byDay[$day]['failed']++;
            }
        }

        $out = [];
        for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
            $day = $d->format('Y-m-d');
            $out[] = [
                'date'   => $day,
                'sent'   => $byDay[$day]['sent'] ?? 0,
                'failed' => $byDay[$day]['failed'] ?? 0,
            ];
        }

        return $out;
    }
}
