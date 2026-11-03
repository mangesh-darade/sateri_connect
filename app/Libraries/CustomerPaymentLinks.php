<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\ContactModel;
use App\Models\NotificationModel;
use App\Models\PaymentLinkModel;
use Throwable;

/**
 * Razorpay payment links a client sends to its own customers. Uses the client's own Razorpay keys
 * (tenant settings), never the platform account that RazorpayService uses for wallet recharges.
 * Paid status is picked up by reconcilePending() (cron payments:reconcile), so no public webhook is needed.
 */
class CustomerPaymentLinks
{
    private const API         = 'https://api.razorpay.com/v1';
    private const MIN_AMOUNT  = 1.0;
    private const MAX_AMOUNT  = 500000.0;
    private const EXPIRY_DAYS = 7;
    private const WATCH_DAYS  = 30;

    private SettingsService $settings;

    public function __construct(?SettingsService $settings = null)
    {
        $this->settings = $settings ?? service('settingsService');
    }

    /**
     * @return array{key_id:string,key_secret:string}
     */
    public function config(): array
    {
        return [
            'key_id'     => trim((string) $this->settings->get('customer_rzp_key_id', '')),
            'key_secret' => trim((string) $this->settings->get('customer_rzp_key_secret', '')),
        ];
    }

    public function isConfigured(): bool
    {
        $c = $this->config();

        return $c['key_id'] !== '' && $c['key_secret'] !== '';
    }

    /**
     * Empty key id clears the keys. A null/empty secret keeps the stored one.
     *
     * @return array{ok:bool,status:int,message:string}
     */
    public function saveConfig(string $keyId, ?string $secret): array
    {
        $keyId = trim($keyId);
        if ($keyId === '') {
            $this->settings->set('customer_rzp_key_id', '', 'payments');
            $this->settings->set('customer_rzp_key_secret', '', 'payments', true);

            return ['ok' => true, 'status' => 200, 'message' => 'Payment keys removed.'];
        }

        if (preg_match('/^rzp_(live|test)_[A-Za-z0-9]{6,40}$/', $keyId) !== 1) {
            return ['ok' => false, 'status' => 422, 'message' => 'Razorpay Key ID must look like rzp_live_xxxxxxxx or rzp_test_xxxxxxxx.'];
        }

        $secret = trim((string) $secret);
        if ($secret === '' && $this->config()['key_secret'] === '') {
            return ['ok' => false, 'status' => 422, 'message' => 'Enter the Razorpay Key Secret.'];
        }

        $this->settings->set('customer_rzp_key_id', $keyId, 'payments');
        if ($secret !== '') {
            $this->settings->set('customer_rzp_key_secret', $secret, 'payments', true);
        }

        return ['ok' => true, 'status' => 200, 'message' => 'Payment keys saved.'];
    }

