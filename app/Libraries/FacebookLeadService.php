<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\ContactModel;
use App\Models\TagModel;
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
     * Used when platform shares one Meta webhook URL across all tenants.
     *
     * @return bool True when a tenant with this page_id was selected
     */
    public function useTenantForPage(string $pageId, bool $trusted = true): bool
    {
        $pageId = trim($pageId);
        if ($pageId === '' || preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $pageId) !== 1 || ! MasterTenantRepository::masterConfigured()) {
            return false;
        }

        if ($this->currentTenantHasPage($pageId)) {
            return true;
        }

        $connection = new TenantConnection();
        $snapshot   = $connection->capture();

        // Fast path: the tenant we resolved for this page recently (verified again below).
        $cacheKey  = 'fb_page_tenant_' . sha1($pageId);
        $cachedKey = '';
        try {
            $cachedKey = (string) cache($cacheKey);
        } catch (Throwable) {
            $cachedKey = '';
        }
        if ($cachedKey !== '') {
            if ($connection->apply($cachedKey, 'webhook') && $this->currentTenantHasPage($pageId)) {
                return true;
            }
            $connection->restore($snapshot);
        }

        // A full scan opens every tenant database; cap it for requests that are not platform-signed.
        if (! $trusted) {
            try {
                $ip = (string) service('request')->getIPAddress();
                if (service('throttler')->check('fb_page_scan_' . md5($ip), 10, MINUTE) === false) {
                    return false;
                }
            } catch (Throwable) {
                // throttler unavailable: continue
            }
        }

        $matches = [];
        foreach ((new MasterTenantRepository())->listActiveTenants(null) as $row) {
            $key = strtolower(trim((string) ($row['key'] ?? '')));
            if ($key === '' || $key === ($snapshot['key'] ?? null)) {
                continue;
            }
            if ($connection->apply($key, 'webhook_scan') && $this->currentTenantHasPage($pageId)) {
                $matches[] = $key;
            }
        }

        // Two tenants claiming one Page id is ambiguous (or a hijack attempt): never guess.
        if (count($matches) !== 1) {
            if (count($matches) > 1) {
                log_message('warning', 'Facebook page {page} is configured in more than one tenant; not routing.', ['page' => $pageId]);
            }
            $connection->restore($snapshot);

            return false;
        }

        if (! $connection->apply($matches[0], 'webhook')) {
            $connection->restore($snapshot);

            return false;
        }

        try {
            cache()->save($cacheKey, $matches[0], 600);
        } catch (Throwable) {
            // cache is only an optimisation
        }

        return true;
    }

    protected function currentTenantHasPage(string $pageId): bool
    {
        try {
            \CodeIgniter\Config\Factories::reset('models');
            $found = trim((string) (model(\App\Models\SettingModel::class, false)->getValue('meta_page_id', '') ?? ''));
        } catch (Throwable) {
            return false;
        }

        return $found !== '' && $found === $pageId;
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
        if ($leadId === '' || preg_match('/^\d{5,32}$/', $leadId) !== 1) {
            return ['ok' => false, 'message' => 'Facebook lead id is missing or invalid.'];
        }

        // Never leave the process switched to another tenant after this lead is handled.
        $connection = new TenantConnection();
        $snapshot   = $connection->capture();

        try {
            $this->useTenantForPage($pageId);

            // Scoped per tenant: the same lead id must not suppress another tenant's lead.
            $cacheKey = 'fb_lead_' . sha1((TenantContext::get() ?? 'default') . ':' . $leadId);
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
        } finally {
            $connection->restoreIfChanged($snapshot);
        }
    }

    /**
     * @param array<string, mixed> $lead Graph lead object
     * @param array<string, mixed> $value Webhook change.value
     *
     * @return array{ok: bool, contact_id: int, matched: int, message?: string}
     */
    public function applyLead(array $lead, array $value = []): array
    {
        $fields       = $this->fieldsFromLead($lead);
        $phone        = $this->phoneFrom($fields);
        $name         = $this->nameFrom($fields);
        $email        = $this->emailFrom($fields);
        $leadId       = preg_replace('/[^\d]/', '', (string) ($lead['id'] ?? $value['leadgen_id'] ?? $value['lead_id'] ?? '')) ?? '';
        $formId       = mb_substr(trim((string) ($lead['form_id'] ?? $value['form_id'] ?? '')), 0, 64);
        $adId         = mb_substr(trim((string) ($lead['ad_id'] ?? $value['ad_id'] ?? $value['adgroup_id'] ?? '')), 0, 64);
        $campaignId   = mb_substr(trim((string) ($lead['campaign_id'] ?? $value['campaign_id'] ?? '')), 0, 64);
        $campaignName = mb_substr(trim((string) ($lead['campaign_name'] ?? $value['campaign_name'] ?? '')), 0, 191);
        $adName       = mb_substr(trim((string) ($lead['ad_name'] ?? $value['ad_name'] ?? '')), 0, 191);
        $pageId       = mb_substr(trim((string) ($value['page_id'] ?? '')), 0, 64);

        $saved      = $this->saveContact(
            $phone,
            $name,
            $email,
            $fields,
            $leadId,
            $campaignName,
            $adName,
            $formId,
            $adId
        );
        $contactId  = (int) ($saved['contact_id'] ?? 0);
        $wasCreated = (bool) ($saved['was_created'] ?? false);

        if ($contactId < 1) {
            log_message('warning', 'Facebook lead #{id} discarded: no valid phone or email found.', ['id' => $leadId]);

            return [
                'ok'         => false,
                'contact_id' => 0,
                'matched'    => 0,
                'message'    => 'No valid phone number or email found in lead data.',
            ];
        }

        $context = [
            'contact_id'    => $contactId,
            'phone'         => $phone,
            'mobile'        => $phone,
            'name'          => $name,
            'email'         => $email,
            'is_new'        => $wasCreated ? 1 : 0,
            'is_existing'   => $wasCreated ? 0 : 1,
            'form_id'       => $formId,
            'ad_id'         => $adId,
            'ad_name'       => $adName,
            'page_id'       => $pageId,
            'leadgen_id'    => $leadId,
            'campaign_id'   => $campaignId,
            'campaign_name' => $campaignName,
            'content'       => $this->summary($fields),
            'fields'        => $fields,
        ];

        // If this is a newly created contact, also fire contact_created automations
        if ($wasCreated) {
            try {
                service('automationEngine')->processTrigger('contact_created', [
                    'contact_id' => $contactId,
                    'phone'      => $phone,
                    'mobile'     => $phone,
                    'name'       => $name,
                    'email'      => $email,
                    'channel'    => 'whatsapp',
                    'source'     => 'facebook_lead',
                ]);
            } catch (Throwable $cte) {
                log_message('warning', 'contact_created trigger for FB lead failed: {msg}', ['msg' => $cte->getMessage()]);
            }
        }

        $run = service('automationEngine')->processTrigger('facebook_lead', $context);

        log_activity('create', 'automations', 'Facebook lead started workflow' . ($name !== '' ? ' for ' . $name : ''), [
            'leadgen_id'    => $leadId,
            'form_id'       => $formId,
            'page_id'       => $pageId,
            'contact_id'    => $contactId,
            'ad_name'       => $adName,
            'campaign_name' => $campaignName,
            'matched'       => (int) ($run['matched'] ?? 0),
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
        $preferred = [
            'phone_number', 'phone', 'mobile_number', 'mobile', 'cell_phone',
            'whatsapp_number', 'whatsapp', 'wa_number', 'contact_number', 'telephone',
        ];
        foreach ($preferred as $key) {
            $raw = trim((string) ($fields[$key] ?? ''));
            $digits = preg_replace('/\D+/', '', $raw) ?? '';
            if (strlen($digits) >= 8 && strlen($digits) <= 16) {
                return $digits;
            }
        }

        // Custom form labels: any field whose key looks like a phone.
        foreach ($fields as $key => $raw) {
            $key = strtolower((string) $key);
            if (! str_contains($key, 'phone') && ! str_contains($key, 'mobile') && ! str_contains($key, 'whatsapp')) {
                continue;
            }
            $digits = preg_replace('/\D+/', '', (string) $raw) ?? '';
            if (strlen($digits) < 8 || strlen($digits) > 16) {
                continue;
            }

            // Normalization for Indian mobile numbers:
            // 1) 10-digit standard Indian mobile starting with 6, 7, 8, or 9: prepend 91
            if (strlen($digits) === 10 && preg_match('/^[6-9]\d{9}$/', $digits)) {
                $digits = '91' . $digits;
            } elseif (strlen($digits) === 11 && str_starts_with($digits, '0') && preg_match('/^0[6-9]\d{9}$/', $digits)) {
                // 2) 11-digit starting with 0 (e.g. 09822012345): replace 0 with 91
                $digits = '91' . substr($digits, 1);
            }

            return $digits;
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
     *
     * @return array{contact_id: int, was_created: bool}
     */
    protected function saveContact(
        string $phone,
        string $name,
        string $email,
        array $fields,
        string $leadId = '',
        string $campaignName = '',
        string $adName = '',
        string $formId = '',
        string $adId = ''
    ): array {
        if ($phone === '' && $email === '') {
            return ['contact_id' => 0, 'was_created' => false];
        }

        $contacts   = model(ContactModel::class);
        $contactId  = 0;
        $contact    = null;
        $wasCreated = false;

        $db = db_connect();
        $db->transStart();

        try {
            if ($phone !== '') {
                $contact = $contacts->findOrCreateForChannel('whatsapp', $phone, [
                    'name'             => $name !== '' ? $name : null,
                    'email'            => $email !== '' ? $email : null,
                    'mobile'           => $phone,
                ], $wasCreated);
                $contactId = (int) ($contact['id'] ?? 0);
            } elseif ($email !== '') {
                // Email-only lead: check if contact exists by email
                $existing = $contacts->where('email', $email)
                    ->where('deleted_at', null)
                    ->first();

                if ($existing !== null) {
                    $contactId  = (int) $existing['id'];
                    $contact    = $existing;
                    $wasCreated = false;
                } else {
                    $insertData = [
                        'channel'          => 'whatsapp',
                        'name'             => $name !== '' ? $name : null,
                        'email'            => $email,
                        'mobile'           => null,
                        'status'           => 'active',
                        'wa_opt_in'        => 0,
                        'wa_opt_in_source' => 'facebook_lead',
                    ];
                    $contactId  = (int) $contacts->insert($insertData);
                    $contact    = $contacts->find($contactId);
                    $wasCreated = true;
                }
            }

            if ($contactId < 1 || ! is_array($contact)) {
                $db->transRollback();

                return ['contact_id' => 0, 'was_created' => false];
            }

            $updates = [];
            if ($name !== '' && trim((string) ($contact['name'] ?? '')) === '') {
                $updates['name'] = $name;
            }
            if ($email !== '' && trim((string) ($contact['email'] ?? '')) === '') {
                $updates['email'] = $email;
            }
            $custom = is_array($contact['custom_fields'] ?? null) ? $contact['custom_fields'] : [];

            // Store metadata safely
            if ($campaignName !== '') {
                $custom['fb_campaign_name'] = mb_substr($campaignName, 0, 255);
            }
            if ($adName !== '') {
                $custom['fb_ad_name'] = mb_substr($adName, 0, 255);
            }
            if ($formId !== '') {
                $custom['fb_form_id'] = mb_substr($formId, 0, 100);
            }
            if ($adId !== '') {
                $custom['fb_ad_id'] = mb_substr($adId, 0, 100);
            }
            if ($leadId !== '') {
                $custom['fb_lead_id'] = mb_substr($leadId, 0, 100);
            }

            // Store form question fields
            foreach ($fields as $key => $value) {
                $attr = $this->attributeKey($key);
                if ($attr === '' || in_array($attr, ['name', 'email', 'mobile', 'phone', 'phone_number'], true)) {
                    continue;
                }
                // Limit value to 500 chars, strip dangerous control characters
                $cleanValue = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $value) ?? (string) $value;
                $custom[$attr] = mb_substr(trim($cleanValue), 0, 500);
            }

            if ($custom !== []) {
                $updates['custom_fields'] = $custom;
            }
            if ($updates !== []) {
                $contacts->update($contactId, $updates);
            }

            // Automatically attach "Facebook Lead" customer group (tag)
            try {
                $tagModel = model(TagModel::class);
                $tag      = $tagModel->findOrCreateByName('Facebook Lead', '#1877F2');
                if (! empty($tag['id'])) {
                    $tagModel->attachContact((int) $tag['id'], $contactId);
                }
            } catch (Throwable $te) {
                log_message('warning', 'Attaching Facebook Lead tag failed: {msg}', ['msg' => $te->getMessage()]);
            }

            $db->transComplete();

            if ($db->transStatus() === false) {
                return ['contact_id' => 0, 'was_created' => false];
            }

            // Consent service records the opt-in and never overrides an existing STOP / opt-out.
            if ($phone !== '') {
                try {
                    $fresh = $contacts->find($contactId);
                    if (is_array($fresh)) {
                        service('whatsAppConsent')->optInIfMissing($fresh, 'facebook_lead');
                    }
                } catch (Throwable $ce) {
                    log_message('warning', 'Facebook lead opt-in failed: {msg}', ['msg' => $ce->getMessage()]);
                }
            }

            return ['contact_id' => $contactId, 'was_created' => $wasCreated];
        } catch (Throwable $e) {
            $db->transRollback();
            log_message('error', 'saveContact failed: {msg}', ['msg' => $e->getMessage()]);

            return ['contact_id' => 0, 'was_created' => false];
        }
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
            $key   = mb_substr(trim((string) $key), 0, 50);
            $value = mb_substr(trim((string) $value), 0, 255);
            if ($key !== '') {
                $lines[] = $key . ': ' . $value;
            }
        }

        return implode("\n", $lines);
    }
}
