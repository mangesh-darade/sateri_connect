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
     * @return array{enabled: bool, url: string, api_key: string, consent_ok: bool, max_per_hour: int}
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
        ];
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
        $c = $this->getConfig();

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

            $cfg = $this->getConfig();
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
     * Check URL + key without creating a lead.
     *
     * @return array{ok: bool, message: string}
     */
    public function testConnection(): array
    {
        $cfg = $this->getConfig();
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
        if ($sent >= $this->getConfig()['max_per_hour']) {
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
        $cfg = $this->getConfig();

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
