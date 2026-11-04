<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\ContactModel;
use App\Models\StoreCartModel;
use RuntimeException;
use Throwable;

/**
 * WhatsApp abandoned cart reminders for WooCommerce and Shopify.
 *
 * The store posts to /webhooks/store/{platform}/{tenantKey} (signed with the shared secret from Settings):
 *  - WooCommerce: order created / updated. An unpaid order (pending, failed, checkout-draft) is a cart waiting
 *    for payment; once the order is paid (processing, completed, ...) the cart counts as recovered.
 *  - Shopify: checkouts create / update (with the abandoned checkout link) and orders create / paid.
 * remindDue() (cron carts:remind, also the browser tick) sends the approved template once the cart has been
 * quiet for the configured delay, through the normal consent / template guard / queue path.
 *
 * Services return ['ok', 'status', 'message', 'data'?].
 */
class CartRecoveryService
{
    public const PLATFORMS = ['woocommerce' => 'WooCommerce', 'shopify' => 'Shopify'];

    private const GROUP = 'cart_recovery';

    private const WOO_UNPAID = ['pending', 'failed', 'checkout-draft'];
    private const WOO_DROP   = ['cancelled', 'trash', 'refunded'];

    private SettingsService $settings;

    public function __construct(?SettingsService $settings = null)
    {
        $this->settings = $settings ?? service('settingsService');
    }

    /**
     * @return array{enabled: bool, delay_minutes: int, max_reminders: int, gap_hours: int, template_name: string, template_language: string, assume_optin: bool, country_code: string}
     */
    public function config(): array
    {
        $get = fn (string $k, mixed $d = '') => $this->settings->get(self::GROUP . '.' . $k, $d);

        return [
            'enabled'           => (string) $get('enabled', '0') === '1',
            'delay_minutes'     => max(2, min(10080, (int) $get('delay_minutes', 60))),
            'max_reminders'     => max(1, min(3, (int) $get('max_reminders', 2))),
            'gap_hours'         => max(1, min(168, (int) $get('gap_hours', 24))),
            'template_name'     => trim((string) $get('template_name', '')),
            'template_language' => trim((string) $get('template_language', 'en')) ?: 'en',
            'assume_optin'      => (string) $get('assume_optin', '0') === '1',
            'country_code'      => preg_replace('/\D+/', '', (string) $get('country_code', '91')) ?? '91',
        ];
    }

    /**
     * @param array<string, mixed> $in
     *
     * @return array{ok: bool, status: int, message: string}
     */
    public function saveConfig(array $in): array
    {
        $enabled = (string) ($in['enabled'] ?? '0') === '1';
        $name    = trim((string) ($in['template_name'] ?? ''));
        if ($enabled && ! preg_match('/^[a-z0-9_]{1,150}$/i', $name)) {
            return ['ok' => false, 'status' => 422, 'message' => 'Choose the approved WhatsApp template to send.'];
        }

        if ($enabled) {
            try {
                (new WhatsAppTemplateSendGuard())->resolveApprovedTemplate(null, $name, trim((string) ($in['template_language'] ?? 'en')) ?: 'en');
            } catch (RuntimeException $e) {
                return ['ok' => false, 'status' => 422, 'message' => 'This template cannot be used: ' . $e->getMessage()];
            }
        }

        $values = [
            'enabled'           => $enabled ? '1' : '0',
            'delay_minutes'     => (string) max(2, min(10080, (int) ($in['delay_minutes'] ?? 60))),
            'max_reminders'     => (string) max(1, min(3, (int) ($in['max_reminders'] ?? 2))),
            'gap_hours'         => (string) max(1, min(168, (int) ($in['gap_hours'] ?? 24))),
            'template_name'     => $name,
            'template_language' => trim((string) ($in['template_language'] ?? 'en')) ?: 'en',
            'assume_optin'      => (string) ($in['assume_optin'] ?? '0') === '1' ? '1' : '0',
            'country_code'      => substr(preg_replace('/\D+/', '', (string) ($in['country_code'] ?? '91')) ?? '', 0, 4),
        ];
        foreach ($values as $k => $v) {
            $this->settings->set(self::GROUP . '.' . $k, $v, self::GROUP, false);
        }
        // Shopify signs with its own secret (shown on Shopify's Webhooks page), so it is pasted here; blank keeps the saved one.
        $shopify = trim((string) ($in['shopify_secret'] ?? ''));
        if ($shopify !== '') {
            $this->settings->set(self::GROUP . '.shopify_secret', $shopify, self::GROUP, true);
        }

        return ['ok' => true, 'status' => 200, 'message' => 'Cart reminder settings saved.'];
    }

