<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\ContactModel;
use RuntimeException;
use Throwable;

/**
 * Pull ElintOm POS customers via HTTP (Api3 action=sateri_contacts) into contacts.
 */
class ElintOmCustomerSyncService
{
    public function __construct(
        protected ?SettingsService $settings = null,
        protected ?ContactModel $contacts = null,
    ) {
        $this->settings ??= service('settingsService');
        $this->contacts ??= model(ContactModel::class);
    }

    /**
     * @return array{base_url: string, private_key: string}
     */
    public function getConfig(): array
    {
        $s = $this->settings;

        return [
            'base_url'    => rtrim(trim((string) $s->get('elintom_base_url', '')), '/'),
            'private_key' => (string) $s->get('elintom_api_private_key', ''),
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    public function setConfig(array $config): void
    {
        if (array_key_exists('base_url', $config)) {
            $url = rtrim(trim((string) $config['base_url']), '/');
            $this->settings->set('elintom_base_url', $url, 'elintom', false);
        }

        if (array_key_exists('private_key', $config)) {
            $key = (string) $config['private_key'];
            if ($key !== '' && ! str_contains($key, '•')) {
                $this->settings->set('elintom_api_private_key', $key, 'elintom', true);
            }
        }
    }

    /**
     * @return array{ok: bool, message: string, count?: int}
     */
    public function testConnection(): array
    {
        try {
            $payload = $this->fetchCustomers();
            $count   = count($payload);

            return [
                'ok'      => true,
                'message' => "Connected. {$count} syncable customer(s) from ElintOm.",
                'count'   => $count,
            ];
        } catch (Throwable $e) {
            return [
                'ok'      => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array{created: int, updated: int, skipped: int, failed: int, total: int, errors: list<string>}
     */
    public function sync(): array
    {
        $rows = $this->fetchCustomers();

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $failed  = 0;
        $errors  = [];

        foreach ($rows as $row) {
            $mobile = normalize_phone((string) ($row['mobile'] ?? $row['phone'] ?? ''));
            if ($mobile === '') {
                $skipped++;
                continue;
            }

            try {
                $wasCreated = false;
                $this->upsertContact($row, $mobile, $wasCreated);
                if ($wasCreated) {
                    $created++;
                } else {
                    $updated++;
                }
            } catch (Throwable $e) {
                $failed++;
                if (count($errors) < 20) {
                    $errors[] = 'id=' . ($row['id'] ?? '?') . ': ' . $e->getMessage();
                }
            }
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'failed'  => $failed,
            'total'   => count($rows),
            'errors'  => $errors,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function fetchCustomers(): array
    {
        $cfg = $this->getConfig();
        if ($cfg['base_url'] === '') {
            throw new RuntimeException(
                'ElintOm domain URL is not set. Insert settings.key=elintom_base_url (e.g. http://localhost/ElintOm).'
            );
        }
        if ($cfg['private_key'] === '') {
            throw new RuntimeException(
                'ElintOm API private key is not set. Insert settings.key=elintom_api_private_key (same as ElintOm sma_settings.api_privatekey).'
            );
        }

        $endpoint = $this->resolveEshopEndpoint($cfg['base_url']);
        $body     = http_build_query([
            'privatekey' => $cfg['private_key'],
            'action'     => 'sateri_contacts',
        ]);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 20,
        ]);

        $raw  = curl_exec($ch);
        $errno = curl_errno($ch);
        $err   = curl_error($ch);
        $http  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            throw new RuntimeException('ElintOm request failed: ' . $err);
        }

        $decoded = $this->decodeJson(is_string($raw) ? $raw : '');
        if ($decoded === null) {
            throw new RuntimeException('ElintOm returned invalid JSON (HTTP ' . $http . ').');
        }

        $status = strtoupper((string) ($decoded['status'] ?? ''));
        $ok     = ($decoded['success'] ?? null) === true || $status === 'SUCCESS';
        if (! $ok) {
            $msg = (string) ($decoded['msg'] ?? $decoded['message'] ?? $decoded['mag'] ?? 'ElintOm API error');
            throw new RuntimeException($msg . ' (HTTP ' . $http . ')');
        }

        $customers = $decoded['customers'] ?? [];
        if (! is_array($customers)) {
            return [];
        }

        $out = [];
        foreach ($customers as $row) {
            if (is_array($row)) {
                $out[] = $row;
            } elseif (is_object($row)) {
                $out[] = (array) $row;
            }
        }

        return $out;
    }

    protected function resolveEshopEndpoint(string $base): string
    {
        $base = rtrim($base, '/');
        if (stripos($base, 'api3') !== false && stripos($base, 'eshop') !== false) {
            return $base;
        }

        return $base . '/api3/eshop';
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function decodeJson(string $body): ?array
    {
        $body = trim($body);
        if ($body === '') {
            return null;
        }
        if (strncmp($body, "\xEF\xBB\xBF", 3) === 0) {
            $body = substr($body, 3);
        }

        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($body, '{');
        $end   = strrpos($body, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($body, $start, $end - $start + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function upsertContact(array $row, string $mobile, bool &$wasCreated): void
    {
        $wasCreated = false;
        $existing   = $this->contacts->findByMobile($mobile, true);

        $custom = [
            'elintom_company_id' => isset($row['id']) ? (int) $row['id'] : null,
            'company'            => $row['company'] ?? null,
            'city'               => $row['city'] ?? null,
            'state'              => $row['state'] ?? null,
            'gstn_no'            => $row['gstn_no'] ?? null,
            'customer_group'     => $row['customer_group'] ?? ($row['customer_group_name'] ?? null),
            'source'             => 'elintom',
        ];

        if ($existing !== null && is_array($existing['custom_fields'] ?? null)) {
            $custom = array_merge($existing['custom_fields'], $custom);
        }

        $data = [
            'channel'       => 'whatsapp',
            'external_id'   => $mobile,
            'mobile'        => $mobile,
            'name'          => $row['name'] ?? ($existing['name'] ?? null),
            'email'         => ! empty($row['email']) ? (string) $row['email'] : ($existing['email'] ?? null),
            'country'       => ! empty($row['country']) ? (string) $row['country'] : ($existing['country'] ?? 'IN'),
            'status'        => $existing['status'] ?? 'active',
            'birthday'      => ! empty($row['birthday']) ? (string) $row['birthday'] : ($existing['birthday'] ?? null),
            'notes'         => $existing['notes'] ?? 'Synced from ElintOm',
            'custom_fields' => $custom,
        ];

        if ($existing === null) {
            $id = $this->contacts->insert($data);
            if (! $id) {
                throw new RuntimeException(implode(' ', $this->contacts->errors() ?: ['Insert failed']));
            }
            $wasCreated = true;

            return;
        }

        $id = (int) $existing['id'];
        if (! empty($existing['deleted_at'])) {
            $this->contacts->restoreContact($id);
            $data['status'] = 'active';
        }

        if (! $this->contacts->update($id, $data)) {
            throw new RuntimeException(implode(' ', $this->contacts->errors() ?: ['Update failed']));
        }
    }
}
