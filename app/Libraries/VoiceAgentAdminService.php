<?php

declare(strict_types=1);

namespace App\Libraries;

use Throwable;

/**
 * Platform level control of the ElintOm AI voice agent (the separate calling server).
 *
 * Sateri Connect stores only how to reach it (URL, dashboard login, leads key, in the master DB platform_settings,
 * secrets encrypted). Everything else (SIP, Exotel, n8n webhooks, lead pipeline timing, S3, and the agent's script,
 * language, voice and calling hours) is read and written through the voice agent's own dashboard API, so it lives in one
 * place. Secrets that the voice agent holds are never sent back to the browser, only whether they are set.
 *
 * LiveKit, Deepgram, Groq and Sarvam keys are read by the voice agent from its server .env and are not exposed by its
 * API, so they are not editable here.
 *
 * Services return ['ok', 'status', 'message', 'data'?].
 */
class VoiceAgentAdminService
{
    public const URL      = 'voice_agent_url';
    public const USERNAME = 'voice_agent_username';
    public const PASSWORD = 'voice_agent_password';
    public const LEADS    = 'voice_agent_leads_key';

    private MasterTenantRepository $master;

    public function __construct(?MasterTenantRepository $master = null)
    {
        $this->master = $master ?? new MasterTenantRepository();
    }

    private function secret(string $key): string
    {
        $raw = $this->master->getPlatformSetting($key, '');
        if ($raw === '') {
            return '';
        }
        try {
            return (new EncryptionService())->decryptIfNeeded($raw);
        } catch (Throwable) {
            return '';
        }
    }

    public function url(): string
    {
        return rtrim(trim($this->master->getPlatformSetting(self::URL, '')), '/');
    }

    /** The key Sateri Connect sends as X-API-Key when it hands leads to the voice agent (used by clients with no key of their own). */
    public function leadsKey(): string
    {
        return $this->secret(self::LEADS);
    }

    /**
     * @return array{url: string, username: string, has_password: bool, has_leads_key: bool}
     */
    public function config(): array
    {
        return [
            'url'           => $this->url(),
            'username'      => trim($this->master->getPlatformSetting(self::USERNAME, '')),
            'has_password'  => $this->secret(self::PASSWORD) !== '',
            'has_leads_key' => $this->leadsKey() !== '',
        ];
    }

    /**
     * @param array<string, mixed> $in url, username, password (blank keeps the saved one), leads_key (blank keeps)
     *
     * @return array{ok: bool, status: int, message: string}
     */
    public function saveConnection(array $in): array
    {
        $url = rtrim(trim((string) ($in['url'] ?? '')), '/');
        if ($url !== '') {
            $p = parse_url($url);
            if (! is_array($p) || ! in_array(strtolower((string) ($p['scheme'] ?? '')), ['http', 'https'], true) || empty($p['host']) || isset($p['user'])) {
                return $this->err(422, 'Enter the voice agent address, for example https://voice.yourdomain.com');
            }
            $check = $this->pin($url);
            if (! $check['ok']) {
                return $this->err(422, $check['message']);
            }
        }

        $this->master->setPlatformSetting(self::URL, $url);
        $this->master->setPlatformSetting(self::USERNAME, mb_substr(trim((string) ($in['username'] ?? '')), 0, 100));
        $password = (string) ($in['password'] ?? '');
        if ($password !== '') {
            $this->master->setPlatformSetting(self::PASSWORD, $password, true);
        }
        $leads = trim((string) ($in['leads_key'] ?? ''));
        if ($leads !== '') {
            $this->master->setPlatformSetting(self::LEADS, $leads, true);
        }
        cache()->delete($this->tokenKey());

        return ['ok' => true, 'status' => 200, 'message' => 'Voice agent connection saved.'];
    }

    /**
     * Checks the address and the leads key without creating a lead.
     *
     * @return array{ok: bool, status: int, message: string}
     */
    public function ping(): array
    {
        $url = $this->url();
        if ($url === '' || $this->leadsKey() === '') {
            return $this->err(422, 'Save the voice agent address and the leads key first.');
        }
        $r = $this->http('GET', $url . '/leads/ping', null, ['X-API-Key: ' . $this->leadsKey()], 8);

        return $r['ok'] ? ['ok' => true, 'status' => 200, 'message' => 'Connected to the voice agent.'] : $this->err($r['status'] ?: 502, $r['message']);
    }

