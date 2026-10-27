<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\WalletPaymentModel;
use Throwable;

/**
 * Wallet recharge through Mswipe Pay By Link (PBL). One platform Mswipe merchant account serves every client.
 *
 * Flow (https://docs.mswipe.com, "Getting Started"):
 *   1. CreatePBLAuthToken  -> session token
 *   2. MswipePayment       -> payment link (`smslink`, carries `TransID`) for our order (`invoice_id`)
 *   3. the customer pays on Mswipe and is sent back to our `redirect_url`; Mswipe also back-posts to a URL
 *      registered with them (unsigned!)
 *   4. getPBLTransactionDetails (id = TransID) -> Payment_Status 1 paid / 0 failed / 2 pending
 *
 * The back-post carries no signature, so it is only a nudge: every credit is decided by the status API, never
 * by what the browser or the back-post says. A cron (`payments:reconcile`) settles links whose customer closed
 * the tab. Services return ['ok', 'status', 'message', ...] and never throw to controllers.
 */
class MswipeService
{
    /** The currency this gateway collects in. A wallet in another currency cannot be topped up through it. */
    public const CURRENCY = 'INR';

    public const UAT  = 'https://dcuat.mswipetech.co.in';
    public const LIVE = 'https://pbl.mswipe.com';

    /** Platform setting keys (master DB `platform_settings`). Secrets are stored encrypted. */
    public const SETTINGS = [
        'mswipe_enabled', 'mswipe_env', 'mswipe_user_id', 'mswipe_client_id', 'mswipe_password', 'mswipe_custcode', 'mswipe_fallback_mobile',
    ];

    private MasterTenantRepository $master;

    /** @var array<string, string>|null */
    private ?array $cfg = null;

    public function __construct(?MasterTenantRepository $master = null)
    {
        $this->master = $master ?? new MasterTenantRepository();
    }

    // ------------------------------------------------------------------ pure helpers (unit tested)

    /** Mswipe sends status as a JSON boolean in some replies and as "True" / "true" in others. */
    public static function truthy(mixed $v): bool
    {
        return $v === true || in_array(strtolower(trim((string) $v)), ['true', '1'], true);
    }

    /** Mswipe wants rupees as a decimal string. */
    public static function amountString(float $total): string
    {
        return number_format(round($total, 2), 2, '.', '');
    }

    /** Our order id, sent as `invoice_id`: letters and digits only, short, unique. */
    public static function newOrderId(): string
    {
        return 'W' . strtoupper(base_convert((string) time(), 10, 36)) . strtoupper(bin2hex(random_bytes(4)));
    }

    /** The `TransID` query value inside the link Mswipe returns; it is the id the status API takes. */
    public static function transIdFromLink(string $link): string
    {
        $q = (string) parse_url($link, PHP_URL_QUERY);
        parse_str($q, $params);

        return trim((string) ($params['TransID'] ?? ''));
    }

    /**
     * Read the status API response.
     *
     * @param array<string, mixed> $res
     *
     * @return array{state: string, amount: float, order_id: string, ipg_id: string, rrn: string}
     *         state: paid | failed | pending | unknown
     */
    public static function parseStatus(array $res): array
    {
        $d = $res['Data'][0] ?? null;
        if (! is_array($d) || ! self::truthy($res['Status'] ?? '')) {
            return ['state' => 'unknown', 'amount' => 0.0, 'order_id' => '', 'ipg_id' => '', 'rrn' => ''];
        }
        $code  = (string) ($d['Payment_Status'] ?? '');
        $state = $code === '1' ? 'paid' : ($code === '0' ? 'failed' : ($code === '2' ? 'pending' : 'unknown'));

        return [
            'state'    => $state,
            'amount'   => (float) ($d['Amount'] ?? 0),
            'order_id' => trim((string) ($d['Order_Id'] ?? '')),
            'ipg_id'   => trim((string) ($d['IPG_ID'] ?? '')),
            'rrn'      => trim((string) ($d['Payment_Id'] ?? '')),
        ];
    }

    // ------------------------------------------------------------------ configuration

    /** @return array<string, string> */
    public function config(): array
    {
        if ($this->cfg !== null) {
            return $this->cfg;
        }
        $out = [];
        foreach (self::SETTINGS as $k) {
            $raw = $this->master->getPlatformSetting($k, '');
            $out[$k] = $k === 'mswipe_password' && $raw !== '' ? $this->decrypt($raw) : $raw;
        }
        $out['mswipe_env'] = $out['mswipe_env'] === 'live' ? 'live' : 'uat';

        return $this->cfg = $out;
    }