    /** Shared signing secret for the store webhooks; created on first use. */
    public function secret(bool $rotate = false): string
    {
        $key    = self::GROUP . '.webhook_secret';
        $secret = (string) $this->settings->get($key, '');
        if ($secret === '' || $rotate) {
            $secret = bin2hex(random_bytes(24));
            $this->settings->set($key, $secret, self::GROUP, true);
        }

        return $secret;
    }

    public function hasShopifySecret(): bool
    {
        return (string) $this->settings->get(self::GROUP . '.shopify_secret', '') !== '';
    }

    /**
     * @param array<string, string> $headers Lower-cased header name => value.
     *
     * @return array{ok: bool, status: int, message: string}
     */
    public function handleWebhook(string $platform, string $raw, array $headers): array
    {
        if (! isset(self::PLATFORMS[$platform])) {
            return ['ok' => false, 'status' => 404, 'message' => 'Unknown platform.'];
        }

        $sigHeader = $platform === 'shopify' ? 'x-shopify-hmac-sha256' : 'x-wc-webhook-signature';
        $given     = trim((string) ($headers[$sigHeader] ?? ''));
        $secret    = $platform === 'shopify' ? (string) $this->settings->get(self::GROUP . '.shopify_secret', '') : $this->secret();
        $expected  = base64_encode(hash_hmac('sha256', $raw, $secret, true));
        if ($secret === '' || $given === '' || ! hash_equals($expected, $given)) {
            $why = $secret === '' ? 'No signing key is saved for ' . self::PLATFORMS[$platform] . '.' : 'The signature did not match the saved signing key.';
            $this->note($platform, 'rejected', $why);
            log_message('warning', 'CartRecoveryService: ' . $platform . ' webhook rejected. ' . $why);

            return ['ok' => false, 'status' => 401, 'message' => 'Invalid signature.'];
        }

        $data = json_decode($raw, true);
        if (! is_array($data)) {
            // WooCommerce sends a form-encoded "webhook_id=.." ping when a webhook is saved.
            return ['ok' => true, 'status' => 200, 'message' => 'Pong.'];
        }

        try {
            $platform === 'shopify'
                ? $this->handleShopify($data, strtolower((string) ($headers['x-shopify-topic'] ?? '')))
                : $this->handleWoo($data, strtolower((string) ($headers['x-wc-webhook-topic'] ?? '')));
        } catch (Throwable $e) {
            log_message('error', 'CartRecoveryService::handleWebhook ' . $e::class . ': ' . $e->getMessage());
            $this->note($platform, 'failed', 'An event could not be saved. Check that the cart table is migrated.');

            return ['ok' => false, 'status' => 500, 'message' => 'Could not process the event.'];
        }
        $this->note($platform, 'ok', '');

        return ['ok' => true, 'status' => 200, 'message' => 'OK'];
    }

    /** Remembers the latest webhook outcome per store so the settings tab can show what is wrong. */
    private function note(string $platform, string $outcome, string $why): void
    {
        try {
            $key = self::GROUP . '.last_event.' . $platform;
            $old = json_decode((string) $this->settings->get($key, ''), true);
            // Successful events are frequent; refresh the stored time at most once a minute.
            if ($outcome === 'ok' && is_array($old) && ($old['outcome'] ?? '') === 'ok' && time() - (int) ($old['at'] ?? 0) < 60) {
                return;
            }
            $this->settings->set($key, json_encode(['outcome' => $outcome, 'why' => $why, 'at' => time()]), self::GROUP, false);
        } catch (Throwable) {
            // Never let status bookkeeping break the webhook response.
        }
    }

