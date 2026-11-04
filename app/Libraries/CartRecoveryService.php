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

    /** Between products in the saved item text and in the message. */
    private const ITEM_SEP = ' · ';

    /** Note on a cart whose shopper emptied the cart; if items are added again the cart is live again. */
    private const NOTE_EMPTY = 'Cart is empty.';

    /** Products named in one reminder; the rest are counted ("+2 more"). */
    private const MESSAGE_ITEMS = 3;

    private const WOO_UNPAID = ['pending', 'failed', 'checkout-draft'];
    private const WOO_DROP   = ['cancelled', 'trash', 'refunded'];

    private SettingsService $settings;

    public function __construct(?SettingsService $settings = null)
    {
        $this->settings = $settings ?? service('settingsService');
    }

    /**
     * @return array{enabled: bool, delay_minutes: int, max_reminders: int, gap_hours: int, template_name: string, template_language: string, assume_optin: bool, country_code: string, sync_customers: bool, customer_optin: bool}
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
            'sync_customers'    => (string) $get('sync_customers', '1') === '1',
            'customer_optin'    => (string) $get('customer_optin', '0') === '1',
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
            'sync_customers'    => (string) ($in['sync_customers'] ?? '0') === '1' ? '1' : '0',
            'customer_optin'    => (string) ($in['customer_optin'] ?? '0') === '1' ? '1' : '0',
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

        $topic = strtolower((string) ($headers[$platform === 'shopify' ? 'x-shopify-topic' : 'x-wc-webhook-topic'] ?? ''));
        try {
            $platform === 'shopify' ? $this->handleShopify($data, $topic) : $this->handleWoo($data, $topic);
        } catch (Throwable $e) {
            log_message('error', 'CartRecoveryService::handleWebhook ' . $e::class . ': ' . $e->getMessage());
            $this->note($platform, 'failed', 'An event could not be saved. Check the server log (and that the cart table is migrated).');

            return ['ok' => false, 'status' => 500, 'message' => 'Could not process the event.'];
        }
        $this->note($platform, 'ok', '');

        return ['ok' => true, 'status' => 200, 'message' => 'OK'];
    }

    /**
     * Saves the customer on a checkout or order as a contact (phone required). Never fails the webhook.
     *
     * @param array<string, mixed> $o
     */
    private function contactFromOrder(string $platform, array $o): void
    {
        try {
            $res = (new StoreCustomerSync($this))->fromOrder($platform, $o);
            if ($res['message'] !== 'Customer sync is off.' && ! str_starts_with($res['message'], 'Skipped')) {
                $this->settings->set(self::GROUP . '.last_customer', json_encode(['platform' => $platform, 'ok' => $res['ok'], 'message' => $res['message'] . ' (from checkout)', 'at' => time()]), self::GROUP, false);
            }
        } catch (Throwable $e) {
            log_message('warning', 'CartRecoveryService::contactFromOrder ' . $e::class);
        }
    }

    /**
     * @param array<string, mixed> $c
     */
    private function saveCustomer(string $platform, array $c): void
    {
        $res = (new StoreCustomerSync($this))->sync($platform, $c);
        // Shown in the settings tab so "I signed up but no contact appeared" can be answered (usually: no phone).
        try {
            $this->settings->set(self::GROUP . '.last_customer', json_encode(['platform' => $platform, 'ok' => $res['ok'], 'message' => $res['message'], 'at' => time()]), self::GROUP, false);
        } catch (Throwable) {
            // Bookkeeping only.
        }
        if (! $res['ok']) {
            throw new RuntimeException($res['message']);
        }
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
        if ($cfg['enabled']) {
            $run = json_decode((string) $this->settings->get(self::GROUP . '.last_run', ''), true);
            if (! is_array($run)) {
                $out[] = ['level' => 'warning', 'message' => 'The reminder check has not run yet. Keep any Sateri Connect page open (it runs about once a minute) or add the carts:remind cron job.'];
            } else {
                $when = $this->localTime((int) ($run['at'] ?? 0));
                if (time() - (int) ($run['at'] ?? 0) > 900) {
                    $out[] = ['level' => 'warning', 'message' => 'The reminder check last ran at ' . $when . '. Keep a Sateri Connect page open or add the carts:remind cron job.'];
                } elseif (($run['why'] ?? '') !== '') {
                    $out[] = ['level' => 'danger', 'message' => 'Reminder check at ' . $when . ': ' . (int) ($run['due'] ?? 0) . ' due, ' . (int) ($run['sent'] ?? 0) . ' queued. ' . $run['why']];
                } elseif ((int) ($run['due'] ?? 0) > 0) {
                    $out[] = ['level' => 'success', 'message' => 'Reminder check at ' . $when . ': ' . (int) ($run['due'] ?? 0) . ' due, ' . (int) ($run['sent'] ?? 0) . ' queued.'];
                } else {
                    $out[] = ['level' => 'secondary', 'message' => 'Reminder check ran at ' . $when . '. Nothing was due yet.'];
                }
            }
        }
        $cust = json_decode((string) $this->settings->get(self::GROUP . '.last_customer', ''), true);
        if (is_array($cust)) {
            $label = self::PLATFORMS[$cust['platform'] ?? ''] ?? 'Store';
            $when  = $this->localTime((int) ($cust['at'] ?? 0));
            $out[] = ['level' => ! empty($cust['ok']) && str_starts_with((string) ($cust['message'] ?? ''), 'Contact') ? 'success' : (! empty($cust['ok']) ? 'secondary' : 'danger'), 'message' => 'Last customer signup from ' . $label . ' (' . $when . '): ' . ($cust['message'] ?? '')];
        }
        if (! $this->hasShopifySecret()) {
            $out[] = ['level' => 'secondary', 'message' => 'Shopify signing key is not set, so Shopify events are rejected.'];
        }
        foreach (self::PLATFORMS as $p => $label) {
            $last = json_decode((string) $this->settings->get(self::GROUP . '.last_event.' . $p, ''), true);
            if (! is_array($last)) {
                continue;
            }
            $when = $this->localTime((int) ($last['at'] ?? 0));
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
        if (str_starts_with($topic, 'customer.')) {
            $this->saveCustomer('woocommerce', $o);

            return;
        }
        $id = (string) ($o['id'] ?? '');
        if ($id === '' || $topic === 'order.deleted') {
            return;
        }
        $status = strtolower((string) ($o['status'] ?? ''));
        $phone  = $this->normalizePhone((string) ($o['billing']['phone'] ?? ''));
        if ($phone !== '' && ! in_array($status, self::WOO_DROP, true)) {
            $this->contactFromOrder('woocommerce', $o);
        }

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
            $items[] = $this->shortName((string) ($li['name'] ?? '')) . ' x' . (int) ($li['quantity'] ?? 1);
        }
        $cur = (string) ($o['currency'] ?? '');
        $this->upsert('woocommerce', $id, [
            'phone'         => $phone,
            'customer_name' => trim((string) ($o['billing']['first_name'] ?? '')),
            'items_text'    => implode(self::ITEM_SEP, $items),
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
        if (str_starts_with($topic, 'customers/')) {
            $this->saveCustomer('shopify', $o);

            return;
        }
        if (str_starts_with($topic, 'orders/')) {
            $token = (string) ($o['checkout_token'] ?? '');
            $phone = $this->normalizePhone((string) ($o['phone'] ?? $o['billing_address']['phone'] ?? $o['customer']['phone'] ?? $o['customer']['default_address']['phone'] ?? ''));
            if ($phone !== '') {
                $this->contactFromOrder('shopify', $o);
            }
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
        if ($phone !== '') {
            $this->contactFromOrder('shopify', $o);
        }
        if (! empty($o['completed_at'])) {
            $this->markDone('shopify', $id, $phone, 'recovered');

            return;
        }
        $items = [];
        foreach ((array) ($o['line_items'] ?? []) as $li) {
            $qty = (int) ($li['quantity'] ?? 1);
            if ($qty > 0) {
                $items[] = $this->shortName((string) ($li['title'] ?? $li['name'] ?? '')) . ' x' . $qty;
            }
        }
        if ($items === []) {
            // The shopper emptied the cart: nothing to remind about.
            $this->markDone('shopify', $id, '', 'skipped', self::NOTE_EMPTY);

            return;
        }
        // A new checkout can report total_price 0.00 before shipping/tax are worked out; fall back to the line total.
        $amount = 0.0;
        foreach (['total_price', 'subtotal_price', 'total_line_items_price'] as $k) {
            if ((float) ($o[$k] ?? 0) > 0) {
                $amount = (float) $o[$k];
                break;
            }
        }
        $this->upsert('shopify', $id, [
            'phone'         => $phone,
            'customer_name' => trim((string) ($o['customer']['first_name'] ?? $o['billing_address']['first_name'] ?? '')),
            'items_text'    => implode(self::ITEM_SEP, $items),
            'total'         => trim((string) ($o['currency'] ?? '') . ' ' . number_format($amount, 2, '.', '')),
            'amount'        => $amount,
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
        if ($row['status'] === 'skipped' && $row['last_error'] === self::NOTE_EMPTY) {
            $f['status']     = 'open';
            $f['last_error'] = '';
        } elseif (in_array($row['status'], ['recovered', 'skipped'], true)) {
            return;
        }
        $model->update((int) $row['id'], $f);
    }

    private function markDone(string $platform, string $externalId, string $phone, string $status, string $note = ''): void
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
        $update = ['status' => $status, 'recovered_at' => $status === 'recovered' ? date('Y-m-d H:i:s') : null];
        if ($note !== '') {
            $update['last_error'] = $note;
        }
        $scope()->update($update);
    }

    /**
     * The item list as one WhatsApp variable (no line breaks allowed there): the first products, then "+N more".
     */
    private function itemsForMessage(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return 'your items';
        }
        $parts = array_values(array_filter(array_map('trim', explode(self::ITEM_SEP, $text))));
        $shown = array_slice($parts, 0, self::MESSAGE_ITEMS);
        $more  = count($parts) - count($shown);

        return implode(self::ITEM_SEP, $shown) . ($more > 0 ? self::ITEM_SEP . '+' . $more . ' more' : '');
    }

    /**
     * A plain explanation for the delivery errors people meet most. Anything else is shown as Meta wrote it.
     */
    public static function deliveryHint(string $error): string
    {
        $e = mb_strtolower($error);
        if (str_contains($e, 'healthy ecosystem') || str_contains($e, '131049')) {
            return 'Meta did not deliver this marketing message to this number (it limits promotional messages to people who have not replied recently). Try another number or wait a day.';
        }
        if (str_contains($e, 're-engagement') || str_contains($e, '131047')) {
            return 'The customer has not messaged you in the last 24 hours, so only an approved template can be sent.';
        }
        if (str_contains($e, 'not a valid whatsapp') || str_contains($e, '131026')) {
            return 'This phone number is not on WhatsApp.';
        }

        return mb_substr($error, 0, 160);
    }

    /** Product name for the message: stores often use "Name | long description"; keep the name and cap the length. */
    private function shortName(string $title): string
    {
        $name = trim(explode(' | ', $title)[0]);

        return mb_strlen($name) > 40 ? rtrim(mb_substr($name, 0, 39)) . '…' : $name;
    }

    /** A phone number in the form WhatsApp needs (digits with country code), '' when it is not usable. */
    public function phoneFor(string $raw): string
    {
        return $this->normalizePhone($raw);
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
            $this->noteRun(0, 0, 'The template cannot be sent: ' . $e->getMessage());

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

        $queue = new QueueService();
        $sent  = 0;
        $why   = '';

        foreach ($rows as $cart) {
            $res = $this->deliver($cart, $cfg, $tpl, $guard, $queue);
            if ($res['ok']) {
                $sent++;
                continue;
            }
            if ($res['retry']) {
                $why = $res['message'];
                break; // try again on the next run
            }
            $this->fail((int) $cart['id'], 'skipped', mb_substr($res['message'], 0, 250));
            if ($res['status'] >= 500) {
                $why = $res['message'];
            }
        }
        $this->noteRun(count($rows), $sent, $why);

        return $sent;
    }

    /**
     * Sends one reminder now, ignoring the delay (the "Send now" button on a cart).
     *
     * @return array{ok: bool, status: int, message: string}
     */
    public function sendNow(int $cartId): array
    {
        $cart = model(StoreCartModel::class)->find($cartId);
        if ($cart === null) {
            return ['ok' => false, 'status' => 404, 'message' => 'Cart not found.'];
        }
        if ($cart['status'] === 'recovered') {
            return ['ok' => false, 'status' => 409, 'message' => 'This cart was already paid, so no reminder is needed.'];
        }
        $cfg = $this->config();
        if ($cfg['template_name'] === '') {
            return ['ok' => false, 'status' => 422, 'message' => 'Choose the approved template in Reminder settings first.'];
        }

        $guard = new WhatsAppTemplateSendGuard();
        try {
            $tpl = $guard->resolveApprovedTemplate(null, $cfg['template_name'], $cfg['template_language']);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'status' => 422, 'message' => 'The template cannot be sent: ' . $e->getMessage()];
        }

        $queue = new QueueService();
        $res   = $this->deliver($cart, $cfg, $tpl, $guard, $queue);
        if (! $res['ok']) {
            model(StoreCartModel::class)->update($cartId, ['last_error' => mb_substr($res['message'], 0, 255)]);

            return ['ok' => false, 'status' => $res['status'], 'message' => $res['message']];
        }

        try {
            $queue->processBatch(5); // send it now instead of waiting for the next tick
        } catch (Throwable $e) {
            log_message('warning', 'CartRecoveryService::sendNow queue ' . $e::class);
        }

        return ['ok' => true, 'status' => 200, 'message' => 'Reminder sent to WhatsApp. Check the delivery result in the cart list.'];
    }

    /**
     * Policy checks, template variables and queueing for one cart.
     *
     * @param array<string, mixed> $cart
     * @param array<string, mixed> $cfg
     * @param array<string, mixed> $tpl
     *
     * @return array{ok: bool, status: int, message: string, retry: bool}
     */
    private function deliver(array $cart, array $cfg, array $tpl, WhatsAppTemplateSendGuard $guard, QueueService $queue): array
    {
        try {
            if ((string) $cart['phone'] === '') {
                return ['ok' => false, 'status' => 422, 'message' => 'This cart has no phone number.', 'retry' => false];
            }
            if ((string) $cart['checkout_url'] === '') {
                return ['ok' => false, 'status' => 422, 'message' => 'No checkout link in the store event.', 'retry' => false];
            }

            $contacts = model(ContactModel::class);
            $consent  = service('whatsAppConsent');
            $contact  = $contacts->findOrCreateForChannel('whatsapp', (string) $cart['phone'], ['mobile' => (string) $cart['phone'], 'name' => (string) $cart['customer_name']]);
            $cid      = (int) $contact['id'];
            if ($cfg['assume_optin'] && ! $consent->isOptedOut($contact)) {
                $consent->optIn($cid, 'checkout');
                $contact = $contacts->find($cid) ?? $contact;
            }
            $check = $consent->templateSendCheck($contact, $tpl, null, null, true);
            if (! $check['ok']) {
                return ['ok' => false, 'status' => 422, 'message' => (string) $check['message'], 'retry' => $check['reason'] === 'messaging_limit'];
            }

            $name       = $cart['customer_name'] !== '' ? $cart['customer_name'] : 'there';
            $components = $guard->buildBodyComponents($tpl, [1 => $name, 2 => $this->itemsForMessage((string) $cart['items_text']), 3 => $cart['total'] ?: '-', 4 => $cart['checkout_url']]);
            $queueId    = $queue->enqueue($cid, 'template', [
                'template_name' => $cfg['template_name'],
                'language'      => $cfg['template_language'],
                'components'    => $components,
            ], null, 4);

            model(StoreCartModel::class)->update((int) $cart['id'], [
                'status'           => 'reminded',
                'reminders_sent'   => (int) $cart['reminders_sent'] + 1,
                'last_reminded_at' => date('Y-m-d H:i:s'),
                'queue_id'         => $queueId,
                'last_error'       => '',
            ]);

            return ['ok' => true, 'status' => 200, 'message' => 'Queued.', 'retry' => false];
        } catch (RuntimeException $e) {
            // Template variables the cart cannot fill, and similar rule failures: safe to show.
            return ['ok' => false, 'status' => 422, 'message' => $e->getMessage(), 'retry' => false];
        } catch (Throwable $e) {
            log_message('error', 'CartRecoveryService::deliver ' . $e::class . ': ' . $e->getMessage());

            return ['ok' => false, 'status' => 500, 'message' => 'Could not send the reminder. Check the server log.', 'retry' => false];
        }
    }

    /** A stored UTC time shown in the app timezone (Settings). */
    private function localTime(int $ts): string
    {
        return format_app_datetime(date('Y-m-d H:i:s', $ts), 'd M, g:i A');
    }

    /** Remembers the latest reminder check so the settings tab can show whether it runs and why nothing was sent. */
    private function noteRun(int $due, int $sent, string $why): void
    {
        try {
            $key = self::GROUP . '.last_run';
            $old = json_decode((string) $this->settings->get($key, ''), true);
            // Quiet ticks (nothing due) are rewritten at most every 5 minutes.
            if ($due === 0 && $why === '' && is_array($old) && time() - (int) ($old['at'] ?? 0) < 300) {
                return;
            }
            $this->settings->set($key, json_encode(['at' => time(), 'due' => $due, 'sent' => $sent, 'why' => $why]), self::GROUP, false);
        } catch (Throwable) {
            // Bookkeeping only.
        }
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
