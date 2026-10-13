<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\SelfHealingSchema;
use CodeIgniter\Model;

class CampaignContactModel extends Model
{
    use SelfHealingSchema;

    protected $table            = 'campaign_contacts';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'campaign_id',
        'contact_id',
        'status',
        'wa_message_id',
        'error_message',
        'sent_at',
        'delivered_at',
        'read_at',
    ];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'campaign_id' => 'required|is_natural_no_zero',
        'contact_id'  => 'required|is_natural_no_zero',
        'status'      => 'permit_empty|in_list[pending,queued,sent,delivered,read,failed]',
    ];

    protected $validationMessages   = [];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    /**
     * @return list<array<string, mixed>>
     */
    public function getByCampaign(int $campaignId, ?string $status = null): array
    {
        $builder = $this->where('campaign_id', $campaignId);

        if ($status !== null) {
            $builder->where('status', $status);
        }

        return $builder->findAll();
    }

    public function updateStatusByWaMessageId(string $waMessageId, string $status): bool
    {
        $waMessageId = trim($waMessageId);
        $status      = strtolower(trim($status));
        if ($waMessageId === '' || $status === '') {
            return false;
        }

        $rows = $this->where('wa_message_id', $waMessageId)->findAll();
        if ($rows === []) {
            return false;
        }

        $now     = date('Y-m-d H:i:s');
        $updated = false;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (! \App\Libraries\WhatsAppDeliveryStatus::shouldAdvance((string) ($row['status'] ?? ''), $status)) {
                continue;
            }

            $data = ['status' => $status];
            if ($status === 'sent' && trim((string) ($row['sent_at'] ?? '')) === '') {
                $data['sent_at'] = $now;
            } elseif ($status === 'delivered') {
                if (trim((string) ($row['delivered_at'] ?? '')) === '') {
                    $data['delivered_at'] = $now;
                }
            } elseif ($status === 'read') {
                if (trim((string) ($row['read_at'] ?? '')) === '') {
                    $data['read_at'] = $now;
                }
                if (trim((string) ($row['delivered_at'] ?? '')) === '') {
                    $data['delivered_at'] = $now;
                }
            } 

            if ($this->update((int) $row['id'], $data)) {
                $updated = true;
            }
        }

        return $updated;
    }
}
