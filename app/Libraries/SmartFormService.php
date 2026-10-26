<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\ContactModel;
use App\Models\SmartFormModel;
use App\Models\SmartFormResponseModel;
use RuntimeException;

/**
 * Shareable lead forms: save the definition, and turn a public submit into a contact.
 */
class SmartFormService
{
    public static function publicUrl(string $slug): string
    {
        $url    = site_url('f/' . rawurlencode($slug));
        $tenant = strtolower(trim((string) (\App\Libraries\TenantContext::get() ?? '')));
        if ($tenant !== '' && $tenant !== 'localhost') {
            $url .= '?t=' . rawurlencode($tenant);
        }

        return $url;
    }

    public static function recaptchaSiteKey(): string
    {
        try {
            return trim((string) service('settingsService')->get('recaptcha_site_key', ''));
        } catch (\Throwable) {
            return '';
        }
    }

    public static function recaptchaReady(): bool
    {
        return self::recaptchaSiteKey() !== '' && self::recaptchaSecret() !== '';
    }

    public static function assertRecaptcha(string $token, string $ip): void
    {
        if (! self::recaptchaReady()) {
            throw new RuntimeException('This form is not accepting responses yet.');
        }
        $token = trim($token);
        if ($token === '') {
            throw new RuntimeException('Please confirm you are not a robot.');
        }

        try {
            $client = \Config\Services::curlrequest([
                'timeout'     => 8,
                'http_errors' => false,
            ], null, null, false);
            $response = $client->post('https://www.google.com/recaptcha/api/siteverify', [
                'form_params' => [
                    'secret'   => self::recaptchaSecret(),
                    'response' => $token,
                    'remoteip' => $ip,
                ],
            ]);
            $decoded = json_decode((string) $response->getBody(), true);
        } catch (\Throwable) {
            throw new RuntimeException('Could not verify the captcha. Try again.');
        }

        if (! is_array($decoded) || empty($decoded['success'])) {
            throw new RuntimeException('Please confirm you are not a robot.');
        }
    }

    private static function recaptchaSecret(): string
    {
        try {
            return trim((string) service('settingsService')->get('recaptcha_secret_key', ''));
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{key:string,label:string,type:string,required:bool}>
     */
    public static function normalizeFields(array $rows): array
    {
        $out     = [];
        $seen    = [];
        $hasMobile = false;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $type = strtolower(trim((string) ($row['type'] ?? 'text')));
            if (! in_array($type, ['name', 'mobile', 'email', 'text'], true)) {
                $type = 'text';
            }
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                $label = match ($type) {
                    'name'   => 'Name',
                    'mobile' => 'Mobile',
                    'email'  => 'Email',
                    default  => 'Field',
                };
            }
            $key = strtolower(trim((string) ($row['key'] ?? '')));
            $key = preg_replace('/[^a-z0-9_]+/', '_', $key) ?? '';
            $key = trim($key, '_');
            if ($type === 'name') {
                $key = 'name';
            } elseif ($type === 'mobile') {
                $key = 'mobile';
            } elseif ($type === 'email') {
                $key = 'email';
            }
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if ($type === 'mobile') {
                $hasMobile = true;
            }
            $out[] = [
                'key'      => $key,
                'label'    => mb_substr($label, 0, 80),
                'type'     => $type,
                'required' => $type === 'mobile' || ! empty($row['required']),
            ];
            if (count($out) >= 12) {
                break;
            }
        }

        if (! $hasMobile) {
            array_unshift($out, [
                'key'      => 'mobile',
                'label'    => 'Mobile',
                'type'     => 'mobile',
                'required' => true,
            ]);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function save(array $input, ?int $id = null): int
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new RuntimeException('Form name is required (max 120 characters).');
        }

        $fields = self::normalizeFields(is_array($input['fields'] ?? null) ? $input['fields'] : []);
        $slug   = $this->uniqueSlug((string) ($input['slug'] ?? ''), $name, $id);
        $status = (string) ($input['status'] ?? 'active') === 'paused' ? 'paused' : 'active';
        $message = trim((string) ($input['success_message'] ?? ''));
        if ($message === '') {
            $message = 'Thank you. We have received your details.';
        }

        $data = [
            'name'            => $name,
            'slug'            => $slug,
            'status'          => $status,
            'success_message' => mb_substr($message, 0, 255),
            'fields'          => json_encode($fields, JSON_UNESCAPED_UNICODE),
        ];

        $model = model(SmartFormModel::class);
        if ($id === null || $id <= 0) {
            $data['created_by'] = (int) (session()->get('user_id') ?? 0) ?: null;
            $newId = $model->insert($data);
            if (! $newId) {
                throw new RuntimeException('Could not save the form.');
            }

            return (int) $newId;
        }

        if ($model->find($id) === null) {
            throw new RuntimeException('Form not found.');
        }
        $model->update($id, $data);

        return $id;
    }

