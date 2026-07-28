<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class EmailHtmlCampaignModel extends Model
{
    protected $table            = 'email_html_campaigns';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $protectFields    = true;
    protected $allowedFields    = [
        'name',
        'subject',
        'html_content',
        'builder_id',
        'cheerio_builder_id',
        'mode',
        'label_name',
        'recipients_json',
        'status',
        'sent_count',
        'failed_count',
        'last_error',
        'sent_at',
        'created_by',
    ];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $beforeInsert = ['encodeRecipients'];
    protected $beforeUpdate = ['encodeRecipients'];
    protected $afterFind    = ['decodeRecipients'];

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

        if (isset($data['data'][0]) && is_array($data['data'][0])) {
            foreach ($data['data'] as &$row) {
                $decode($row);
            }
            unset($row);
        } elseif (is_array($data['data'])) {
            $decode($data['data']);
        }

        return $data;
    }
}