    public function isConfigured(): bool
    {
        $c = $this->config();

        return $c['mswipe_enabled'] === '1' && $c['mswipe_user_id'] !== '' && $c['mswipe_client_id'] !== '' && $c['mswipe_password'] !== '' && $c['mswipe_custcode'] !== '';
    }

    public function baseUrl(): string
    {
        return $this->config()['mswipe_env'] === 'live' ? self::LIVE : self::UAT;
    }

    /**
     * @param array<string, string> $values only the SETTINGS keys; an empty password keeps the stored one
     */
    public function saveConfig(array $values): void
    {
        foreach (self::SETTINGS as $k) {
            if (! array_key_exists($k, $values)) {
                continue;
            }
            $v = trim((string) $values[$k]);
            if ($k === 'mswipe_password' && $v === '') {
                continue;
            }
            $this->master->setPlatformSetting($k, $v, $k === 'mswipe_password');
        }
        $this->cfg = null;
    }

    // ------------------------------------------------------------------ create a payment link

    /**
     * Start a recharge of `$credit` (wallet currency, before GST) for the current client.
     *
     * @return array{ok: bool, status: int, message: string, url?: string, order_id?: string, total?: float}
     */
    public function createLink(string $tenantKey, float $credit, ?int $userId, string $mobile, string $email, string $returnUrl): array
    {
        $wallet = new WalletService();
        if (! $this->isConfigured()) {
            return $this->fail(503, 'Online recharge is not available. Contact your platform admin.');
        }
        if (strtoupper($wallet->currency()) !== self::CURRENCY) {
            return $this->fail(422, 'Online recharge is available for ' . self::CURRENCY . ' wallets only. Contact your platform admin to add money.');
        }
        $rz  = new RazorpayService($this->master); // shared recharge limits and GST
        $cfg = $rz->config();
        if (! is_finite($credit) || $credit < (float) $cfg['recharge_min'] || $credit > (float) $cfg['recharge_max']) {
            return $this->fail(422, 'Enter an amount between ' . number_format((float) $cfg['recharge_min']) . ' and ' . number_format((float) $cfg['recharge_max']) . '.');
        }

        $c = $this->config();
        $mobile = preg_replace('/\D+/', '', $mobile) ?? '';
        $mobile = strlen($mobile) >= 10 ? substr($mobile, -10) : preg_replace('/\D+/', '', $c['mswipe_fallback_mobile']);
        if (strlen((string) $mobile) !== 10) {
            return $this->fail(422, 'A 10 digit mobile number is needed for the payment link. Ask your platform admin to set one in the payment settings.');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $email = 'noreply@' . (parse_url(site_url(), PHP_URL_HOST) ?: 'example.com');
        }

        $token = $this->token();
        if ($token === '') {
            return $this->fail(502, 'Could not start the payment. Please try again.');
        }

        $t       = RazorpayService::totals($credit, $rz->gstPercent());
        $orderId = self::newOrderId();
        $res = $this->http('POST', '/ipg/api/MswipePayment', [
            'amount'        => self::amountString($t['total']),
            'mobileno'      => $mobile,
            'custcode'      => $c['mswipe_custcode'],
            'user_id'       => $c['mswipe_user_id'],
            'sessiontoken'  => $token,
            'versionno'     => 'VER4.0.0',
            'imeino'        => '',
            'email_id'      => $email,
            'invoice_id'    => $orderId,
            'request_id'    => $orderId . bin2hex(random_bytes(3)),
            'device_id'     => '',
            'addlnote1'     => mb_substr($tenantKey, 0, 60),
            'LinkValidity'  => date('Y-m-d', strtotime('+2 days')),
            'paymentreason' => 'WhatsApp wallet recharge',
            'redirect_url'  => $returnUrl . (str_contains($returnUrl, '?') ? '&' : '?') . 'order=' . $orderId,
            'IsSendSMS'     => false,
            'ConvAllow'     => 'false',
            'ApplicationId' => 'api',
            'ChannelId'     => 'pbl',
            'ClientId'      => $c['mswipe_client_id'],
        ]);

        $link    = trim((string) ($res['data']['smslink'] ?? ''));
        $transId = self::transIdFromLink($link);
        if (! $res['ok'] || ! self::truthy($res['data']['status'] ?? '') || $link === '' || $transId === '') {
            log_message('error', 'Mswipe link creation failed: {m}', ['m' => (string) ($res['data']['responsemessage'] ?? 'no response')]);

            return $this->fail(502, 'Could not create the payment link. Please try again.');
        }

        $now = date('Y-m-d H:i:s');
        model(WalletPaymentModel::class)->insert([
            'order_id' => $orderId, 'gateway' => 'mswipe', 'gateway_ref' => $transId, 'gateway_txn' => mb_substr((string) ($res['data']['txn_id'] ?? ''), 0, 64),
            'source' => 'link', 'status' => 'created', 'currency' => self::CURRENCY,
            'credit_amount' => $t['credit'], 'tax_percent' => $rz->gstPercent(), 'tax_amount' => $t['tax'], 'total_amount' => $t['total'],
            'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->route($orderId, $tenantKey);

        return $this->ok('Payment link created.', ['url' => $link, 'order_id' => $orderId, 'total' => $t['total']]);
    }

    // ------------------------------------------------------------------ settle

    /**
     * Ask Mswipe what happened to an order and credit the wallet when (and only when) it is paid.
     * Safe to call any number of times, from the browser return, the back-post or cron.
     *
     * @return array{ok: bool, status: int, message: string, state?: string}
     */
    public function reconcile(string $orderId): array
    {
        try {
            $row = model(WalletPaymentModel::class)->where('order_id', $orderId)->where('gateway', 'mswipe')->first();
            if (! is_array($row)) {
                return $this->fail(404, 'Unknown payment order.');
            }
            if ($row['status'] === 'paid') {
                return $this->ok('Payment already credited.', ['state' => 'paid']);
            }

            $res = $this->http('POST', '/ipg/api/getPBLTransactionDetails', ['id' => (string) $row['gateway_ref']]);
            $st  = self::parseStatus($res['data']);

            if ($st['state'] === 'paid') {
                if (abs($st['amount'] - (float) $row['total_amount']) > 0.009 || ($st['order_id'] !== '' && $st['order_id'] !== $orderId)) {
                    log_message('error', 'Mswipe order {o}: status says {a} / {i}, expected {e}', ['o' => $orderId, 'a' => $st['amount'], 'i' => $st['order_id'], 'e' => (float) $row['total_amount']]);

                    return $this->fail(409, 'Paid amount does not match the order.') + ['state' => 'mismatch'];
                }
                $ref = $st['ipg_id'] !== '' ? $st['ipg_id'] : (string) $row['gateway_txn'];
                $out = (new WalletPaymentSettler())->settle($orderId, $ref !== '' ? $ref : $orderId, null, 'Mswipe', 'msw-');

                return $out + ['state' => $out['ok'] ? 'paid' : 'error'];
            }
            if ($st['state'] === 'failed') {
                (new WalletPaymentSettler())->markFailed($orderId);

                return $this->fail(402, 'The payment failed. You were not charged.') + ['state' => 'failed'];
            }

            return $this->ok($st['state'] === 'pending' ? 'Payment is still pending. The wallet is credited as soon as Mswipe confirms it.' : 'Waiting for Mswipe to confirm the payment.', ['state' => 'pending']);
        } catch (Throwable $e) {
            log_message('error', 'MswipeService::reconcile failed: {msg}', ['msg' => $e->getMessage()]);

            return $this->fail(500, 'Could not check the payment.');
        }
    }

    /**
     * Back-post from Mswipe. It is not signed, so it only tells us which order to re-check.
     *
     * @param array<string, mixed> $body
     *
     * @return array{ok: bool, status: int, message: string}
     */
    public function handleBackPost(array $body): array
    {
        $orderId = trim((string) ($body['ME_InvNo'] ?? $body['invoice_id'] ?? $body['InvNo'] ?? ''));
        if ($orderId === '' || preg_match('/^[A-Za-z0-9]{6,40}$/', $orderId) !== 1) {
            return $this->ok('Ignored.');
        }
        $tenantKey = $this->routeOf($orderId);
        if ($tenantKey === '') {
            return $this->ok('Ignored.');
        }
        try {
            if (! (new TenantConnection())->apply($tenantKey, 'mswipe-backpost')) {
                return $this->fail(404, 'Unknown client.');
            }
        } catch (Throwable $e) {
            log_message('error', 'Mswipe back-post tenant switch failed: {msg}', ['msg' => $e->getMessage()]);

            return $this->fail(500, 'Could not open the client.');
        }
        $r = $this->reconcile($orderId);

        return ['ok' => $r['ok'], 'status' => $r['status'], 'message' => $r['message']];
    }

    /**
     * Cron: re-check every link still open (created in the last 3 days) in the current client database.
     *
     * @return int orders credited
     */
    public function reconcilePending(int $limit = 100): int
    {
        $credited = 0;
        try {
            $rows = model(WalletPaymentModel::class)->where('gateway', 'mswipe')->where('status', 'created')
                ->where('created_at >=', date('Y-m-d H:i:s', strtotime('-3 days')))->orderBy('id', 'ASC')->findAll($limit);
            foreach ($rows as $r) {
                $res = $this->reconcile((string) $r['order_id']);
                if (($res['state'] ?? '') === 'paid' && $res['ok']) {
                    $credited++;
                }
            }
        } catch (Throwable $e) {
            log_message('error', 'MswipeService::reconcilePending failed: {msg}', ['msg' => $e->getMessage()]);
        }

        return $credited;
    }

    /** Settings page "Test": can we get a token with the saved credentials? */
    public function testCredentials(): array
    {
        return $this->token() !== '' ? $this->ok('Connected to Mswipe (' . strtoupper($this->config()['mswipe_env']) . ').') : $this->fail(502, 'Mswipe rejected the credentials. Check user id, client id and password.');
    }

    // ------------------------------------------------------------------ transport and routing

    protected function token(): string
    {
        $c = $this->config();
        $res = $this->http('POST', '/ipg/api/CreatePBLAuthToken', [
            'userId' => $c['mswipe_user_id'], 'clientId' => $c['mswipe_client_id'], 'password' => $c['mswipe_password'],
            'applId' => 'api', 'channelId' => 'pbl',
        ]);

        return $res['ok'] && self::truthy($res['data']['status'] ?? '') ? trim((string) ($res['data']['token'] ?? '')) : '';
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{ok: bool, status: int, data: array<string, mixed>}
     */
    protected function http(string $method, string $path, array $body): array
    {
        try {
            $ch = curl_init($this->baseUrl() . $path);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 25,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS     => json_encode($body),
            ]);
            $raw    = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $data = is_string($raw) ? json_decode($raw, true) : null;
            if ($status < 200 || $status >= 300) {
                log_message('error', 'Mswipe {p} failed: HTTP {s}', ['p' => $path, 's' => $status]);
            }

            return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'data' => is_array($data) ? $data : []];
        } catch (Throwable $e) {
            log_message('error', 'Mswipe request failed: {msg}', ['msg' => $e->getMessage()]);

            return ['ok' => false, 'status' => 0, 'data' => []];
        }
    }

