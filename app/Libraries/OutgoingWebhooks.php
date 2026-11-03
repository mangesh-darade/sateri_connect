<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\WebhookDeliveryModel;
use App\Models\WebhookEndpointModel;
use Throwable;

/**
 * Outgoing webhooks: tell a client's own systems when something happens (customer message, delivery
 * status, payment). dispatch() only writes queue rows, so the Meta webhook request never waits on the
 * network; deliverDue() (cron webhooks:deliver) makes the calls with retries.
 *
 * Each call is POSTed as JSON with
 *   X-Webhook-Event, X-Webhook-Id, X-Webhook-Timestamp and
 *   X-Webhook-Signature: sha256=HMAC_SHA256("{timestamp}.{raw body}", endpoint secret).
 *
 * Endpoints must be https on port 443 and resolve to a public address (checked when saving and again
 * on every call, with the IP pinned), and redirects are never followed, so a webhook cannot be aimed
 * at the server's own network.
 */
class OutgoingWebhooks
{
    /** @var array<string, string> */
    public const EVENTS = [
        'message.received' => 'Customer message received',
        'message.status'   => 'Message status changed (sent, delivered, read, failed)',
        'payment.paid'     => 'Payment link paid',
    ];

    private const MAX_ENDPOINTS = 10;
    private const MAX_ATTEMPTS  = 5;
    private const BACKOFF       = [60, 300, 1800, 7200, 21600];
    private const KEEP_DAYS     = 14;

