<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\SelfHealingSchema;
use CodeIgniter\Model;

class EmailHtmlCampaignModel extends Model
{
    use SelfHealingSchema {
        initialize as protected initializeSchema;
    }

    /** Status values the app writes; `paused` = stopped by EmailReputationGuard. */
    public const STATUSES = ['draft', 'queued', 'sending', 'sent', 'failed', 'cancelled', 'paused'];

    /** @var array<string, true> databases whose status enum was already checked */
    protected static array $statusEnumChecked = [];

    protected $table            = 'email_html_campaigns';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $protectFields    = true;
    protected $allowedFields    = [
        'name',
        'subject',
        'html_content',
        'attachment_path',
        'attachment_name',
        'builder_id',
        'cheerio_builder_id',
        'sender_id',
        'mode',
        'label_name',
        'recipients_json',
        'status',
        'sent_count',
        'failed_count',
        'last_error',
        'sent_at',
        'scheduled_at',
        'created_by',
    ];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $beforeInsert = ['encodeRecipients'];
    protected $beforeUpdate = ['encodeRecipients'];
    protected $afterFind    = ['decodeRecipients'];

    protected function initialize(): void
    {
        $this->initializeSchema();
        $this->ensureStatusEnum();
    }

    /**
     * SchemaRepairService only adds missing columns, so widen the `status` enum here
     * (docs/sql/2026-10-07_email_campaign_paused_status.sql).
     */
    protected function ensureStatusEnum(): void
    {
        $key = (string) $this->db->getDatabase();
        if (isset(self::$statusEnumChecked[$key])) {
            return;
        }
        self::$statusEnumChecked[$key] = true;

        try {
            if (! $this->db->tableExists($this->table)) {
                return;
            }
            $table = $this->db->prefixTable($this->table);
            $row   = $this->db->query('SHOW COLUMNS FROM `' . $table . "` LIKE 'status'")->getRowArray();
            $type  = strtolower((string) ($row['Type'] ?? ''));
            if ($type === '' || ! str_starts_with($type, 'enum(') || str_contains($type, "'paused'")) {
                return;
            }

            $values = implode(',', array_map(static fn (string $s) => "'" . $s . "'", self::STATUSES));
            $this->db->query('ALTER TABLE `' . $table . '` MODIFY `status` ENUM(' . $values . ") NOT NULL DEFAULT 'draft'");
            log_message('notice', 'Schema auto-repair on {db}: email_html_campaigns.status now allows paused', ['db' => $key]);
        } catch (\Throwable $e) {
            log_message('error', 'Schema auto-repair skipped for email_html_campaigns.status: {msg}', ['msg' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function encodeRecipients(array $data): array
    {
        if (isset($data['data']['recipients_json']) && is_array($data['data']['recipients_json'])) {
            $data['data']['recipients_json'] = json_encode(array_values($data['data']['recipients_json']));
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function decodeRecipients(array $data): array
    {
        if (! isset($data['data'])) {
            return $data;
        }

        $decode = static function (array &$row): void {
            if (isset($row['recipients_json']) && is_string($row['recipients_json'])) {
                $decoded = json_decode($row['recipients_json'], true);
                $row['recipients'] = is_array($decoded) ? $decoded : [];
            } elseif (! isset($row['recipients'])) {
                $row['recipients'] = [];
            }
        };

        // Empty findAll() returns [] — do not treat it as a single row (would invent a fake row without id).
        if ($data['data'] === [] || $data['data'] === null) {
            return $data;
        }

        $isCollection = array_is_list($data['data'])
            && (isset($data['data'][0]) ? is_array($data['data'][0]) : false);

        if ($isCollection) {
            foreach ($data['data'] as &$row) {
                if (is_array($row)) {
                    $decode($row);
                }
            }
            unset($row);
        } elseif (is_array($data['data'])) {
            $decode($data['data']);
        }

        return $data;
    }
}