    /**
     * Everything the page shows: the server settings (secrets as set / not set), and the agent's own settings.
     *
     * @return array{ok: bool, status: int, message: string, data?: array<string, mixed>}
     */
    public function load(): array
    {
        $settings = $this->call('GET', '/api/settings');
        if (! $settings['ok']) {
            return $settings;
        }
        $me = $this->call('GET', '/api/me');
        if (! $me['ok']) {
            return $me;
        }

        $accounts = $this->accounts();

        return ['ok' => true, 'status' => 200, 'message' => 'Loaded.', 'data' => [
            'accounts' => $accounts['data'] ?? [],
            'settings' => $settings['data'],
            'agent'    => $me['data']['agent'] ?? [],
            'tenant'   => $me['data']['tenant'] ?? [],
            'effective' => $me['data']['effective'] ?? [],
        ]];
    }

    /**
     * @param array<string, mixed> $values key => value; a blank secret keeps the stored one (the voice agent does that)
     *
     * @return array{ok: bool, status: int, message: string}
     */
    public function saveServerSettings(array $values): array
    {
        $clean = [];
        foreach ($values as $k => $v) {
            if (is_string($k) && preg_match('/^[A-Z0-9_]{3,64}$/', $k) === 1 && is_scalar($v)) {
                $clean[$k] = (string) $v;
            }
        }
        if ($clean === []) {
            return $this->err(422, 'Nothing to save.');
        }
        $r = $this->call('PUT', '/api/settings', $clean);

        return $r['ok'] ? ['ok' => true, 'status' => 200, 'message' => 'Voice agent settings saved.'] : $r;
    }

    /**
     * @param array<string, mixed> $in name, prompt, language, stt_provider, tts_provider, speaker, llm_model, phone_number,
     *                                 calling_hours, country, timezone, recording_enabled
     *
     * @return array{ok: bool, status: int, message: string}
     */
    public function saveAgent(array $in): array
    {
        $body = [
            'name'              => mb_substr(trim((string) ($in['name'] ?? '')), 0, 120) ?: 'Sateri sales assistant',
            'prompt'            => mb_substr((string) ($in['prompt'] ?? ''), 0, 20000),
            'language'          => (string) ($in['language'] ?? ''),
            'stt_provider'      => (string) ($in['stt_provider'] ?? ''),
            'tts_provider'      => (string) ($in['tts_provider'] ?? ''),
            'speaker'           => mb_substr(trim((string) ($in['speaker'] ?? '')), 0, 60),
            'llm_model'         => mb_substr(trim((string) ($in['llm_model'] ?? '')), 0, 120),
            'phone_number'      => mb_substr(trim((string) ($in['phone_number'] ?? '')), 0, 30),
            'calling_hours'     => trim((string) ($in['calling_hours'] ?? '09:00-20:00')),
            'country'           => strtoupper(mb_substr(trim((string) ($in['country'] ?? 'IN')), 0, 2)) ?: 'IN',
            'timezone'          => mb_substr(trim((string) ($in['timezone'] ?? 'Asia/Kolkata')), 0, 60) ?: 'Asia/Kolkata',
            'recording_enabled' => (string) ($in['recording_enabled'] ?? '0') === '1',
        ];
        if (preg_match('/^\d\d:\d\d-\d\d:\d\d$/', $body['calling_hours']) !== 1) {
            return $this->err(422, 'Calling hours must look like 09:00-20:00.');
        }
        $r = $this->call('PUT', '/api/agent', $body);

        return $r['ok'] ? ['ok' => true, 'status' => 200, 'message' => 'Agent settings saved.'] : $r;
    }

    /**
     * Creates a new leads key, applies it on the voice agent and saves it here, so nothing is copied by hand.
     *
     * @return array{ok: bool, status: int, message: string}
     */
    public function generateLeadsKey(): array
    {
        $key = bin2hex(random_bytes(20));
        $r   = $this->call('PUT', '/api/settings', ['LEADS_API_KEY' => $key]);
        if (! $r['ok']) {
            return $r;
        }
        $this->master->setPlatformSetting(self::LEADS, $key, true);

        return ['ok' => true, 'status' => 200, 'message' => 'A new leads key was created and applied on the voice agent and here.'];
    }

    /**
     * The voice agent accounts that exist (the platform owner is the first one).
     *
     * @return array{ok: bool, status: int, message: string, data?: list<array<string, mixed>>}
     */
    public function accounts(): array
    {
        $r = $this->call('GET', '/api/tenants');

        return $r['ok'] ? ['ok' => true, 'status' => 200, 'message' => 'Loaded.', 'data' => is_array($r['data']) ? $r['data'] : []] : $r;
    }