    /** order id => client, in the master DB, so a public back-post can find the right database. */
    public function ensureRouteTable(): void
    {
        MasterTenantRepository::masterConnection()->query('CREATE TABLE IF NOT EXISTS `payment_routes` (
            `order_id` VARCHAR(40) NOT NULL, `tenant_key` VARCHAR(64) NOT NULL, `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`order_id`), KEY `idx_payment_routes_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    protected function route(string $orderId, string $tenantKey): void
    {
        try {
            $this->ensureRouteTable();
            MasterTenantRepository::masterConnection()->table('payment_routes')->insert(['order_id' => $orderId, 'tenant_key' => $tenantKey, 'created_at' => date('Y-m-d H:i:s')]);
        } catch (Throwable $e) {
            log_message('error', 'Mswipe route save failed: {msg}', ['msg' => $e->getMessage()]);
        }
    }

    protected function routeOf(string $orderId): string
    {
        try {
            $row = MasterTenantRepository::masterConnection()->table('payment_routes')->where('order_id', $orderId)->get()->getRowArray();

            return is_array($row) ? strtolower(trim((string) $row['tenant_key'])) : '';
        } catch (Throwable) {
            return '';
        }
    }

    private function decrypt(string $v): string
    {
        try {
            return (new EncryptionService())->decryptIfNeeded($v);
        } catch (Throwable) {
            return $v;
        }
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function ok(string $message, array $extra = []): array
    {
        return ['ok' => true, 'status' => 200, 'message' => $message] + $extra;
    }

    /**
     * @return array{ok: bool, status: int, message: string}
     */
    private function fail(int $status, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'message' => $message];
    }
}