    /**
     * @param array<string, mixed> $post
     *
     * @return array{contact_id:int,created:bool}
     */
    public function submit(array $form, array $post): array
    {
        if ((string) ($form['status'] ?? '') !== 'active') {
            throw new RuntimeException('This form is not accepting responses.');
        }

        SmartFormService::assertRecaptcha(
            (string) ($post['g-recaptcha-response'] ?? ''),
            (string) (service('request')->getIPAddress() ?? '')
        );

        $fields  = model(SmartFormModel::class)->fieldsOf($form);
        $answers = [];
        $name    = '';
        $mobile  = '';
        $email   = '';
        $countryName = '';
        $custom  = [];

        foreach ($fields as $field) {
            $key   = (string) ($field['key'] ?? '');
            $type  = (string) ($field['type'] ?? 'text');
            $value = trim((string) ($post['f'][$key] ?? $post[$key] ?? ''));
            if ($value === '' && ! empty($field['required'])) {
                throw new RuntimeException(($field['label'] ?? 'A field') . ' is required.');
            }
            if ($type === 'email' && $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                throw new RuntimeException('Enter a valid email address.');
            }
            if ($type === 'mobile') {
                $dial = preg_replace('/\D+/', '', (string) ($post['country_code'] ?? '')) ?? '';
                $checked = validate_phone_with_country($value, $dial !== '' ? $dial : '');
                if (! ($checked['valid'] ?? false)) {
                    throw new RuntimeException((string) ($checked['error'] ?? 'Enter a valid mobile number with country code.'));
                }
                $mobile = (string) $checked['phone'];
                $value  = $mobile;
                $countryName = (string) (($checked['country']['name'] ?? '') ?: '');
            } elseif ($type === 'name') {
                $name = mb_substr($value, 0, 150);
                $value = $name;
            } elseif ($type === 'email') {
                $email = mb_substr($value, 0, 191);
                $value = $email;
            } else {
                $custom[$key] = mb_substr($value, 0, 500);
            }
            if ($value !== '') {
                $answers[$key] = $value;
            }
        }

        if ($mobile === '') {
            throw new RuntimeException('Mobile is required.');
        }

        $contacts = model(ContactModel::class);
        $existing = $contacts->findByMobile($mobile, true);
        $created  = false;

        if (is_array($existing)) {
            $contactId = (int) $existing['id'];
            $update    = [];
            if ($name !== '') {
                $update['name'] = $name;
            }
            if ($email !== '') {
                $update['email'] = $email;
            }
            if ($countryName !== '') {
                $update['country'] = $countryName;
            }
            if ($custom !== []) {
                $current = is_array($existing['custom_fields'] ?? null) ? $existing['custom_fields'] : [];
                $update['custom_fields'] = array_merge($current, $custom);
            }
            if (! empty($existing['deleted_at'])) {
                $contacts->builder()->where('id', $contactId)->update(['deleted_at' => null]);
            }
            if ($update !== []) {
                $contacts->update($contactId, $update);
            }
        } else {
            $contactId = (int) $contacts->insert([
                'channel'       => 'whatsapp',
                'name'          => $name !== '' ? $name : $mobile,
                'mobile'        => $mobile,
                'email'         => $email !== '' ? $email : null,
                'country'       => $countryName !== '' ? $countryName : null,
                'status'        => 'active',
                'custom_fields' => $custom,
            ]);
            if ($contactId <= 0) {
                throw new RuntimeException('Could not save this contact.');
            }
            $created = true;
        }

        model(SmartFormResponseModel::class)->insert([
            'form_id'    => (int) ($form['id'] ?? 0),
            'contact_id' => $contactId,
            'payload'    => json_encode($answers, JSON_UNESCAPED_UNICODE),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $contact = $contacts->find($contactId) ?: [];
        if (is_array($contact) && $contact !== []) {
            service('whatsAppConsent')->optInIfMissing($contact, 'smart_form');
            $refreshed = $contacts->find($contactId);
            if (is_array($refreshed)) {
                $contact = $refreshed;
            }
        }
        try {
            service('automationEngine')->processTrigger('form_response', [
                'contact_id' => $contactId,
                'contact'    => $contact,
                'form_id'    => (int) ($form['id'] ?? 0),
                'form_name'  => (string) ($form['name'] ?? ''),
                'source'     => 'smart_form',
                'fields'     => $answers,
            ]);
            if ($created) {
                service('automationEngine')->processTrigger('contact_created', [
                    'contact_id' => $contactId,
                    'contact'    => $contact,
                    'source'     => 'smart_form',
                ]);
            }
        } catch (\Throwable $e) {
            log_message('error', 'Smart form automation error: {msg}', ['msg' => $e->getMessage()]);
        }

        return ['contact_id' => $contactId, 'created' => $created];
    }

    private function uniqueSlug(string $requested, string $name, ?int $ignoreId): string
    {
        $base = strtolower(trim($requested !== '' ? $requested : $name));
        $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?? '';
        $base = trim($base, '-');
        if ($base === '') {
            $base = 'form';
        }
        $base = mb_substr($base, 0, 70);

        $model = model(SmartFormModel::class);
        $slug  = $base;
        $n     = 2;
        while (true) {
            $found = $model->where('slug', $slug)->first();
            if ($found === null || ($ignoreId !== null && (int) ($found['id'] ?? 0) === $ignoreId)) {
                return $slug;
            }
            $slug = $base . '-' . $n;
            $n++;
            if ($n > 50) {
                return $base . '-' . bin2hex(random_bytes(3));
            }
        }
    }
}