    /**
     * A voice agent account of its own for one client: its own script, calling hours and do-not-call list, and its own
     * leads key. Creates the account (or reuses the one named for this client), then makes a new leads key for it.
     * The login password is returned only when the account was just created; the key is returned once.
     *
     * @return array{ok: bool, status: int, message: string, data?: array{tenant_id: int, username: string, password: string, key: string}}
     */
    public function createClientAccount(string $clientKey, string $clientName): array
    {
        $username = mb_substr('c_' . preg_replace('/[^a-z0-9_]/', '', strtolower($clientKey)), 0, 60);
        if ($username === 'c_') {
            return $this->err(422, 'The client has no usable key.');
        }
        $password = bin2hex(random_bytes(10));

        $created = $this->call('POST', '/api/tenants', [
            'name'     => mb_substr(trim($clientName) !== '' ? trim($clientName) : $clientKey, 0, 120),
            'username' => $username,
            'password' => $password,
            'country'  => 'IN',
        ]);
        $tenantId = (int) ($created['data']['id'] ?? 0);
        if (! $created['ok']) {
            if (stripos($created['message'], 'Username taken') === false) {
                return $created;
            }
            // Already made for this client: keep its login, just make a fresh leads key.
            $password = '';
            $all      = $this->accounts();
            foreach (($all['data'] ?? []) as $a) {
                if (($a['username'] ?? '') === $username) {
                    $tenantId = (int) $a['id'];
                }
            }
        }
        if ($tenantId <= 0) {
            return $this->err(502, 'The voice agent account could not be found or created.');
        }

        $key = $this->call('POST', '/api/tenants/' . $tenantId . '/leads-key', []);
        if (! $key['ok']) {
            return $key;
        }

        return ['ok' => true, 'status' => 200, 'message' => $password !== '' ? 'Voice agent account created.' : 'The account already existed; a new leads key was made.', 'data' => [
            'tenant_id' => $tenantId,
            'username'  => $username,
            'password'  => $password,
            'key'       => (string) ($key['data']['key'] ?? ''),
        ]];
    }

    /** @return array{ok: bool, status: int, message: string, data?: mixed} */
    public function sipSetup(): array
    {
        $r = $this->call('POST', '/api/settings/sip-setup', [], 40);

        return $r['ok'] ? ['ok' => true, 'status' => 200, 'message' => 'SIP trunks created.', 'data' => $r['data']] : $r;
    }

    /** @return array{ok: bool, status: int, message: string, data?: mixed} */
    public function testWebhook(string $key): array
    {
        $r = $this->call('POST', '/api/settings/test-webhook', ['key' => $key]);
        if (! $r['ok']) {
            return $r;
        }
        $d = is_array($r['data']) ? $r['data'] : [];

        return ['ok' => ! empty($d['ok']), 'status' => ! empty($d['ok']) ? 200 : 502, 'message' => (string) ($d['detail'] ?? 'Done.')];
    }

    // -------------------------------------------------------------------- transport