    /**
     * @return list<array<string, mixed>> Endpoints without their secret.
     */
    public function all(): array
    {
        $rows = model(WebhookEndpointModel::class)->orderBy('id', 'ASC')->findAll(self::MAX_ENDPOINTS);

        return array_map(static function (array $r): array {
            unset($r['secret']);
            $r['events'] = array_values(array_filter(explode(',', (string) $r['events'])));

            return $r;
        }, $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentDeliveries(int $limit = 20): array
    {
        return db_connect()->table('webhook_deliveries d')
            ->select('d.id, d.event, d.status, d.attempts, d.last_http_status, d.last_error, d.created_at, e.name AS endpoint_name')
            ->join('webhook_endpoints e', 'e.id = d.endpoint_id', 'left')
            ->orderBy('d.id', 'DESC')
            ->limit($limit)
            ->get()
            ->getResultArray();
    }

    /**
     * @param list<string> $events
     *
     * @return array{ok:bool,status:int,message:string,data?:array{id:int,secret:string}}
     */
    public function create(string $name, string $url, array $events, ?int $userId): array
    {
        $model = model(WebhookEndpointModel::class);
        if ($model->countAllResults() >= self::MAX_ENDPOINTS) {
            return $this->fail(422, 'You can add up to ' . self::MAX_ENDPOINTS . ' endpoints.');
        }
        $checked = $this->validate($name, $url, $events);
        if (is_string($checked)) {
            return $this->fail(422, $checked);
        }

        $secret = 'whsec_' . bin2hex(random_bytes(24));
        $id     = (int) $model->insert($checked + [
            'secret'     => (new EncryptionService())->encrypt($secret),
            'is_active'  => 1,
            'created_by' => $userId,
        ]);

        return ['ok' => true, 'status' => 200, 'message' => 'Endpoint added.', 'data' => ['id' => $id, 'secret' => $secret]];
    }

    /**
     * @param list<string> $events
     *
     * @return array{ok:bool,status:int,message:string}
     */
    public function update(int $id, string $name, string $url, array $events, bool $active): array
    {
        $model = model(WebhookEndpointModel::class);
        if ($model->find($id) === null) {
            return $this->fail(404, 'Endpoint not found.');
        }
        $checked = $this->validate($name, $url, $events);
        if (is_string($checked)) {
            return $this->fail(422, $checked);
        }
        $model->update($id, $checked + ['is_active' => $active ? 1 : 0]);

        return ['ok' => true, 'status' => 200, 'message' => 'Endpoint updated.'];
    }

    /**
     * @return array{ok:bool,status:int,message:string,data?:array{id:int,secret:string}}
     */
    public function rotateSecret(int $id): array
    {
        $model = model(WebhookEndpointModel::class);
        if ($model->find($id) === null) {
            return $this->fail(404, 'Endpoint not found.');
        }
        $secret = 'whsec_' . bin2hex(random_bytes(24));
        $model->update($id, ['secret' => (new EncryptionService())->encrypt($secret)]);

        return ['ok' => true, 'status' => 200, 'message' => 'Secret rotated.', 'data' => ['id' => $id, 'secret' => $secret]];
    }

    public function delete(int $id): bool
    {
        $model = model(WebhookEndpointModel::class);
        if ($model->find($id) === null) {
            return false;
        }
        db_connect()->table('webhook_deliveries')->where('endpoint_id', $id)->delete();

        return (bool) $model->delete($id);
    }

    /**
     * Queue the event for every active endpoint subscribed to it. Never throws, never uses the network.
     *
     * @param array<string, mixed> $data
     */
    public function dispatch(string $event, array $data): void
    {
        if (! isset(self::EVENTS[$event])) {
            return;
        }

        try {
            $endpoints = model(WebhookEndpointModel::class)->where('is_active', 1)->findAll(self::MAX_ENDPOINTS);
            $targets   = array_filter($endpoints, static fn (array $e): bool => in_array($event, explode(',', (string) $e['events']), true));
            if ($targets === []) {
                return;
            }

            $payload = (string) json_encode(
                ['event' => $event, 'created_at' => gmdate('c'), 'data' => $data],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            $now = date('Y-m-d H:i:s');
            foreach ($targets as $e) {
                model(WebhookDeliveryModel::class)->insert([
                    'endpoint_id'     => (int) $e['id'],
                    'event'           => $event,
                    'payload'         => $payload,
                    'status'          => 'pending',
                    'attempts'        => 0,
                    'next_attempt_at' => $now,
                    'created_at'      => $now,
                ]);
            }
        } catch (Throwable $e) {
            log_message('warning', 'Webhook dispatch failed for {event}: {msg}', ['event' => $event, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * Make the calls that are due. Returns how many were attempted.
     */
    public function deliverDue(int $limit = 50): int
    {
        $db   = db_connect();
        $now  = date('Y-m-d H:i:s');
        $rows = model(WebhookDeliveryModel::class)
            ->where('status', 'pending')
            ->where('next_attempt_at <=', $now)
            ->orderBy('id', 'ASC')
            ->findAll($limit);

        $endpoints = [];
        $attempted = 0;
        foreach ($rows as $row) {
            // Claim the row so an overlapping cron run does not send it twice.
            $db->table('webhook_deliveries')
                ->where('id', (int) $row['id'])
                ->where('status', 'pending')
                ->where('next_attempt_at <=', $now)
                ->update(['next_attempt_at' => date('Y-m-d H:i:s', time() + 120)]);
            if ($db->affectedRows() !== 1) {
                continue;
            }

            $endpoints[$row['endpoint_id']] ??= model(WebhookEndpointModel::class)->find((int) $row['endpoint_id']);
            $endpoint = $endpoints[$row['endpoint_id']];
            if (! is_array($endpoint) || (int) $endpoint['is_active'] !== 1) {
                model(WebhookDeliveryModel::class)->update((int) $row['id'], ['status' => 'failed', 'last_error' => 'Endpoint removed or switched off.']);
                continue;
            }

            $this->record($row, $this->send($endpoint, $row));
            $attempted++;
        }

        return $attempted;
    }

    /**
     * Send a "ping" to one endpoint right now and return the result.
     *
     * @return array{ok:bool,status:int,message:string}
     */
    public function sendTest(int $id): array
    {
        $endpoint = model(WebhookEndpointModel::class)->find($id);
        if (! is_array($endpoint)) {
            return $this->fail(404, 'Endpoint not found.');
        }

        $now = date('Y-m-d H:i:s');
        $row = [
            'endpoint_id'     => $id,
            'event'           => 'ping',
            'payload'         => (string) json_encode(['event' => 'ping', 'created_at' => gmdate('c'), 'data' => ['message' => 'Test event from Sateri Connect']]),
            'status'          => 'pending',
            'attempts'        => 0,
            'next_attempt_at' => $now,
            'created_at'      => $now,
        ];
        $row['id'] = (int) model(WebhookDeliveryModel::class)->insert($row);

        $res = $this->send($endpoint, $row);
        $this->record($row, $res, false);

        return $res['ok']
            ? ['ok' => true, 'status' => 200, 'message' => 'Your endpoint answered HTTP ' . $res['http'] . '.']
            : ['ok' => false, 'status' => 502, 'message' => 'Test failed: ' . $res['error']];
    }

    /**
     * Drop delivery rows older than two weeks.
     */
    public function cleanup(): void
    {
        db_connect()->table('webhook_deliveries')
            ->where('created_at <', date('Y-m-d H:i:s', time() - self::KEEP_DAYS * 86400))
            ->limit(1000)
            ->delete();
    }

    /**
     * Error text, or null when the URL is acceptable. Also used before every call.
     */
    public static function validateUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || mb_strlen($url) > 500) {
            return 'Enter an https:// URL (max 500 characters).';
        }
        $p = parse_url($url);
        if (! is_array($p) || strtolower((string) ($p['scheme'] ?? '')) !== 'https' || empty($p['host'])) {
            return 'The URL must start with https://.';
        }
        if (isset($p['user']) || isset($p['pass'])) {
            return 'The URL must not contain a username or password.';
        }
        if (isset($p['port']) && (int) $p['port'] !== 443) {
            return 'Only the standard HTTPS port (443) is allowed.';
        }
        if (self::publicIp((string) $p['host']) === null) {
            return 'The host must resolve to a public internet address.';
        }

        return null;
    }

    /**
     * First IPv4 address of the host when every address it resolves to is public, else null.
     */
    public static function publicIp(string $host): ?string
    {
        $host = strtolower(trim($host, '[]'));
        if ($host === '' || $host === 'localhost') {
            return null;
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : (gethostbynamel($host) ?: []);
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return null;
            }
        }

        return $ips === [] ? null : (string) $ips[0];
    }

    /**
     * @param list<string> $events
     *
     * @return array{name:string,url:string,events:string}|string Clean fields, or an error message.
     */
    private function validate(string $name, string $url, array $events): array|string
    {
        $name = trim(mb_substr($name, 0, 100));
        if ($name === '') {
            return 'Enter a name for this endpoint.';
        }
        if (($err = self::validateUrl($url)) !== null) {
            return $err;
        }
        $events = array_values(array_intersect(array_keys(self::EVENTS), $events));
        if ($events === []) {
            return 'Pick at least one event.';
        }

        return ['name' => $name, 'url' => trim($url), 'events' => implode(',', $events)];
    }

    /**
     * @param array<string, mixed> $endpoint
     * @param array<string, mixed> $delivery
     *
     * @return array{ok:bool,http:int,error:string}
     */
    private function send(array $endpoint, array $delivery): array
    {
        $host = (string) parse_url((string) $endpoint['url'], PHP_URL_HOST);
        $ip   = self::publicIp($host);
        if ($ip === null) {
            return ['ok' => false, 'http' => 0, 'error' => 'Host does not resolve to a public address.'];
        }

        try {
            $secret = (new EncryptionService())->decryptIfNeeded((string) $endpoint['secret']);
            $body   = (string) $delivery['payload'];
            $ts     = time();

            $ch = curl_init((string) $endpoint['url']);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_RESOLVE        => [$host . ':443:' . $ip],
                CURLOPT_USERAGENT      => 'SateriConnect-Webhooks/1',
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'X-Webhook-Event: ' . $delivery['event'],
                    'X-Webhook-Id: ' . $delivery['id'],
                    'X-Webhook-Timestamp: ' . $ts,
                    'X-Webhook-Signature: sha256=' . hash_hmac('sha256', $ts . '.' . $body, $secret),
                ],
            ]);
            $raw  = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = $raw === false ? curl_error($ch) : '';

            if ($raw === false) {
                return ['ok' => false, 'http' => 0, 'error' => mb_substr($err !== '' ? $err : 'Connection failed.', 0, 200)];
            }

            return $http >= 200 && $http < 300
                ? ['ok' => true, 'http' => $http, 'error' => '']
                : ['ok' => false, 'http' => $http, 'error' => 'HTTP ' . $http];
        } catch (Throwable $e) {
            log_message('warning', 'Webhook send failed: {msg}', ['msg' => $e->getMessage()]);

            return ['ok' => false, 'http' => 0, 'error' => 'Could not send.'];
        }
    }

    /**
     * @param array<string, mixed>              $row
     * @param array{ok:bool,http:int,error:string} $res
     */
    private function record(array $row, array $res, bool $retry = true): void
    {
        $attempts = (int) $row['attempts'] + 1;
        $update   = [
            'attempts'         => $attempts,
            'last_http_status' => $res['http'] > 0 ? $res['http'] : null,
            'last_error'       => $res['ok'] ? null : $res['error'],
        ];

        if ($res['ok']) {
            $update['status']  = 'sent';
            $update['sent_at'] = date('Y-m-d H:i:s');
        } elseif (! $retry || $attempts >= self::MAX_ATTEMPTS) {
            $update['status'] = 'failed';
        } else {
            $update['next_attempt_at'] = date('Y-m-d H:i:s', time() + self::BACKOFF[$attempts - 1]);
        }

        model(WebhookDeliveryModel::class)->update((int) $row['id'], $update);
    }

    /**
     * @return array{ok:bool,status:int,message:string}
     */
    private function fail(int $status, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'message' => $message];
    }
}
