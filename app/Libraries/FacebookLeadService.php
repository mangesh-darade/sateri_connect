<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\ContactModel;
use Throwable;

/**
 * Turns a Facebook Lead Ads webhook into a contact and a facebook_lead workflow.
 *
 * Meta sends only the lead id. The Page access token then loads the form answers.
 */
class FacebookLeadService
{
    /**
     * Point the request at the tenant whose Settings Page ID matches this Facebook Page.
     */
    public function useTenantForPage(string $pageId): void
    {
        $pageId = trim($pageId);
        if ($pageId === '' || ! MasterTenantRepository::masterConfigured()) {
            return;
        }

        $current = trim((string) ((new SettingsService())->getMetaConfig()['page_id'] ?? ''));
        if ($current === $pageId) {
            return;
        }

        $connection = new TenantConnection();
        $snapshot   = $connection->capture();

        foreach ((new MasterTenantRepository())->listActiveTenants(null) as $row) {
            $key = strtolower(trim((string) ($row['key'] ?? '')));
            if ($key === '' || $key === TenantContext::get()) {
                continue;
            }
            if (! $connection->apply($key, 'webhook')) {
                continue;
            }
            $found = trim((string) ((new SettingsService())->getMetaConfig()['page_id'] ?? ''));
            if ($found === $pageId) {
                return;
            }
        }

        $connection->restore($snapshot);
    }

