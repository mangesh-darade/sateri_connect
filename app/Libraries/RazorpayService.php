<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\WalletPaymentModel;
use Throwable;

/**
 * Online wallet recharge through Razorpay. One platform Razorpay account (keys in the master DB) serves every
 * client; the client's wallet is credited in its own database.
 *
 * Flow: createOrder() -> Razorpay Checkout in the browser -> confirm() (checks the checkout signature) or the
 * webhook (checks the webhook signature). Both end in settle(), which credits the wallet once per payment.
 *
 * Services return ['ok', 'status', 'message', ...] and never throw to controllers.
 */
class RazorpayService
{
    /** The currency this gateway collects in. A wallet in another currency cannot be topped up through it. */
    public const CURRENCY = 'INR';

    public const API = 'https://api.razorpay.com/v1';

    /** Platform setting keys (master DB `platform_settings`). Secrets are stored encrypted. */
    public const SETTINGS = [
        'razorpay_enabled', 'razorpay_key_id', 'razorpay_key_secret', 'razorpay_webhook_secret',
        'recharge_min', 'recharge_max',
        'invoice_company', 'invoice_address', 'invoice_gstin',
    ];

    private MasterTenantRepository $master;

    /** @var array<string, string>|null */
    private ?array $cfg = null;

    public function __construct(?MasterTenantRepository $master = null)
    {
        $this->master = $master ?? new MasterTenantRepository();
    }

    // ------------------------------------------------------------------ pure helpers (unit tested)

