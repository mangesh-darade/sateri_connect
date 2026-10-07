<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\SelfHealingSchema;
use CodeIgniter\Model;

class EmailUnsubscribeModel extends Model
{
    use SelfHealingSchema;

    protected $table            = 'email_unsubscribes';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'email',
        'campaign_id',
        'reason',
        'ip_address',
        'is_active',
        'is_deleted',
    ];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    public function isUnsubscribed(string $email): bool
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return false;
        }

        $row = $this->where('email', $email)
            ->where('is_deleted', 0)
            ->where('is_active', 1)
            ->first();

        return $row !== null;
    }

    public function recordUnsubscribe(string $email, ?int $campaignId = null, ?string $reason = null, ?string $ip = null): bool
    {
        $email = strtolower(trim($email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $existing = $this->where('email', $email)->first();
        if ($existing) {
            return (bool) $this->update((int) $existing['id'], [
                'campaign_id' => $campaignId ?: ($existing['campaign_id'] ?? null),
                'reason'      => $reason ?: ($existing['reason'] ?? 'User clicked unsubscribe'),
                'ip_address'  => $ip ?: ($existing['ip_address'] ?? null),
                'is_active'   => 1,
                'is_deleted'  => 0,
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);
        }

        return (bool) $this->insert([
            'email'       => $email,
            'campaign_id' => $campaignId,
            'reason'      => $reason ?: 'User clicked unsubscribe',
            'ip_address'  => $ip,
            'is_active'   => 1,
            'is_deleted'  => 0,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Filter out any unsubscribed emails from a list.
     *
     * @param list<string> $emails
     * @return list<string>
     */
    public function filterActiveRecipients(array $emails): array
    {
        if ($emails === []) {
            return [];
        }

        $cleaned = [];
        foreach ($emails as $email) {
            $e = strtolower(trim((string) $email));
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) {
                $cleaned[$e] = true;
            }
        }
        $normalized = array_keys($cleaned);
        if ($normalized === []) {
            return [];
        }

        $unsubscribed = $this->select('email')
            ->whereIn('email', $normalized)
            ->where('is_deleted', 0)
            ->where('is_active', 1)
            ->findColumn('email') ?: [];

        $blocked = array_flip(array_map('strtolower', (array) $unsubscribed));

        return array_values(array_filter($normalized, static fn (string $e) => ! isset($blocked[$e])));
    }
}