    /**
     * The address is typed by an admin but fetched by this server, so it is checked every time it is used:
     * https unless the voice agent is on this same computer, and never a link-local address (cloud metadata such as
     * 169.254.169.254), an unspecified or a multicast address. The name is resolved once here and the request is pinned
     * to that address, so the name cannot be switched to another address between the check and the request.
     * LAN and private addresses are allowed on purpose: a voice agent in the same data centre is a normal setup.
     *
     * @return array{ok: bool, message: string, resolve?: list<string>}
     */
    private function pin(string $url): array
    {
        $p      = parse_url($url);
        $scheme = strtolower((string) ($p['scheme'] ?? ''));
        $host   = strtolower((string) ($p['host'] ?? ''));
        $port   = (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80));
        $local  = in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true);

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($p['user'])) {
            return ['ok' => false, 'message' => 'Enter the voice agent address, for example https://voice.yourdomain.com'];
        }
        if ($scheme !== 'https' && ! $local) {
            return ['ok' => false, 'message' => 'Use https for a voice agent that is not on this computer.'];
        }
        if ($local) {
            return ['ok' => true, 'message' => 'OK'];
        }

        $bare = trim($host, '[]');
        $ips  = filter_var($bare, FILTER_VALIDATE_IP) !== false ? [$bare] : (gethostbynamel($bare) ?: []);
        if ($ips === []) {
            return ['ok' => false, 'message' => 'The voice agent address does not resolve. Check the name.'];
        }
        foreach ($ips as $ip) {
            $blocked = ! filter_var($ip, FILTER_VALIDATE_IP)
                || str_starts_with($ip, '169.254.') || str_starts_with(strtolower($ip), 'fe80:')
                || $ip === '0.0.0.0' || $ip === '::'
                || (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && (int) explode('.', $ip)[0] >= 224);
            if ($blocked) {
                return ['ok' => false, 'message' => 'That address is not allowed for a voice agent.'];
            }
        }

        return ['ok' => true, 'message' => 'OK', 'resolve' => [$bare . ':' . $port . ':' . $ips[0]]];
    }

    private function tokenKey(): string
    {
        return 'voice_admin_token_' . md5($this->url() . '|' . $this->master->getPlatformSetting(self::USERNAME, ''));
    }

    /**
     * @return array{ok: bool, status: int, message: string, token?: string}
     */
    private function login(): array
    {
        $cache = cache();
        $token = (string) $cache->get($this->tokenKey());
        if ($token !== '') {
            return ['ok' => true, 'status' => 200, 'message' => 'Cached.', 'token' => $token];
        }

        $url  = $this->url();
        $user = trim($this->master->getPlatformSetting(self::USERNAME, ''));
        $pass = $this->secret(self::PASSWORD);
        if ($url === '' || $user === '' || $pass === '') {
            return $this->err(422, 'Save the voice agent address and the dashboard login first.');
        }

        $r = $this->http('POST', $url . '/api/login', ['username' => $user, 'password' => $pass], [], 10);
        if (! $r['ok']) {
            return $this->err($r['status'] === 401 ? 401 : ($r['status'] ?: 502), $r['status'] === 401 ? 'The voice agent rejected the dashboard login.' : $r['message']);
        }
        $token = (string) ($r['data']['token'] ?? '');
        if ($token === '' || empty($r['data']['super'])) {
            return $this->err(403, 'This login is not the voice agent platform admin. Use the super admin login.');
        }
        $cache->save($this->tokenKey(), $token, 72000);

        return ['ok' => true, 'status' => 200, 'message' => 'Logged in.', 'token' => $token];
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array{ok: bool, status: int, message: string, data?: mixed}
     */
    private function call(string $method, string $path, ?array $body = null, int $timeout = 12): array
    {
        $login = $this->login();
        if (! $login['ok']) {
            return $login;
        }
        $r = $this->http($method, $this->url() . $path, $body, ['Authorization: Bearer ' . $login['token']], $timeout);
        if ($r['status'] === 401) {
            cache()->delete($this->tokenKey()); // the token expired: log in again once
            $login = $this->login();
            if ($login['ok']) {
                $r = $this->http($method, $this->url() . $path, $body, ['Authorization: Bearer ' . $login['token']], $timeout);
            }
        }

        return $r['ok'] ? ['ok' => true, 'status' => 200, 'message' => 'OK', 'data' => $r['data']] : $this->err($r['status'] ?: 502, $r['message']);
    }

    /**
     * @param array<string, mixed>|null $json
     * @param list<string>              $headers
     *
     * @return array{ok: bool, status: int, message: string, data?: mixed}
     */
    private function http(string $method, string $url, ?array $json, array $headers, int $timeout): array
    {
        $pin = $this->pin($url);
        if (! $pin['ok']) {
            return ['ok' => false, 'status' => 0, 'message' => $pin['message']];
        }

        try {
            $ch   = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
            ];
            if ($json !== null) {
                $opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_UNICODE);
            }
            if (! empty($pin['resolve'])) {
                $opts[CURLOPT_RESOLVE] = $pin['resolve'];
            }
            curl_setopt_array($ch, $opts);
            $raw     = curl_exec($ch);
            $status  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);
        } catch (Throwable $e) {
            log_message('error', 'VoiceAgentAdminService: ' . $e::class . ': ' . $e->getMessage());

            return ['ok' => false, 'status' => 0, 'message' => 'The voice agent could not be reached.'];
        }

        if ($curlErr !== '') {
            return ['ok' => false, 'status' => 0, 'message' => 'The voice agent could not be reached (' . mb_substr($curlErr, 0, 120) . ').'];
        }
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if ($status < 200 || $status >= 300) {
            $detail = is_array($data) ? (string) ($data['detail'] ?? '') : '';

            return ['ok' => false, 'status' => $status, 'message' => 'The voice agent answered HTTP ' . $status . ($detail !== '' ? ': ' . mb_substr($detail, 0, 200) : '.')];
        }

        return ['ok' => true, 'status' => $status, 'message' => 'OK', 'data' => $data];
    }

    /**
     * @return array{ok: bool, status: int, message: string}
     */
    private function err(int $status, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'message' => $message];
    }
}