    /**
     * What needs attention, for the alert box on the settings tab.
     *
     * @return list<array{level: string, message: string}>
     */
    public function health(): array
    {
        $cfg = $this->config();
        $out = [];
        if (! $cfg['enabled']) {
            $out[] = ['level' => 'secondary', 'message' => 'Reminders are off. Turn them on in Reminder settings.'];
        } elseif ($cfg['template_name'] === '') {
            $out[] = ['level' => 'warning', 'message' => 'Choose the approved template to send.'];
        } else {
            try {
                (new WhatsAppTemplateSendGuard())->resolveApprovedTemplate(null, $cfg['template_name'], $cfg['template_language']);
            } catch (RuntimeException $e) {
                $out[] = ['level' => 'danger', 'message' => 'The template cannot be sent: ' . $e->getMessage()];
            }
        }
        try {
            $failed = (int) db_connect()->table('store_carts c')
                ->join('message_queue q', 'q.id = c.queue_id')
                ->where('q.status', 'failed')->where('c.last_reminded_at >=', date('Y-m-d H:i:s', time() - 86400))
                ->countAllResults();
            if ($failed > 0) {
                $out[] = ['level' => 'danger', 'message' => $failed . ' reminder(s) in the last 24 hours could not be delivered. See the reason in Carts.'];
            }
        } catch (Throwable) {
            // Table not migrated yet; the webhook alerts above already say so.
        }
        if (! $this->hasShopifySecret()) {
            $out[] = ['level' => 'secondary', 'message' => 'Shopify signing key is not set, so Shopify events are rejected.'];
        }
        foreach (self::PLATFORMS as $p => $label) {
            $last = json_decode((string) $this->settings->get(self::GROUP . '.last_event.' . $p, ''), true);
            if (! is_array($last)) {
                continue;
            }
            $when = date('d M H:i', (int) ($last['at'] ?? 0));
            if (($last['outcome'] ?? '') === 'rejected') {
                $out[] = ['level' => 'danger', 'message' => $label . ' sent an event that was rejected (' . $when . '). ' . ($last['why'] ?? '')];
            } elseif (($last['outcome'] ?? '') === 'failed') {
                $out[] = ['level' => 'danger', 'message' => $label . ': ' . ($last['why'] ?? 'An event failed.') . ' (' . $when . ')'];
            } else {
                $out[] = ['level' => 'success', 'message' => $label . ' connected. Last event received ' . $when . '.'];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $o WooCommerce REST order.
     */
    private function handleWoo(array $o, string $topic): void
    {
        $id = (string) ($o['id'] ?? '');
        if ($id === '' || $topic === 'order.deleted') {
            return;
        }
        $status = strtolower((string) ($o['status'] ?? ''));
        $phone  = $this->normalizePhone((string) ($o['billing']['phone'] ?? ''));

        if (in_array($status, self::WOO_DROP, true)) {
            $this->markDone('woocommerce', $id, '', 'skipped');

            return;
        }
        if (! in_array($status, self::WOO_UNPAID, true)) {
            $this->markDone('woocommerce', $id, $phone, 'recovered');

            return;
        }

        $url = trim((string) ($o['payment_url'] ?? ''));
        if ($url === '' && ! empty($o['order_key'])) {
            $origin = $this->origin((string) ($o['_links']['self'][0]['href'] ?? ''));
            $url    = $origin !== '' ? $origin . '/checkout/order-pay/' . $id . '/?pay_for_order=true&key=' . rawurlencode((string) $o['order_key']) : '';
        }
        $items = [];
        foreach ((array) ($o['line_items'] ?? []) as $li) {
            $items[] = trim((string) ($li['name'] ?? '')) . ' x' . (int) ($li['quantity'] ?? 1);
        }
        $cur = (string) ($o['currency'] ?? '');
        $this->upsert('woocommerce', $id, [
            'phone'         => $phone,
            'customer_name' => trim((string) ($o['billing']['first_name'] ?? '')),
            'items_text'    => implode(', ', $items),
            'total'         => trim($cur . ' ' . (string) ($o['total'] ?? '')),
            'amount'        => (float) ($o['total'] ?? 0),
            'checkout_url'  => $url,
        ]);
    }

    /**
     * @param array<string, mixed> $o Shopify checkout or order.
     */
    private function handleShopify(array $o, string $topic): void
    {
        if (str_starts_with($topic, 'orders/')) {
            $token = (string) ($o['checkout_token'] ?? '');
            $phone = $this->normalizePhone((string) ($o['phone'] ?? $o['billing_address']['phone'] ?? $o['customer']['phone'] ?? $o['customer']['default_address']['phone'] ?? ''));
            if ($token !== '') {
                $this->markDone('shopify', $token, $phone, 'recovered');
            } elseif ($phone !== '') {
                $this->markDone('shopify', '', $phone, 'recovered');
            }

            return;
        }

        $id = (string) ($o['token'] ?? $o['id'] ?? '');
        if ($id === '') {
            return;
        }
        $phone = $this->normalizePhone((string) ($o['phone'] ?? $o['billing_address']['phone'] ?? $o['shipping_address']['phone'] ?? $o['customer']['phone'] ?? $o['customer']['default_address']['phone'] ?? ''));
        if (! empty($o['completed_at'])) {
            $this->markDone('shopify', $id, $phone, 'recovered');

            return;
        }
        $items = [];
        foreach ((array) ($o['line_items'] ?? []) as $li) {
            $items[] = trim((string) ($li['title'] ?? $li['name'] ?? '')) . ' x' . (int) ($li['quantity'] ?? 1);
        }
        $this->upsert('shopify', $id, [
            'phone'         => $phone,
            'customer_name' => trim((string) ($o['customer']['first_name'] ?? $o['billing_address']['first_name'] ?? '')),
            'items_text'    => implode(', ', $items),
            'total'         => trim((string) ($o['currency'] ?? '') . ' ' . (string) ($o['total_price'] ?? '')),
            'amount'        => (float) ($o['total_price'] ?? 0),
            'checkout_url'  => (string) ($o['abandoned_checkout_url'] ?? ''),
        ]);
    }

    /**
     * @param array<string, mixed> $f
     */
    private function upsert(string $platform, string $externalId, array $f): void
    {
        $model = model(StoreCartModel::class);
        $now   = date('Y-m-d H:i:s');
        $row   = $model->where('platform', $platform)->where('external_id', $externalId)->first();

        $f['items_text']   = mb_substr((string) $f['items_text'], 0, 500);
        $f['checkout_url'] = mb_substr((string) $f['checkout_url'], 0, 1000);
        $f['last_activity_at'] = $now;

        if ($row === null) {
            $model->insert($f + ['platform' => $platform, 'external_id' => $externalId, 'status' => 'open', 'reminders_sent' => 0, 'created_at' => $now]);

            return;
        }
        if (in_array($row['status'], ['recovered', 'skipped'], true)) {
            return;
        }
        $model->update((int) $row['id'], $f);
    }

    private function markDone(string $platform, string $externalId, string $phone, string $status): void
    {
        if ($externalId === '' && $phone === '') {
            return;
        }
        // A fresh query builder per update: the model's shared builder would lose its WHERE after the first one.
        $scope = function () use ($platform, $externalId, $phone) {
            $b = db_connect()->table('store_carts')->where('platform', $platform)->whereIn('status', ['open', 'reminded']);
            if ($externalId !== '' && $phone !== '') {
                $b->groupStart()->where('external_id', $externalId)->orWhere('phone', $phone)->groupEnd();
            } elseif ($externalId !== '') {
                $b->where('external_id', $externalId);
            } else {
                $b->where('phone', $phone);
            }

            return $b;
        };

        if ($status === 'recovered') {
            // Paid before any reminder went out: not a recovery, so it must not count as one.
            $scope()->where('reminders_sent', 0)->update(['status' => 'skipped', 'last_error' => 'Paid before a reminder was sent.']);
        }
        $scope()->update(['status' => $status, 'recovered_at' => $status === 'recovered' ? date('Y-m-d H:i:s') : null]);
    }

    private function normalizePhone(string $raw): string
    {
        $d = preg_replace('/\D+/', '', $raw) ?? '';
        $d = ltrim($d, '0');
        $cc = $this->config()['country_code'];
        if (strlen($d) === 10 && $cc !== '') {
            $d = $cc . $d;
        }

        return strlen($d) >= 11 && strlen($d) <= 15 ? $d : '';
    }

    private function origin(string $url): string
    {
        $p = parse_url($url);

        return isset($p['scheme'], $p['host']) && in_array($p['scheme'], ['http', 'https'], true)
            ? $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '')
            : '';
    }

    /**
     * Sends the reminders that are due. Returns how many were queued.
     */
    public function remindDue(int $limit = 20): int
    {
        $cfg = $this->config();
        if (! $cfg['enabled'] || $cfg['template_name'] === '') {
            return 0;
        }

        $guard = new WhatsAppTemplateSendGuard();
        try {
            $tpl = $guard->resolveApprovedTemplate(null, $cfg['template_name'], $cfg['template_language']);
        } catch (RuntimeException $e) {
            log_message('warning', 'CartRecoveryService template: ' . $e->getMessage());

            return 0;
        }

        $model = model(StoreCartModel::class);
        $first = date('Y-m-d H:i:s', time() - $cfg['delay_minutes'] * 60);
        $again = date('Y-m-d H:i:s', time() - $cfg['gap_hours'] * 3600);
        $rows  = $model->where('phone !=', '')->where('reminders_sent <', $cfg['max_reminders'])
            ->groupStart()
                ->groupStart()->where('status', 'open')->where('last_activity_at <=', $first)->groupEnd()
                ->orGroupStart()->where('status', 'reminded')->where('last_reminded_at <=', $again)->groupEnd()
            ->groupEnd()
            ->orderBy('id', 'ASC')->findAll(max(1, $limit));

        $contacts = model(ContactModel::class);
        $consent  = service('whatsAppConsent');
        $queue    = new QueueService();
        $sent     = 0;

        foreach ($rows as $cart) {
            try {
                if ($cart['checkout_url'] === '') {
                    $this->fail((int) $cart['id'], 'skipped', 'No checkout link in the store event.');
                    continue;
                }
                $contact = $contacts->findOrCreateForChannel('whatsapp', (string) $cart['phone'], ['mobile' => (string) $cart['phone'], 'name' => (string) $cart['customer_name']]);
                $cid     = (int) $contact['id'];
                if ($cfg['assume_optin'] && ! $consent->isOptedOut($contact)) {
                    $consent->optIn($cid, 'checkout');
                    $contact = $contacts->find($cid) ?? $contact;
                }
                $check = $consent->templateSendCheck($contact, $tpl, null, null, true);
                if (! $check['ok']) {
                    if (in_array($check['reason'], ['messaging_limit'], true)) {
                        break; // try again on the next run
                    }
                    $this->fail((int) $cart['id'], 'skipped', mb_substr($check['message'], 0, 250));
                    continue;
                }

                $name = $cart['customer_name'] !== '' ? $cart['customer_name'] : 'there';
                $components = $guard->buildBodyComponents($tpl, [$name, $cart['items_text'] ?: 'your items', $cart['total'] ?: '-', $cart['checkout_url']]);
                $queueId = $queue->enqueue($cid, 'template', [
                    'template_name' => $cfg['template_name'],
                    'language'      => $cfg['template_language'],
                    'components'    => $components,
                ], null, 4);

                $model->update((int) $cart['id'], [
                    'status'           => 'reminded',
                    'reminders_sent'   => (int) $cart['reminders_sent'] + 1,
                    'last_reminded_at' => date('Y-m-d H:i:s'),
                    'queue_id'         => $queueId,
                    'last_error'       => '',
                ]);
                $sent++;
            } catch (Throwable $e) {
                log_message('error', 'CartRecoveryService::remindDue ' . $e::class . ': ' . $e->getMessage());
                $this->fail((int) $cart['id'], 'skipped', 'Could not send the reminder.');
            }
        }

        return $sent;
    }

    private function fail(int $id, string $status, string $message): void
    {
        model(StoreCartModel::class)->update($id, ['status' => $status, 'last_error' => mb_substr($message, 0, 255)]);
    }

    /**
     * @return array{rows: list<array<string, mixed>>, counts: array<string, int>, recovered_amount: float}
     */
    public function overview(int $limit = 100): array
    {
        $model  = model(StoreCartModel::class);
        $counts = ['open' => 0, 'reminded' => 0, 'recovered' => 0, 'skipped' => 0];
        $amount = 0.0;
        foreach (db_connect()->query('SELECT status, COUNT(*) c, COALESCE(SUM(amount), 0) a FROM store_carts GROUP BY status')->getResultArray() as $r) {
            $counts[(string) $r['status']] = (int) $r['c'];
            if ($r['status'] === 'recovered') {
                $amount = (float) $r['a'];
            }
        }

        // Delivery result of the queued reminder (sent / failed with the reason), from the message queue.
        $rows = db_connect()->table('store_carts c')
            ->select('c.*, q.status AS delivery_status, q.error_message AS delivery_error')
            ->join('message_queue q', 'q.id = c.queue_id', 'left')
            ->orderBy('c.id', 'DESC')->limit($limit)->get()->getResultArray();

        return ['rows' => $rows, 'counts' => $counts, 'recovered_amount' => $amount];
    }
}
