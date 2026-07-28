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
        $start = strtotime($from);
        $end   = strtotime($to);
        if ($start === false || $end === false) {
            return [];
        }

        $rows = $this->db->table($this->table)
            ->select("DATE(created_at) AS d,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed
            ", false)
            ->where('created_at >=', $from . ' 00:00:00')
            ->where('created_at <=', $to . ' 23:59:59')
            ->groupBy('d')
            ->orderBy('d', 'ASC')
            ->get()
            ->getResultArray();

        $byDay = [];
        foreach ($rows as $row) {
            $byDay[(string) $row['d']] = [
                'sent'   => (int) $row['sent'],
                'failed' => (int) $row['failed'],
            ];
        }

        $out = [];
        for ($ts = $start; $ts <= $end; $ts += 86400) {
            $day = date('Y-m-d', $ts);
            $out[] = [
                'date'   => $day,
                'sent'   => $byDay[$day]['sent'] ?? 0,
                'failed' => $byDay[$day]['failed'] ?? 0,
            ];
        }

        return $out;
    }
}
