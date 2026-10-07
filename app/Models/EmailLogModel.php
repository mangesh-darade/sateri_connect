<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\SelfHealingSchema;
use CodeIgniter\Model;

class EmailLogModel extends Model
{
    use SelfHealingSchema;

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
        'opened_at',
        'open_count',
        'clicked_at',
        'click_count',
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

    public function recordOpen(int $logId): bool
    {
        return $this->recordEngagement($logId, 'open_count', 'opened_at');
    }

    public function recordClick(int $logId): bool
    {
        return $this->recordEngagement($logId, 'click_count', 'clicked_at');
    }

    protected function recordEngagement(int $logId, string $countCol, string $atCol): bool
    {
        if ($logId <= 0) {
            return false;
        }

        $now = date('Y-m-d H:i:s');

        return (bool) $this->db->table($this->table)
            ->where('id', $logId)
            ->set($countCol, "{$countCol} + 1", false)
            ->set($atCol, "COALESCE({$atCol}, '{$now}')", false)
            ->set('updated_at', $now)
            ->update();
    }

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
     * @return array{sent:int,failed:int,queued:int,total:int,opened:int,clicked:int,open_rate:float,click_rate:float}
     */
    public function summary(?string $from = null, ?string $to = null): array
    {
        $builder = $this->db->table($this->table)
            ->select("
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) AS queued,
                SUM(CASE WHEN open_count > 0 THEN 1 ELSE 0 END) AS opened,
                SUM(CASE WHEN click_count > 0 THEN 1 ELSE 0 END) AS clicked,
                COUNT(*) AS total
            ", false);

        if ($from !== null && $from !== '') {
            $builder->where('created_at >=', $from);
        }
        if ($to !== null && $to !== '') {
            $builder->where('created_at <=', $to);
        }

        $row = $builder->get()->getRowArray() ?? [];
        $sent    = (int) ($row['sent'] ?? 0);
        $opened  = (int) ($row['opened'] ?? 0);
        $clicked = (int) ($row['clicked'] ?? 0);

        return [
            'sent'       => $sent,
            'failed'     => (int) ($row['failed'] ?? 0),
            'queued'     => (int) ($row['queued'] ?? 0),
            'total'      => (int) ($row['total'] ?? 0),
            'opened'     => $opened,
            'clicked'    => $clicked,
            'open_rate'  => $sent > 0 ? round(($opened / $sent) * 100, 1) : 0.0,
            'click_rate' => $sent > 0 ? round(($clicked / $sent) * 100, 1) : 0.0,
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