    /**
     * @return array{ok:bool,status:int,message:string,data?:array{id:string,url:string,text:string}}
     */
    public function create(int $contactId, float $amount, string $description, ?int $userId): array
    {
        if (! $this->isConfigured()) {
            return $this->fail(422, 'Online payments are not set up. Add your Razorpay keys in Settings → Payments.');
        }

        $amount = round($amount, 2);
        if ($amount < self::MIN_AMOUNT || $amount > self::MAX_AMOUNT) {
            return $this->fail(422, 'Amount must be between ₹1 and ₹5,00,000.');
        }

        $contact = model(ContactModel::class)->find($contactId);
        if (! is_array($contact)) {
            return $this->fail(404, 'Contact not found.');
        }

        $description = trim(mb_substr(strip_tags($description), 0, 200));
        if ($description === '') {
            $description = 'Payment';
        }

        $res = $this->http('POST', '/payment_links', [
            'amount'          => (int) round($amount * 100),
            'currency'        => 'INR',
            'description'     => $description,
            'reference_id'    => 'c' . $contactId . '-' . time(),
            'expire_by'       => time() + self::EXPIRY_DAYS * 86400,
            'reminder_enable' => false,
            'notes'           => ['contact_id' => (string) $contactId],
        ]);

        if (! $res['ok'] || empty($res['data']['id']) || empty($res['data']['short_url'])) {
            return in_array($res['status'], [401, 403], true)
                ? $this->fail(502, 'Razorpay rejected the saved keys. Check them in Settings → Payments.')
                : $this->fail(502, 'Could not create the payment link. Please try again.');
        }

        $now = date('Y-m-d H:i:s');
        model(PaymentLinkModel::class)->insert([
            'contact_id'  => $contactId,
            'link_id'     => (string) $res['data']['id'],
            'short_url'   => (string) $res['data']['short_url'],
            'amount'      => $amount,
            'currency'    => 'INR',
            'description' => $description,
            'status'      => 'created',
            'created_by'  => $userId,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        log_activity('create', 'payments', 'Created payment link', [
            'contact_id' => $contactId,
            'amount'     => $amount,
            'link_id'    => (string) $res['data']['id'],
        ], $userId);

        $url = (string) $res['data']['short_url'];

        return $this->ok('Payment link created.', [
            'id'   => (string) $res['data']['id'],
            'url'  => $url,
            'text' => 'Please pay ₹' . number_format($amount, 2) . ' for ' . $description . ":\n" . $url,
        ]);
    }

    /**
     * Check open links with Razorpay and record the ones that were paid, expired or cancelled.
     *
     * @return int Links newly marked paid.
     */
    public function reconcilePending(int $limit = 100): int
    {
        if (! $this->isConfigured()) {
            return 0;
        }

        $rows = model(PaymentLinkModel::class)
            ->where('status', 'created')
            ->where('created_at >=', date('Y-m-d H:i:s', time() - self::WATCH_DAYS * 86400))
            ->orderBy('id', 'ASC')
            ->findAll($limit);

        $paid = 0;
        foreach ($rows as $row) {
            if ($this->refresh($row)) {
                $paid++;
            }
        }

        return $paid;
    }

    /**
     * @param array<string, mixed> $row A payment_links row.
     *
     * @return bool True when the link was just marked paid.
     */
    private function refresh(array $row): bool
    {
        $res = $this->http('GET', '/payment_links/' . rawurlencode((string) $row['link_id']));
        if (! $res['ok']) {
            return false;
        }

        $status = match (strtolower((string) ($res['data']['status'] ?? ''))) {
            'paid'      => 'paid',
            'expired'   => 'expired',
            'cancelled' => 'cancelled',
            default     => '',
        };
        if ($status === '' || $status === $row['status']) {
            return false;
        }

        $now    = date('Y-m-d H:i:s');
        $update = ['status' => $status, 'updated_at' => $now];

        if ($status === 'paid') {
            $payments = $res['data']['payments'] ?? [];
            $first    = is_array($payments) ? reset($payments) : false;
            $update['paid_at']    = $now;
            $update['payment_id'] = is_array($first) && ! empty($first['payment_id']) ? (string) $first['payment_id'] : null;
        }

        model(PaymentLinkModel::class)->update((int) $row['id'], $update);

        if ($status === 'paid') {
            $this->announcePaid($row, $update['payment_id'] ?? null);

            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function announcePaid(array $row, ?string $paymentId): void
    {
        $contact = model(ContactModel::class)->find((int) $row['contact_id']);
        $name    = is_array($contact) ? trim((string) ($contact['name'] ?? '') ?: (string) ($contact['mobile'] ?? '')) : 'A customer';
        $amount  = '₹' . number_format((float) $row['amount'], 2);

        log_activity('update', 'payments', 'Payment link paid', [
            'contact_id' => (int) $row['contact_id'],
            'amount'     => (float) $row['amount'],
            'link_id'    => (string) $row['link_id'],
        ], isset($row['created_by']) ? (int) $row['created_by'] : null);

        (new OutgoingWebhooks())->dispatch('payment.paid', [
            'link_id'    => (string) $row['link_id'],
            'contact_id' => (int) $row['contact_id'],
            'amount'     => (float) $row['amount'],
            'currency'   => (string) ($row['currency'] ?? 'INR'),
            'payment_id' => $paymentId,
            'paid_at'    => date('c'),
        ]);

        try {
            model(NotificationModel::class)->notifyChatUsers(
                'Payment received',
                $name . ' paid ' . $amount . (($row['description'] ?? '') !== '' ? ' for ' . $row['description'] : ''),
                site_url('chat?contact_id=' . (int) $row['contact_id']),
                is_array($contact) && ! empty($contact['assigned_to']) ? (int) $contact['assigned_to'] : null,
                'info'
            );
        } catch (Throwable $e) {
            log_message('warning', 'Payment notify failed: {msg}', ['msg' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array{ok:bool,status:int,data:array<string,mixed>}
     */
    private function http(string $method, string $path, ?array $body = null): array
    {
        $c = $this->config();
        try {
            $ch = curl_init(self::API . $path);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_USERPWD        => $c['key_id'] . ':' . $c['key_secret'],
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            }
            $raw    = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($raw === false) {
                log_message('error', 'Customer payment link {m} {p} transport error: {e}', ['m' => $method, 'p' => $path, 'e' => curl_error($ch)]);
            }
            $data = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
            if ($status < 200 || $status >= 300) {
                log_message('error', 'Customer payment link {m} {p} failed: HTTP {s} {e}', ['m' => $method, 'p' => $path, 's' => $status, 'e' => (string) ($data['error']['description'] ?? '')]);
            }

            return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'data' => is_array($data) ? $data : []];
        } catch (Throwable $e) {
            log_message('error', 'Customer payment link request failed: {msg}', ['msg' => $e->getMessage()]);

            return ['ok' => false, 'status' => 0, 'data' => []];
        }
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function ok(string $message, array $extra = []): array
    {
        return ['ok' => true, 'status' => 200, 'message' => $message, 'data' => $extra];
    }

    /**
     * @return array{ok:bool,status:int,message:string}
     */
    private function fail(int $status, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'message' => $message];
    }
}