    /**
     * @param array<string, mixed> $value Webhook change.value
     *
     * @return array{ok: bool, duplicate?: bool, contact_id?: int, matched?: int, message?: string}
     */
    public function ingest(array $value): array
    {
        $leadId = trim((string) ($value['leadgen_id'] ?? $value['lead_id'] ?? ''));
        $pageId = trim((string) ($value['page_id'] ?? ''));
        if ($leadId === '') {
            return ['ok' => false, 'message' => 'Facebook lead id is missing.'];
        }

        $this->useTenantForPage($pageId);

        $cacheKey = 'fb_lead_' . sha1($leadId);
        try {
            if (cache()->get($cacheKey)) {
                return ['ok' => true, 'duplicate' => true, 'contact_id' => 0];
            }
        } catch (Throwable) {
            $cacheKey = '';
        }

        try {
            $lead = (new MetaPageMessagingAPI())->fetchLead($leadId);
        } catch (Throwable $e) {
            log_message('error', 'Facebook lead fetch failed: {msg}', ['msg' => $e->getMessage()]);

            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $result = $this->applyLead($lead, $value);
        if ($cacheKey !== '' && ($result['ok'] ?? false)) {
            try {
                cache()->save($cacheKey, 1, 7 * 86400);
            } catch (Throwable) {
                // A retry may run the workflow again. The contact match stays the same.
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $lead Graph lead object
     * @param array<string, mixed> $value Webhook change.value
     *
     * @return array{ok: bool, contact_id: int, matched: int, message?: string}
     */
    public function applyLead(array $lead, array $value = []): array
    {
        $fields = $this->fieldsFromLead($lead);
        $phone  = $this->phoneFrom($fields);
        $name   = $this->nameFrom($fields);
        $email  = $this->emailFrom($fields);
        $leadId = trim((string) ($lead['id'] ?? $value['leadgen_id'] ?? ''));
        $formId = trim((string) ($lead['form_id'] ?? $value['form_id'] ?? ''));
        $adId   = trim((string) ($lead['ad_id'] ?? $value['ad_id'] ?? $value['adgroup_id'] ?? ''));
        $pageId = trim((string) ($value['page_id'] ?? ''));

        $contactId = $this->saveContact($phone, $name, $email, $fields);

        $context = [
            'contact_id'  => $contactId,
            'phone'       => $phone,
            'mobile'      => $phone,
            'name'        => $name,
            'email'       => $email,
            'form_id'     => $formId,
            'ad_id'       => $adId,
            'page_id'     => $pageId,
            'leadgen_id'  => $leadId,
            'campaign_id' => trim((string) ($lead['campaign_id'] ?? '')),
            'content'     => $this->summary($fields),
            'fields'      => $fields,
        ];

        $run = service('automationEngine')->processTrigger('facebook_lead', $context);

        log_activity('create', 'automations', 'Facebook lead started workflow' . ($name !== '' ? ' for ' . $name : ''), [
            'leadgen_id' => $leadId,
            'form_id'    => $formId,
            'page_id'    => $pageId,
            'contact_id' => $contactId,
            'matched'    => (int) ($run['matched'] ?? 0),
        ]);

        return [
            'ok'         => true,
            'contact_id' => $contactId,
            'matched'    => (int) ($run['matched'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $lead
     *
     * @return array<string, string>
     */
    public function fieldsFromLead(array $lead): array
    {
        $rows = $lead['field_data'] ?? [];
        if (! is_array($rows)) {
            return [];
        }

        $fields = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = strtolower(trim((string) ($row['name'] ?? '')));
            $values = $row['values'] ?? [];
            $value = '';
            if (is_array($values)) {
                $value = trim((string) ($values[0] ?? ''));
            } elseif (is_string($values)) {
                $value = trim($values);
            }
            if ($key !== '' && $value !== '') {
                $fields[$key] = $value;
            }
        }

        return $fields;
    }

    /**
     * @param array<string, string> $fields
     */
    public function phoneFrom(array $fields): string
    {
        foreach (['phone_number', 'phone', 'mobile_number', 'mobile', 'cell_phone'] as $key) {
            $raw = trim((string) ($fields[$key] ?? ''));
            if ($raw === '') {
                continue;
            }
            $digits = preg_replace('/\D+/', '', $raw) ?? '';
            if (strlen($digits) >= 8) {
                return $digits;
            }
        }

        return '';
    }

    /**
     * @param array<string, string> $fields
     */
    public function nameFrom(array $fields): string
    {
        $full = trim((string) ($fields['full_name'] ?? $fields['name'] ?? ''));
        if ($full !== '') {
            return mb_substr($full, 0, 150);
        }

        $joined = trim((string) ($fields['first_name'] ?? '') . ' ' . (string) ($fields['last_name'] ?? ''));

        return $joined !== '' ? mb_substr($joined, 0, 150) : '';
    }

    /**
     * @param array<string, string> $fields
     */
    public function emailFrom(array $fields): string
    {
        $email = trim((string) ($fields['email'] ?? $fields['email_address'] ?? ''));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return '';
        }

        return mb_substr($email, 0, 191);
    }

    /**
     * @param array<string, string> $fields
     */
    protected function saveContact(string $phone, string $name, string $email, array $fields): int
    {
        if ($phone === '') {
            return 0;
        }

        $contacts = model(ContactModel::class);
        $contact  = $contacts->findOrCreateForChannel('whatsapp', $phone, [
            'name'   => $name !== '' ? $name : null,
            'email'  => $email !== '' ? $email : null,
            'mobile' => $phone,
        ]);
        $contactId = (int) ($contact['id'] ?? 0);
        if ($contactId < 1) {
            return 0;
        }

        $updates = [];
        if ($name !== '' && trim((string) ($contact['name'] ?? '')) === '') {
            $updates['name'] = $name;
        }
        if ($email !== '' && trim((string) ($contact['email'] ?? '')) === '') {
            $updates['email'] = $email;
        }

        $custom = is_array($contact['custom_fields'] ?? null) ? $contact['custom_fields'] : [];
        foreach ($fields as $key => $value) {
            $attr = $this->attributeKey($key);
            if ($attr === '' || in_array($attr, ['name', 'email', 'mobile', 'phone', 'phone_number'], true)) {
                continue;
            }
            $custom[$attr] = mb_substr($value, 0, 500);
        }
        if ($custom !== []) {
            $updates['custom_fields'] = $custom;
        }
        if ($updates !== []) {
            $contacts->update($contactId, $updates);
        }

        return $contactId;
    }

    protected function attributeKey(string $key): string
    {
        $key = strtolower(trim($key));
        $key = preg_replace('/[^a-z0-9_]+/', '_', $key) ?? '';
        $key = trim($key, '_');
        if ($key === '' || ! preg_match('/^[a-z]/', $key)) {
            $key = 'fb_' . $key;
        }
        $key = trim($key, '_');
        if ($key === '' || ! preg_match(ContactAttributeService::KEY_PATTERN, $key)) {
            return '';
        }

        return $key;
    }

    /**
     * @param array<string, string> $fields
     */
    protected function summary(array $fields): string
    {
        $lines = [];
        foreach ($fields as $key => $value) {
            $lines[] = $key . ': ' . $value;
        }

        return implode("\n", $lines);
    }
}
