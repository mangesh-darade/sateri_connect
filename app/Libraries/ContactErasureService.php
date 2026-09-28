<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\ContactModel;
use RuntimeException;
use Throwable;

/**
 * Customer data-deletion request (Meta Platform Terms / DPDP): wipe messages,
 * conversations and every contact-linked row, then keep only a suppression
 * record (mobile + opt-out) so the number is never messaged again.
 */
class ContactErasureService
{
    /** Contact columns cleared on erase; mobile and wa_* consent fields are kept as the suppression record. */
    private const PII_COLUMNS = [
        'name', 'country', 'email', 'notes', 'birthday', 'custom_fields',
        'assigned_to', 'last_message_at', 'last_reply_at', 'external_id', 'wa_opt_in_source',
    ];

    /**
     * @return array{rows_deleted: int, tables: array<string, int>}
     */
    public function erase(int $contactId): array
    {
        $model   = model(ContactModel::class);
        $contact = $model->withDeleted()->find($contactId);
        if (! is_array($contact)) {
            throw new RuntimeException('Contact not found.');
        }

        $db     = db_connect();
        $tables = [];

        $conversationIds = $db->tableExists('conversations')
            ? array_map('intval', array_column(
                $db->table('conversations')->select('id')->where('contact_id', $contactId)->get()->getResultArray(),
                'id'
            ))
            : [];

        $db->transStart();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        try {
            if ($conversationIds !== []) {
                foreach ($this->tablesWithColumn('conversation_id') as $table) {
                    $db->table($table)->whereIn('conversation_id', $conversationIds)->delete();
                    $tables[$table] = ($tables[$table] ?? 0) + $db->affectedRows();
                }
            }
            foreach ($this->tablesWithColumn('contact_id') as $table) {
                $db->table($table)->where('contact_id', $contactId)->delete();
                $tables[$table] = ($tables[$table] ?? 0) + $db->affectedRows();
            }

            $fields = $db->getFieldNames('contacts');
            $update = [
                'status'              => 'inactive',
                'wa_opt_in'           => 0,
                'wa_opted_out_at'     => $contact['wa_opted_out_at'] ?? date('Y-m-d H:i:s'),
                'wa_suppressed_until' => null,
                'wa_suppress_reason'  => 'Customer data erased on request',
                'deleted_at'          => date('Y-m-d H:i:s'),
                'updated_at'          => date('Y-m-d H:i:s'),
            ];
            foreach (self::PII_COLUMNS as $column) {
                $update[$column] = null;
            }
            $db->table('contacts')
                ->where('id', $contactId)
                ->update(array_intersect_key($update, array_flip($fields)));
        } finally {
            $db->query('SET FOREIGN_KEY_CHECKS = 1');
        }
        $db->transComplete();

        if (! $db->transStatus()) {
            throw new RuntimeException('Could not erase contact data. Nothing was changed.');
        }

        $tables = array_filter($tables);
        try {
            (new ActivityLogger())->log('erase', 'contacts', 'Customer data erased on request', [
                'contact_id' => $contactId,
                'tables'     => $tables,
            ]);
        } catch (Throwable) {
        }

        return ['rows_deleted' => array_sum($tables), 'tables' => $tables];
    }

    /**
     * @return list<string>
     */
    protected function tablesWithColumn(string $column): array
    {
        $db   = db_connect();
        $rows = $db->query(
            'SELECT c.TABLE_NAME FROM information_schema.COLUMNS c'
            . ' JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME'
            . " WHERE c.TABLE_SCHEMA = ? AND c.COLUMN_NAME = ? AND c.TABLE_NAME <> ? AND t.TABLE_TYPE = 'BASE TABLE'",
            [$db->getDatabase(), $column, 'contacts']
        )->getResultArray();

        return array_values(array_unique(array_map(static fn (array $r): string => (string) ($r['TABLE_NAME'] ?? $r['table_name'] ?? ''), $rows)));
    }
}
