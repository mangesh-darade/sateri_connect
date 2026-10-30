<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\ContactModel;
use App\Models\TagModel;
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
     * @return array{base_url: string, private_key: string, enabled: bool}
     */
    public function getConfig(): array
    {
        $s = $this->settings;

        return [
            'base_url'    => rtrim(trim((string) $s->get('elintom_base_url', '')), '/'),
            'private_key' => (string) $s->get('elintom_api_private_key', ''),
            // Unset means active, so installs that already have credentials keep syncing.
            'enabled'     => (string) $s->get('elintom_enabled', '1') !== '0',
        ];
    }

    /** True when both the domain URL and the Api3 private key are saved. */
    public function isConfigured(): bool
    {
        $cfg = $this->getConfig();

        return $cfg['base_url'] !== '' && $cfg['private_key'] !== '';
    }

    /** Switched on in settings AND both the URL and the key are saved. */
    public function isActive(): bool
    {
        return $this->getConfig()['enabled'] && $this->isConfigured();
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

        if (array_key_exists('enabled', $config)) {
            $this->settings->set('elintom_enabled', $config['enabled'] ? '1' : '0', 'elintom', false);
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
            $payload = $this->fetchCustomers('sateri_leads', 'leads');
            $count   = count($payload);

            return [
                'ok'      => true,
                'message' => "Connected. {$count} lead(s) in ElintOm.",
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
     * @return array{created: int, updated: int, skipped: int, failed: int, unchanged: int, deleted: int, total: int, errors: list<string>}
     */
    public function sync(): array
    {
        if (! $this->isActive()) {
            throw new RuntimeException('ElintOm integration is inactive. Turn it on in Settings > ElintOm POS.');
        }

        // Customers pull is off: sync is leads <-> contacts only (see pullLeads / ElintOmLeadPushService::pushAll).
        $rows = [];

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $failed  = 0;
        $unchanged = 0;
        $deleted   = 0;
        $errors  = [];

        foreach ($rows as $row) {
            $mobile = normalize_phone((string) ($row['mobile'] ?? $row['phone'] ?? ''));
            if ($mobile === '') {
                $skipped++;
                continue;
            }

            try {
                $wasCreated = false;
                $wasSame    = false;
                $wasDeleted = false;
                $this->upsertContact($row, $mobile, $wasCreated, $wasSame, $wasDeleted);
                if ($wasDeleted) {
                    $deleted++;
                } elseif ($wasCreated) {
                    $created++;
                } elseif ($wasSame) {
                    $unchanged++;
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

        $leadStats = ['created' => 0, 'updated' => 0, 'failed' => 0];
        $pushStats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
        // Push first so a contact's own source reaches ElintOm before leads are pulled back.
        try {
            $pushStats = (new ElintOmLeadPushService())->pushAll();
        } catch (Throwable $e) {
            $errors[] = 'leads push: ' . $e->getMessage();
        }
        try {
            $leadStats = $this->pullLeads();
        } catch (Throwable $e) {
            $errors[] = 'leads pull: ' . $e->getMessage();
        }

        if ($created + $updated > 0) {
            log_activity('sync', 'contacts', sprintf('ElintOm customers sync: %d created, %d updated, %d deleted-in-app skipped', $created, $updated, $deleted), [
                'source'    => 'elintom',
                'created'   => $created,
                'updated'   => $updated,
                'unchanged' => $unchanged,
                'deleted'   => $deleted,
                'failed'    => $failed,
            ]);
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'failed'  => $failed,
            'unchanged' => $unchanged,
            'deleted' => $deleted,
            'total'   => count($rows),
            'errors'  => $errors,
            'leads_pulled' => $leadStats,
            'leads_pushed' => $pushStats,
        ];
    }

    /**
     * ElintOm leads (Api3 action=sateri_leads) -> contacts. Only name/email are refreshed on existing contacts.
     *
     * @return array{created: int, updated: int, failed: int}
     */
    protected function pullLeads(): array
    {
        $out     = ['created' => 0, 'updated' => 0, 'failed' => 0];
        $pushMap = (new ElintOmLeadPushService())->mapping();

        $this->contacts->skipElintOmPush = true; // leads come from ElintOm; pushing each one back is a wasted HTTP call
        $leads = $this->fetchCustomers('sateri_leads', 'leads');
        foreach ($leads as $row) {
            $mobile = normalize_phone((string) ($row['mobile'] ?? ''));
            if ($mobile === '') {
                continue;
            }

            try {
                $existing = $this->contacts->findByMobile($mobile) ?? $this->contacts->findByMobile($mobile, true);
                if ($existing !== null && (! empty($existing['deleted_at']) || ! empty($existing['archived_at']))) {
                    continue;
                }

                $name   = trim((string) ($row['name'] ?? ''));
                $email  = trim((string) ($row['email'] ?? ''));
                $source = trim((string) ($row['source'] ?? ''));

                if ($existing === null) {
                    $id = $this->contacts->insert([
                        'channel'       => 'whatsapp',
                        'external_id'   => $mobile,
                        'mobile'        => $mobile,
                        'name'          => $name !== '' ? $name : null,
                        'email'         => $email !== '' ? $email : null,
                        'country'       => 'IN',
                        'status'        => 'active',
                        'notes'         => 'Synced from ElintOm lead',
                        'wa_opt_in'        => 1,
                        'wa_opt_in_at'     => date('Y-m-d H:i:s'),
                        'wa_opt_in_source' => 'elintom_lead',
                        'custom_fields' => ['source' => $source !== '' ? $source : 'ElintOM', 'elintom_lead_id' => (int) ($row['id'] ?? 0)],
                    ]);
                    if (! $id) {
                        throw new RuntimeException(implode(' ', $this->contacts->errors() ?: ['Insert failed']));
                    }
                    $this->attachSourceGroup((int) $id);
                    $out['created']++;
                    continue;
                }

                $this->attachSourceGroup((int) $existing['id']);
                $upd = [];
                $cf  = is_array($existing['custom_fields'] ?? null) ? $existing['custom_fields'] : [];
                if (! empty($cf['elintom_lead_id']) && empty($existing['wa_opt_in'])) {
                    $upd['wa_opt_in']        = 1;
                    $upd['wa_opt_in_at']     = date('Y-m-d H:i:s');
                    $upd['wa_opt_in_source'] = 'elintom_lead';
                }
                // ElintOm is primary for an existing number: source follows ElintOm.
                $curSource = (string) ($cf['source'] ?? '');
                $newSource = $source !== '' ? $source : ($curSource === '' || $curSource === 'elintom_lead' ? 'ElintOM' : '');
                if ($newSource !== '' && $newSource !== $curSource) {
                    $upd['custom_fields'] = array_merge($cf, ['source' => $newSource]);
                }
                // mapped attributes (e.g. status) follow ElintOm
                $cfNow = $upd['custom_fields'] ?? $cf;
                foreach ($pushMap as $attr => $col) {
                    $val = $row[$col] ?? null;
                    if (is_scalar($val) && trim((string) $val) !== '' && (string) ($cfNow[$attr] ?? '') !== trim((string) $val)) {
                        $cfNow[$attr] = trim((string) $val);
                        $upd['custom_fields'] = $cfNow;
                    }
                }
                if ($name !== '' && $name !== (string) ($existing['name'] ?? '')) {
                    $upd['name'] = $name;
                }
                if ($email !== '' && $email !== (string) ($existing['email'] ?? '')) {
                    $upd['email'] = $email;
                }
                if ($upd !== []) {
                    if (! $this->contacts->update((int) $existing['id'], $upd)) {
                        throw new RuntimeException(implode(' ', $this->contacts->errors() ?: ['Update failed']));
                    }
                    $out['updated']++;
                }
            } catch (Throwable $e) {
                $out['failed']++;
            }
        }

        $this->contacts->skipElintOmPush = false;

        return $out;
    }

    /** Adds the contact to the "ElintOM" customer group (created on first use); keeps its other groups. */
    protected function attachSourceGroup(int $contactId): void
    {
        $tags  = model(TagModel::class);
        $group = $tags->findOrCreateByName('ElintOM', '#0D6EFD');
        if (! empty($group['id'])) {
            $tags->attachContact((int) $group['id'], $contactId);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function fetchCustomers(string $action = 'sateri_contacts', string $listKey = 'customers'): array
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
            'action'     => $action,
        ] + ($action === 'sateri_leads' ? ['columns' => json_encode(array_values((new ElintOmLeadPushService())->mapping()))] : []));

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

        $customers = $decoded[$listKey] ?? [];
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
    protected function upsertContact(array $row, string $mobile, bool &$wasCreated, bool &$unchanged = false, bool &$wasDeleted = false): void
    {
        $wasCreated = false;
        $unchanged  = false;
        $wasDeleted = false;
        $existing   = $this->contacts->findByMobile($mobile) ?? $this->contacts->findByMobile($mobile, true);

        // A contact the user deleted in the app stays deleted; sync must not bring it back.
        if ($existing !== null && ! empty($existing['deleted_at'])) {
            $wasDeleted = true;

            return;
        }

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
        if (! $this->hasChanges($existing, $data)) {
            $unchanged = true;

            return;
        }

        if (! $this->contacts->update($id, $data)) {
            throw new RuntimeException(implode(' ', $this->contacts->errors() ?: ['Update failed']));
        }
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $data
     */
    protected function hasChanges(array $existing, array $data): bool
    {
        foreach ($data as $field => $value) {
            $current = $existing[$field] ?? null;
            if ($field === 'custom_fields') {
                $current = is_array($current) ? $current : (json_decode((string) $current, true) ?: []);
                if ($this->normalizeFields($current) !== $this->normalizeFields((array) $value)) {
                    return true;
                }
                continue;
            }
            if ((string) ($current ?? '') !== (string) ($value ?? '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    protected function normalizeFields(array $fields): array
    {
        $out = [];
        foreach ($fields as $key => $value) {
            $out[(string) $key] = is_array($value) ? json_encode($value) : (string) ($value ?? '');
        }
        ksort($out);

        return $out;
    }
}
