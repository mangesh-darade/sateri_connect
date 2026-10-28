<?php

declare(strict_types=1);

namespace App\Libraries;

use Throwable;

/**
 * Push Sateri Connect contacts to ElintOm leads (Api3 sateri_lead_upsert / sateri_leads_bulk).
 * Best effort: never blocks a contact save.
 */
class ElintOmLeadPushService
{

    /** Mapping is offered only when ElintOm URL and private key are both set. */
    public function isConfigured(): bool
    {
        $cfg = (new ElintOmCustomerSyncService())->getConfig();

        return $cfg['base_url'] !== '' && $cfg['private_key'] !== '';
    }

    /**
     * Saved mapping: attribute key => ElintOm lead column.
     *
     * @return array<string, string>
     */
    public function mapping(): array
    {
        if (! $this->isConfigured()) {
            return [];
        }
        $raw = json_decode((string) service('settingsService')->get('elintom_lead_mapping', ''), true);

        return is_array($raw) ? array_map('strval', $raw) : [];
    }

    /** @param array<string, string> $map */
    public function saveMapping(array $map): void
    {
        $clean = [];
        foreach ($map as $attr => $col) {
            $attr = (string) $attr;
            $col  = trim((string) $col);
            if ($col !== '' && preg_match(ContactAttributeService::KEY_PATTERN, $attr) === 1 && preg_match('/^\w+$/', $col) === 1) {
                $clean[$attr] = $col;
            }
        }
        service('settingsService')->set('elintom_lead_mapping', json_encode($clean), 'elintom', false);
    }

    /**
     * Lead columns available in ElintOm (Api3 sateri_lead_fields).
     *
     * @return list<string>
     */
    public function leadFields(): array
    {
        $res = $this->post('sateri_lead_fields', [], 8);

        return array_values(array_filter((array) ($res['fields'] ?? []), 'is_string'));
    }

    /**
     * Mapped attribute values of a contact => [lead column => value].
     *
     * @param array<string, mixed> $custom
     * @param array<string, string> $map
     *
     * @return array<string, string>
     */
    protected function mappedFields(array $custom, array $map): array
    {
        $out = [];
        foreach ($map as $attr => $col) {
            $val = $custom[$attr] ?? '';
            if (is_scalar($val) && trim((string) $val) !== '') {
                $out[$col] = trim((string) $val);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $contact
     */
    public function push(array $contact): void
    {
        try {
            $custom = $contact['custom_fields'] ?? [];
            if (is_string($custom)) {
                $custom = json_decode($custom, true);
            }
            if (is_array($custom) && ! empty($custom['elintom_lead_id'])) {
                return; // came from an ElintOm lead, already there
            }

            $mobile = normalize_phone((string) ($contact['mobile'] ?? ''));
            if ($this->isPlaceholderMobile($mobile)) {
                return;
            }

            $this->post('sateri_lead_upsert', [
                'name'   => (string) ($contact['name'] ?? ''),
                'mobile' => $mobile,
                'email'  => (string) ($contact['email'] ?? ''),
                'source' => is_array($custom) ? (string) ($custom['source'] ?? '') : '',
                'fields' => json_encode($this->mappedFields(is_array($custom) ? $custom : [], $this->mapping())),
            ], 5);
        } catch (Throwable $e) {
            log_message('error', 'ElintOm lead push failed: ' . $e->getMessage());
        }
    }

    /**
     * Push every app-native contact (not synced from ElintOm) in batches.
     *
     * @return array{created: int, updated: int, skipped: int}
     */
    public function pushAll(): array
    {
        $rows = model(\App\Models\ContactModel::class)
            ->select('name, mobile, email, custom_fields')
            ->where('mobile !=', '')
            ->findAll();

        $list = [];
        $map  = $this->mapping();
        foreach ($rows as $r) {
            $custom = $r['custom_fields'] ?? [];
            if (is_array($custom) && ! empty($custom['elintom_lead_id'])) {
                continue;
            }
            if ($this->isPlaceholderMobile(normalize_phone((string) $r['mobile']))) {
                continue;
            }
            $list[] = ['name' => (string) ($r['name'] ?? ''), 'mobile' => (string) $r['mobile'], 'email' => (string) ($r['email'] ?? ''), 'source' => is_array($custom) ? (string) ($custom['source'] ?? '') : '', 'fields' => $this->mappedFields(is_array($custom) ? $custom : [], $map)];
        }

        $out = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        foreach (array_chunk($list, 200) as $chunk) {
            $res = $this->post('sateri_leads_bulk', ['contacts' => json_encode($chunk)], 60);
            foreach (array_keys($out) as $k) {
                $out[$k] += (int) ($res[$k] ?? 0);
            }
        }

        return $out;
    }

    /** Empty or all-zero numbers (e.g. 0000000000) are not real mobiles. */
    protected function isPlaceholderMobile(string $mobile): bool
    {
        return $mobile === '' || preg_match('/^0+$/', preg_replace('/\D+/', '', $mobile) ?? '') === 1;
    }

    /**
     * @param array<string, string> $fields
     *
     * @return array<string, mixed>
     */
    protected function post(string $action, array $fields, int $timeout): array
    {
        $cfg = (new ElintOmCustomerSyncService())->getConfig();
        if ($cfg['base_url'] === '' || $cfg['private_key'] === '') {
            return [];
        }

        $base     = $cfg['base_url'];
        $endpoint = (stripos($base, 'api3') !== false && stripos($base, 'eshop') !== false) ? $base : $base . '/api3/eshop';

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['privatekey' => $cfg['private_key'], 'action' => $action] + $fields),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);

        $decoded = is_string($raw) ? json_decode(ltrim($raw, "\xEF\xBB\xBF \t\r\n"), true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}