    /** Razorpay signs "order_id|payment_id" with the key secret. */
    public static function verifyCheckoutSignature(string $orderId, string $paymentId, string $signature, string $secret): bool
    {
        if ($orderId === '' || $paymentId === '' || $signature === '' || $secret === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $orderId . '|' . $paymentId, $secret), $signature);
    }

    /** Webhooks are signed over the raw request body with the webhook secret. */
    public static function verifyWebhookSignature(string $rawBody, string $signature, string $secret): bool
    {
        if ($rawBody === '' || $signature === '' || $secret === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature);
    }

    /**
     * What the client pays for a recharge. The wallet is credited with `credit`; GST is charged on top.
     *
     * @return array{credit: float, tax: float, total: float, paise: int}
     */
    public static function totals(float $credit, float $gstPercent): array
    {
        $credit = round($credit, 2);
        $tax    = round($credit * max(0.0, $gstPercent) / 100, 2);
        $total  = round($credit + $tax, 2);

        return ['credit' => $credit, 'tax' => $tax, 'total' => $total, 'paise' => (int) round($total * 100)];
    }

    public static function invoiceNumber(string $tenantKey, int $paymentId, string $paidAt): string
    {
        $key = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $tenantKey) ?? '', 0, 6));
        $ts  = strtotime($paidAt) ?: time();

        return 'INV-' . ($key !== '' ? $key : 'CLT') . '-' . date('Ym', $ts) . '-' . str_pad((string) $paymentId, 5, '0', STR_PAD_LEFT);
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
            $out[$k] = in_array($k, ['razorpay_key_secret', 'razorpay_webhook_secret'], true) && $raw !== ''
                ? $this->decrypt($raw)
                : $raw;
        }
        $out['recharge_min']         = $out['recharge_min'] !== '' ? $out['recharge_min'] : '100';
        $out['recharge_max']         = $out['recharge_max'] !== '' ? $out['recharge_max'] : '100000';

        return $this->cfg = $out;
    }

    public function isConfigured(): bool
    {
        $c = $this->config();

        return $c['razorpay_enabled'] === '1' && $c['razorpay_key_id'] !== '' && $c['razorpay_key_secret'] !== '';
    }

    public function keyId(): string
    {
        return $this->config()['razorpay_key_id'];
    }

    /**
     * GST on an online recharge is the same GST % set on the client's wallet (Platform > client > Wallet), the one
     * that is also added to every message price. One setting, per client. Must run with the client's database bound.
     */
    public function gstPercent(): float
    {
        return max(0.0, min(100.0, (new WalletService())->taxPercent()));
    }

    /**
     * @param array<string, string> $values only the SETTINGS keys; an empty secret keeps the stored one
     */
    public function saveConfig(array $values): void
    {
        foreach (self::SETTINGS as $k) {
            if (! array_key_exists($k, $values)) {
                continue;
            }
            $v = trim((string) $values[$k]);
            $secret = in_array($k, ['razorpay_key_secret', 'razorpay_webhook_secret'], true);
            if ($secret && $v === '') {
                continue;
            }
            $this->master->setPlatformSetting($k, $v, $secret);
        }
        $this->cfg = null;
    }

    // ------------------------------------------------------------------ orders

    /**
     * Start an online recharge of `$credit` (wallet currency, before GST) for the current client.
     *
     * @return array{ok: bool, status: int, message: string, order_id?: string, key_id?: string, amount?: int, currency?: string, total?: float, tax?: float}
     */
    public function createOrder(string $tenantKey, float $credit, ?int $userId, string $clientName = ''): array
    {
        $wallet = new WalletService();
        if (! $this->isConfigured()) {
            return $this->fail(503, 'Online recharge is not available. Contact your platform admin.');
        }
        $currency = strtoupper($wallet->currency());
        if ($currency !== self::CURRENCY) {
            return $this->fail(422, 'Online recharge is available for ' . self::CURRENCY . ' wallets only. Contact your platform admin to add money.');
        }
        $c = $this->config();
        if (! is_finite($credit) || $credit < (float) $c['recharge_min'] || $credit > (float) $c['recharge_max']) {
            return $this->fail(422, 'Enter an amount between ' . Currency::symbol(self::CURRENCY) . number_format((float) $c['recharge_min']) . ' and ' . Currency::symbol(self::CURRENCY) . number_format((float) $c['recharge_max']) . '.');
        }

        $t = self::totals($credit, $this->gstPercent());
        $res = $this->http('POST', '/orders', [
            'amount'   => $t['paise'],
            'currency' => self::CURRENCY,
            'receipt'  => substr('w-' . $tenantKey . '-' . time(), 0, 40),
            'notes'    => ['tenant_key' => $tenantKey, 'purpose' => 'wallet', 'credit' => (string) $t['credit']],
        ]);
        if (! $res['ok'] || empty($res['data']['id'])) {
            return $this->fail(502, match (true) {
                $res['status'] === 0                          => 'Could not reach the payment gateway. Please try again in a moment.',
                in_array($res['status'], [401, 403], true)    => 'Online payment is not set up correctly. Please contact your platform admin.',
                $res['status'] === 429                        => 'The payment gateway is busy. Please try again in a minute.',
                default                                       => 'Could not start the payment. Please try again.',
            });
        }

        $orderId = (string) $res['data']['id'];
        $now = date('Y-m-d H:i:s');
        model(WalletPaymentModel::class)->insert([
            'order_id' => $orderId, 'source' => 'checkout', 'status' => 'created', 'currency' => self::CURRENCY,
            'credit_amount' => $t['credit'], 'tax_percent' => $this->gstPercent(), 'tax_amount' => $t['tax'], 'total_amount' => $t['total'],
            'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $this->ok('Order created.', ['order_id' => $orderId, 'key_id' => $this->keyId(), 'amount' => $t['paise'], 'currency' => self::CURRENCY, 'total' => $t['total'], 'tax' => $t['tax']]);
    }

    /**
     * A payment link for the low-balance alert, so the admin can top up from the email or notification.
     *
     * @return array{ok: bool, status: int, message: string, url?: string}
     */
    public function createPaymentLink(string $tenantKey, float $credit, string $clientName = ''): array
    {
        $wallet = new WalletService();
        if (! $this->isConfigured() || strtoupper($wallet->currency()) !== self::CURRENCY) {
            return $this->fail(503, 'Online payments are not available.');
        }
        $c = $this->config();
        $credit = max((float) $c['recharge_min'], min((float) $c['recharge_max'], $credit));
        $t = self::totals($credit, $this->gstPercent());

        $res = $this->http('POST', '/payment_links', [
            'amount'      => $t['paise'],
            'currency'    => self::CURRENCY,
            'description' => 'WhatsApp wallet top-up' . ($clientName !== '' ? ' - ' . $clientName : ''),
            'reference_id' => substr($tenantKey . '-' . time(), 0, 40),
            'notes'       => ['tenant_key' => $tenantKey, 'purpose' => 'wallet', 'credit' => (string) $t['credit']],
            'callback_url' => site_url('wallet'),
            'callback_method' => 'get',
        ]);
        if (! $res['ok'] || empty($res['data']['id']) || empty($res['data']['short_url'])) {
            return $this->fail(502, 'Could not create a payment link.');
        }

        $now = date('Y-m-d H:i:s');
        model(WalletPaymentModel::class)->insert([
            'order_id' => (string) $res['data']['id'], 'source' => 'link', 'status' => 'created', 'currency' => self::CURRENCY,
            'credit_amount' => $t['credit'], 'tax_percent' => $this->gstPercent(), 'tax_amount' => $t['tax'], 'total_amount' => $t['total'],
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return $this->ok('Payment link created.', ['url' => (string) $res['data']['short_url']]);
    }

    /**
     * The browser finished Checkout: verify the signature, then credit.
     *
     * @return array{ok: bool, status: int, message: string}
     */
    public function confirm(string $orderId, string $paymentId, string $signature): array
    {
        if (! self::verifyCheckoutSignature($orderId, $paymentId, $signature, $this->config()['razorpay_key_secret'])) {
            log_message('warning', 'Razorpay checkout signature mismatch for order {o}', ['o' => $orderId]);

            return $this->fail(400, 'Payment could not be verified. If money was deducted it will be credited automatically within a few minutes, or contact support.');
        }

        return $this->settle($orderId, $paymentId, null);
    }

    /**
     * Credit the wallet for a paid order, once. `$paidPaise` (from a webhook) must match the order total.
     *
     * @return array{ok: bool, status: int, message: string}
     */
    public function settle(string $orderId, string $paymentId, ?int $paidPaise): array
    {
        return (new WalletPaymentSettler())->settle($orderId, $paymentId, $paidPaise, 'Razorpay', 'rzp-');
    }

    /**
     * Webhook entry. The caller has already verified the signature. Works out the client and the order from
     * the payload, switches to that client's database and settles.
     *
     * @param array<string, mixed> $event
     *
     * @return array{ok: bool, status: int, message: string}
     */
    public function handleWebhook(array $event): array
    {
        $type = (string) ($event['event'] ?? '');
        if (! in_array($type, ['payment.captured', 'order.paid', 'payment_link.paid'], true)) {
            return $this->ok('Ignored.');
        }

        $p     = $event['payload'] ?? [];
        $pay   = $p['payment']['entity'] ?? [];
        $order = $p['order']['entity'] ?? [];
        $link  = $p['payment_link']['entity'] ?? [];

        $orderId   = (string) ($link['id'] ?? $pay['order_id'] ?? $order['id'] ?? '');
        $paymentId = (string) ($pay['id'] ?? '');
        $notes     = array_merge(
            is_array($order['notes'] ?? null) ? $order['notes'] : [],
            is_array($link['notes'] ?? null) ? $link['notes'] : [],
            is_array($pay['notes'] ?? null) ? $pay['notes'] : []
        );
        $tenantKey = strtolower(trim((string) ($notes['tenant_key'] ?? '')));
        if ($orderId === '' || $paymentId === '' || $tenantKey === '' || ($notes['purpose'] ?? '') !== 'wallet') {
            return $this->ok('Not a wallet payment.');
        }

        try {
            if (! (new TenantConnection())->apply($tenantKey, 'razorpay-webhook')) {
                return $this->fail(404, 'Unknown client.');
            }
        } catch (Throwable $e) {
            log_message('error', 'Razorpay webhook tenant switch failed: {msg}', ['msg' => $e->getMessage()]);

            return $this->fail(500, 'Could not open the client.');
        }

        return $this->settle($orderId, $paymentId, isset($pay['amount']) ? (int) $pay['amount'] : null);
    }

    /** Check the saved keys against Razorpay (used by the "Test connection" button). */
    public function testKeys(): array
    {
        $res = $this->http('GET', '/orders?count=1');

        return $res['ok'] ? $this->ok('Connected to Razorpay.') : $this->fail(502, 'Razorpay rejected the keys. Check the key id and secret.');
    }

    // ------------------------------------------------------------------ transport

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array{ok: bool, status: int, data: array<string, mixed>}
     */
    protected function http(string $method, string $path, ?array $body = null): array
    {
        $c = $this->config();
        try {
            $ch = curl_init(self::API . $path);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_USERPWD        => $c['razorpay_key_id'] . ':' . $c['razorpay_key_secret'],
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            }
            $raw    = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($raw === false) {
                log_message('error', 'Razorpay {m} {p} transport error: {e}', ['m' => $method, 'p' => $path, 'e' => curl_error($ch)]);
            }
            curl_close($ch);
            $data = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
            if ($status < 200 || $status >= 300) {
                log_message('error', 'Razorpay {m} {p} failed: HTTP {s} {e}', ['m' => $method, 'p' => $path, 's' => $status, 'e' => (string) ($data['error']['description'] ?? '')]);
            }

            return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'data' => is_array($data) ? $data : []];
        } catch (Throwable $e) {
            log_message('error', 'Razorpay request failed: {msg}', ['msg' => $e->getMessage()]);

            return ['ok' => false, 'status' => 0, 'data' => []];
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
