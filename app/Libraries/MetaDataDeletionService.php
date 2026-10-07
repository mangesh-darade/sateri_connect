<?php

declare(strict_types=1);

namespace App\Libraries;

use Config\Database;
use RuntimeException;
use Throwable;

/**
 * Meta "Data Deletion Request" callback: verifies the signed_request,
 * records the request in the master DB and exposes its status by code.
 *
 * The platform never stores Facebook-user-keyed personal data (Embedded
 * Signup keeps only business-level tokens), so a verified request is
 * completed immediately with an explanatory note.
 */
class MetaDataDeletionService
{
    public const TABLE = 'data_deletion_requests';

    /**
     * @return array<string, mixed> Verified payload (user_id, algorithm, issued_at, ...)
     */
    public function parseSignedRequest(string $signedRequest): array
    {
        $parts = explode('.', trim($signedRequest), 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new RuntimeException('Malformed signed_request.', 400);
        }

        [$encodedSig, $encodedPayload] = $parts;
        $signature = $this->base64UrlDecode($encodedSig);
        $payload   = json_decode($this->base64UrlDecode($encodedPayload), true);

        if (! is_array($payload) || strtoupper((string) ($payload['algorithm'] ?? '')) !== 'HMAC-SHA256') {
            throw new RuntimeException('Unsupported signed_request payload.', 400);
        }

        $secrets = (new MasterTenantRepository())->allAppSecrets();
        if ($secrets === []) {
            throw new RuntimeException('Meta app secret is not configured.', 503);
        }

        foreach ($secrets as $secret) {
            if (hash_equals(hash_hmac('sha256', $encodedPayload, $secret, true), $signature)) {
                return $payload;
            }
        }

        throw new RuntimeException('Invalid signed_request signature.', 400);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{confirmation_code: string, status: string}
     */
    public function record(array $payload): array
    {
        $this->ensureTable();

        $code = 'del_' . bin2hex(random_bytes(8));
        $now  = date('Y-m-d H:i:s');

        $this->db()->table(self::TABLE)->insert([
            'confirmation_code' => $code,
            'fb_user_id'        => substr((string) ($payload['user_id'] ?? ''), 0, 64),
            'status'            => 'completed',
            'notes'             => 'No personal data linked to this Facebook user is stored by the platform. '
                . 'WhatsApp customer data is controlled by each business and removed from Contacts.',
            'requested_at'      => $now,
            'completed_at'      => $now,
        ]);

        return ['confirmation_code' => $code, 'status' => 'completed'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByCode(string $code): ?array
    {
        if (! preg_match('/^del_[a-f0-9]{16}$/', $code)) {
            return null;
        }

        try {
            $this->ensureTable();
            $row = $this->db()->table(self::TABLE)->where('confirmation_code', $code)->get()->getRowArray();

            return $row ?: null;
        } catch (Throwable $e) {
            log_message('error', 'MetaDataDeletionService::findByCode: {msg}', ['msg' => $e->getMessage()]);

            return null;
        }
    }

    public function ensureTable(): void
    {
        $db = $this->db();
        if ($db->tableExists(self::TABLE)) {
            return;
        }

        $forge = Database::forge($db);
        $forge->addField([
            'id'                => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'confirmation_code' => ['type' => 'VARCHAR', 'constraint' => 40],
            'fb_user_id'        => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'status'            => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'received'],
            'notes'             => ['type' => 'TEXT', 'null' => true],
            'requested_at'      => ['type' => 'DATETIME', 'null' => true],
            'completed_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $forge->addKey('id', true);
        $forge->addUniqueKey('confirmation_code');
        $forge->addKey('fb_user_id');
        $forge->createTable(self::TABLE, true);
    }

    private function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }

    /**
     * @return \CodeIgniter\Database\BaseConnection
     */
    private function db()
    {
        return MasterTenantRepository::masterConnection();
    }
}
