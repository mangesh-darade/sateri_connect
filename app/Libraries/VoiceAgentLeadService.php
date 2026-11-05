<?php

declare(strict_types=1);

namespace App\Libraries;

use Throwable;

/**
 * Send every new contact to the ElintOm AI voice agent (/leads/new) so it calls them within minutes.
 * Best effort: never blocks a contact save. Settings live in the tenant DB (Settings → AI Voice Agent).
 */
class VoiceAgentLeadService
{
    /** Why the last request failed ('' = reached the voice agent and got a JSON answer). */
    protected string $lastError = '';

    /**
     * The client's OWN settings, as saved (this is what the Settings screen shows and saves back).
     *
     * @return array{enabled: bool, url: string, api_key: string, consent_ok: bool, max_per_hour: int, use_platform: bool}
     */
    public function getConfig(): array
    {
        $s = service('settingsService');

        return [
            'enabled'      => (string) $s->get('voice_agent_enabled', '0') === '1',
            'url'          => rtrim(trim((string) $s->get('voice_agent_url', '')), '/'),
            'api_key'      => (string) $s->get('voice_agent_api_key', ''),
            'consent_ok'   => (string) $s->get('voice_agent_consent_ok', '0') === '1',
            'max_per_hour' => max(1, (int) $s->get('voice_agent_max_per_hour', '50')),
            'use_platform' => (string) $s->get('voice_agent_use_platform', '0') === '1',
        ];
    }

    /**
     * What calls actually use. A client that ticked "Use the platform's voice agent" and left the address or key blank
     * gets the platform's (Platform, AI voice agent). Never merged silently: without the tick a blank stays blank, so a
     * client can never start phoning new contacts just because the platform was set up.
     *
     * @return array{enabled: bool, url: string, api_key: string, consent_ok: bool, max_per_hour: int, use_platform: bool}
     */
    public function effective(): array
    {
        $c = $this->getConfig();
        if ($c['use_platform'] && ($c['url'] === '' || $c['api_key'] === '')) {
            try {
                $platform = $this->platform();
                $c['url']     = $c['url'] !== '' ? $c['url'] : $platform->url();
                $c['api_key'] = $c['api_key'] !== '' ? $c['api_key'] : $platform->leadsKey();
            } catch (Throwable) {
                // No master database: only the client's own values apply.
            }
        }

        return $c;
    }

    protected function platform(): VoiceAgentAdminService
    {
        return new VoiceAgentAdminService();
    }

    /** True when the platform admin has saved an address and a leads key (so "use the platform's voice agent" can work). */
    public function platformReady(): bool
    {
        try {
            $platform = $this->platform();

            return $platform->url() !== '' && $platform->leadsKey() !== '';
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public function setConfig(array $config): void
    {
        $s = service('settingsService');
        if (array_key_exists('enabled', $config)) {
            $s->set('voice_agent_enabled', $config['enabled'] ? '1' : '0', 'voice_agent', false);
        }
        if (array_key_exists('url', $config)) {
            $s->set('voice_agent_url', rtrim(trim((string) $config['url']), '/'), 'voice_agent', false);
        }
        if (array_key_exists('use_platform', $config)) {
            $s->set('voice_agent_use_platform', $config['use_platform'] ? '1' : '0', 'voice_agent', false);
        }
        if (array_key_exists('consent_ok', $config)) {
            $s->set('voice_agent_consent_ok', $config['consent_ok'] ? '1' : '0', 'voice_agent', false);
        }
        if (array_key_exists('max_per_hour', $config)) {
            $s->set('voice_agent_max_per_hour', (string) max(1, (int) $config['max_per_hour']), 'voice_agent', false);
        }
        if (array_key_exists('api_key', $config)) {
            $key = trim((string) $config['api_key']);
            if ($key !== '' && ! str_contains($key, '•')) {
                $s->set('voice_agent_api_key', $key, 'voice_agent', true);
            }
        }
    }

    /** On, URL + key saved, and the owner confirmed contacts agreed to be called. */
    public function isActive(): bool
    {
        $c = $this->effective();

        return $c['enabled'] && $c['consent_ok'] && $c['url'] !== '' && $c['api_key'] !== '';
    }

    public function lastError(): string
    {
        return $this->lastError;
    }

    /**
     * Send one new contact. `consent: true` is sent only because the owner confirmed it in settings.
     *
     * @param array<string, mixed> $contact
     */
    public function push(array $contact): void
    {
        if (! $this->isActive() || ! empty($contact['archived_at'])) {
            return;
        }

        try {
            $mobile = normalize_phone((string) ($contact['mobile'] ?? ''));
            if ($mobile === '' || preg_match('/^0+$/', preg_replace('/\D+/', '', $mobile) ?? '') === 1) {
                return; // no real phone number to call
            }
            if (! $this->withinHourlyCap()) {
                log_message('warning', 'Voice agent: hourly cap reached, contact {id} not sent.', ['id' => $contact['id'] ?? '?']);

                return;
            }

            $cfg = $this->effective();
            $this->request('POST', $cfg['url'] . '/leads/new', [
                'name'    => (string) ($contact['name'] ?? ''),
                'phone'   => $mobile,
                'email'   => (string) ($contact['email'] ?? ''),
                'source'  => 'sateri_connect',
                'consent' => true,
            ], 5);
            if ($this->lastError !== '') {
                log_message('warning', 'Voice agent push failed for contact {id}: {err}', ['id' => $contact['id'] ?? '?', 'err' => $this->lastError]);
            }
        } catch (Throwable $e) {
            log_message('error', 'Voice agent push failed: ' . $e->getMessage());
        }
    }

    /**
     * Ask the AI voice agent to call one contact (used by the workflow "AI call" node). The voice agent keeps to its own
     * calling hours. Refuses unless the voice agent is switched on and the owner confirmed consent in Settings, the
     * contact has a real phone and has not opted out, and the contact was not already sent in the last 24 hours.
     *
     * @param array<string, mixed> $contact
     *
     * @return array{ok: bool, message: string, code: string} code: ok, off, archived, opted_out, no_phone, already, cap, unreachable, agent_error, dnc, duplicate or no_consent
     */
    public function callNow(array $contact, string $source = 'workflow'): array
    {
        if (! $this->isActive()) {
            return ['ok' => false, 'message' => 'The AI voice agent is not switched on (Settings, AI Voice Agent).', 'code' => 'off'];
        }
        if (! empty($contact['archived_at'])) {
            return ['ok' => false, 'message' => 'The contact is archived.', 'code' => 'archived'];
        }
        if (! empty($contact['wa_opted_out_at'])) {
            return ['ok' => false, 'message' => 'The contact opted out, so no call was requested.', 'code' => 'opted_out'];
        }
        $mobile = normalize_phone((string) ($contact['mobile'] ?? ''));
        if ($mobile === '' || preg_match('/^0+$/', preg_replace('/\D+/', '', $mobile) ?? '') === 1) {
            return ['ok' => false, 'message' => 'The contact has no phone number to call.', 'code' => 'no_phone'];
        }

        $cache = cache();
        $key   = 'voice_agent_call_' . md5((string) db_connect()->getDatabase()) . '_' . (int) ($contact['id'] ?? 0);
        if ($cache->get($key)) {
            return ['ok' => false, 'message' => 'A call was already requested for this contact in the last 24 hours.', 'code' => 'already'];
        }
        if (! $this->withinHourlyCap()) {
            return ['ok' => false, 'message' => 'The hourly call limit is reached. The call was not requested.', 'code' => 'cap'];
        }

        $cfg = $this->effective();
        try {
            $answer = $this->request('POST', $cfg['url'] . '/leads/new', [
                'name'    => (string) ($contact['name'] ?? ''),
                'phone'   => $mobile,
                'email'   => (string) ($contact['email'] ?? ''),
                'source'  => mb_substr('sateri_connect:' . trim($source), 0, 60),
                'consent' => true,
            ], 5);
        } catch (Throwable $e) {
            log_message('error', 'Voice agent call request failed: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'The voice agent could not be reached.', 'code' => 'unreachable'];
        }
        if ($this->lastError !== '') {
            return ['ok' => false, 'message' => 'The voice agent said: ' . $this->lastError, 'code' => 'agent_error'];
        }

        // The voice agent answers 200 even when it will not call, so read its status.
        $status = strtolower((string) ($answer['status'] ?? ''));
        if ($status === 'dnc') {
            return ['ok' => false, 'message' => 'The number is on the voice agent do-not-call list, so no call will be made.', 'code' => 'dnc'];
        }
        $cache->save($key, 1, 86400);
        if ($status === 'duplicate') {
            return ['ok' => false, 'message' => 'This number is already in the voice agent call list, so it will not be called twice.', 'code' => 'duplicate'];
        }
        if ($status === 'no_consent') {
            return ['ok' => false, 'message' => 'The voice agent did not accept the contact (no consent).', 'code' => 'no_consent'];
        }

        return ['ok' => true, 'message' => 'The AI voice agent will call the contact.', 'code' => 'ok'];
    }

    /**
     * Check URL + key without creating a lead.
     *
     * @return array{ok: bool, message: string}
     */
    public function testConnection(): array
    {
        $cfg = $this->effective();
        if ($cfg['url'] === '' || $cfg['api_key'] === '') {
            return ['ok' => false, 'message' => 'Voice agent URL and API key are required.'];
        }
        $this->request('GET', $cfg['url'] . '/leads/ping', null, 8);

        return $this->lastError === ''
            ? ['ok' => true, 'message' => 'Connected to the voice agent.']
            : ['ok' => false, 'message' => 'Voice agent: ' . $this->lastError];
    }

    /** Safety net so a bulk create cannot make the agent call hundreds of people at once. */
    protected function withinHourlyCap(): bool
    {
        $cache = cache();
        $key   = 'voice_agent_sent_' . date('YmdH');
        $sent  = (int) $cache->get($key);
        if ($sent >= $this->effective()['max_per_hour']) {
            return false;
        }
        $cache->save($key, $sent + 1, 3700);

        return true;
    }

    /**
     * @param array<string, mixed>|null $json
     *
     * @return array<string, mixed>
     */
    protected function request(string $method, string $url, ?array $json, int $timeout): array
    {
        $this->lastError = '';
        $cfg = $this->effective();

        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-API-Key: ' . $cfg['api_key']],
        ];
        if ($json !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opts);
        $raw     = curl_exec($ch);
        $status  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if ($curlErr !== '') {
            $this->lastError = 'network error: ' . $curlErr;
        } elseif ($status === 401) {
            $this->lastError = 'wrong API key';
        } elseif ($status < 200 || $status >= 300) {
            $this->lastError = 'HTTP ' . $status;
        } elseif (! is_array($decoded)) {
            $this->lastError = 'no JSON answer';
        }

        return is_array($decoded) ? $decoded : [];
    }
}
