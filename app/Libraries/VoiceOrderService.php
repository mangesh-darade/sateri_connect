<?php

declare(strict_types=1);

namespace App\Libraries;

use Throwable;

/**
 * Bridge for AI voice ordering (AVO): reads the menu and finds or creates the caller as a customer in ElintOm POS
 * through its Webshop_api (POST {elintom_base_url}/webshop_api/index, privatekey + action).
 * Uses the same ElintOm URL and private key as the customer sync (Settings > ElintOm POS).
 * Never throws: every method returns ['ok' => bool, 'status' => int, 'message' => string, 'data'? => array].
 */
class VoiceOrderService
{
    /** @var callable|null fn(string $url, array $fields): array{body: string, http: int, error: string}, replaced in tests */
    protected $transport;

    public function __construct(
        protected ?ElintOmCustomerSyncService $elintom = null,
        ?callable $transport = null,
    ) {
        $this->elintom ??= new ElintOmCustomerSyncService();
        $this->transport = $transport;
    }

    /**
     * Menu for the voice agent: categories and orderable products with price.
     *
     * @return array{ok: bool, status: int, message: string, data?: array{categories: list<array{id: int, name: string}>, items: list<array<string, mixed>>}}
     */
    public function menu(): array
    {
        $cat = $this->call('getcategories');
        if (! $cat['ok']) {
            return $cat;
        }
        $prod = $this->call('getproductslist');
        if (! $prod['ok']) {
            return $prod;
        }

        $categories = [];
        foreach ((array) ($cat['data']['categories']['main'] ?? []) as $id => $row) {
            $row          = (array) $row;
            $categories[] = ['id' => (int) ($row['id'] ?? $id), 'name' => (string) ($row['name'] ?? '')];
        }

        $items = [];
        foreach ((array) ($prod['data']['result']['items'] ?? []) as $row) {
            $row = (array) $row;
            if ((int) ($row['id'] ?? 0) <= 0) {
                continue;
            }
            $items[] = [
                'id'          => (int) $row['id'],
                'code'        => (string) ($row['code'] ?? ''),
                'name'        => (string) (($row['eshop_name'] ?? '') !== '' ? $row['eshop_name'] : ($row['name'] ?? '')),
                'price'       => (float) ($row['price'] ?? 0),
                'category_id' => (int) ($row['category_id'] ?? 0),
                'in_stock'    => (float) ($row['quantity'] ?? 1) > 0,
            ];
        }

        return $this->ok('Menu loaded.', ['categories' => $categories, 'items' => $items]);
    }

    /**
     * Find the caller by phone or create them.
     *
     * @return array{ok: bool, status: int, message: string, data?: array{customer_id: int, name: string, phone: string, created: bool}}
     */
    public function customer(string $phone, string $name = '', string $country = ''): array
    {
        $phone = normalize_phone($phone);
        if ($phone === '') {
            return $this->fail(422, 'A valid caller phone number is required.');
        }

        $found = $this->call('getcustomer', ['phone' => $phone], true);
        if ($found['ok']) {
            $c = (array) ($found['data']['customer'] ?? []);

            return $this->ok('Customer found.', [
                'customer_id' => (int) ($c['id'] ?? 0),
                'name'        => (string) ($c['name'] ?? ''),
                'phone'       => $phone,
                'created'     => false,
            ]);
        }
        if ($found['status'] !== 404) {
            return $found;
        }

        $created = $this->call('createcustomer', [
            'name'    => trim($name) !== '' ? trim($name) : 'Phone customer',
            'phone'   => $phone,
            'country' => $country,
        ]);
        if (! $created['ok']) {
            return $created;
        }
        $c = (array) ($created['data']['customer'] ?? []);

        return $this->ok('Customer created.', [
            'customer_id' => (int) ($c['id'] ?? 0),
            'name'        => (string) ($c['name'] ?? $name),
            'phone'       => $phone,
            'created'     => true,
        ]);
    }

    /**
     * Place the phone order in ElintOm. ElintOm computes prices and tax; we send only product ids and quantities.
     * The order is saved as pay-on-pickup and staff turn it into a KOT in ElintOm (Omnichannel Orders).
     *
     * @param list<array{product_id: int, quantity: float|int}> $items
     *
     * @return array{ok: bool, status: int, message: string, data?: array{order_id: int, reference_no: string, grand_total: float}}
     */
    public function order(int $customerId, array $items, string $note = '', string $callId = '', string $phone = '', string $deliveryType = ''): array
    {
        $lines = [];
        foreach ($items as $it) {
            $pid = (int) ($it['product_id'] ?? 0);
            $qty = (float) ($it['quantity'] ?? 1);
            if ($pid > 0 && $qty > 0 && $qty <= 99) {
                $lines[] = ['product_id' => $pid, 'quantity' => $qty];
            }
        }
        if ($customerId < 1 || $lines === []) {
            return $this->fail(422, 'A customer and at least one item with a valid quantity are required.');
        }

        $staffNote = 'AVO call' . ($callId !== '' ? ' ' . $callId : '') . ($phone !== '' ? ' from ' . $phone : '');
        $r = $this->call('phoneorder', [
            'customer_id'   => $customerId,
            'items'         => json_encode($lines),
            'note'          => $note,
            'staff_note'    => $staffNote,
            'delivery_type' => $deliveryType,
        ]);
        if (! $r['ok']) {
            return $r;
        }

        return $this->ok('Order placed.', [
            'order_id'     => (int) ($r['data']['order_id'] ?? 0),
            'reference_no' => (string) ($r['data']['reference_no'] ?? ''),
            'grand_total'  => (float) ($r['data']['grand_total'] ?? 0),
        ]);
    }

    /**
     * @param array<string, scalar|null> $fields
     * @param bool                       $notFoundOk when true an ElintOm NOT_FOUND answer becomes status 404 (not an error)
     *
     * @return array{ok: bool, status: int, message: string, data?: array<string, mixed>}
     */
    protected function call(string $action, array $fields = [], bool $notFoundOk = false): array
    {
        $cfg = $this->elintom->getConfig();
        if (! $this->elintom->isActive()) {
            return $this->fail(409, 'ElintOm integration is not set up. Save the URL and private key in Settings > ElintOm POS.');
        }

        $url = $cfg['base_url'] . '/webshop_api/index';
        try {
            $r = $this->send($url, ['privatekey' => $cfg['private_key'], 'action' => $action] + $fields);
        } catch (Throwable $e) {
            log_message('error', 'VoiceOrderService {action} failed: {msg}', ['action' => $action, 'msg' => $e->getMessage()]);

            return $this->fail(502, 'ElintOm could not be reached.');
        }
        if ($r['error'] !== '') {
            log_message('error', 'VoiceOrderService {action} transport error: {msg}', ['action' => $action, 'msg' => $r['error']]);

            return $this->fail(502, 'ElintOm could not be reached.');
        }

        $body = trim($r['body']);
        if (strncmp($body, "\xEF\xBB\xBF", 3) === 0) {
            $body = substr($body, 3);
        }
        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            log_message('error', 'VoiceOrderService {action}: invalid JSON, HTTP {http}', ['action' => $action, 'http' => $r['http']]);

            return $this->fail(502, 'ElintOm sent an unreadable answer.');
        }

        $status = strtoupper((string) ($decoded['status'] ?? ''));
        if ($status === 'NOT_FOUND' && $notFoundOk) {
            return $this->fail(404, (string) ($decoded['msg'] ?? 'Not found.'));
        }
        if ($status !== 'SUCCESS') {
            log_message('warning', 'VoiceOrderService {action}: ElintOm said {msg}', ['action' => $action, 'msg' => (string) ($decoded['msg'] ?? '')]);

            return $this->fail(502, 'ElintOm refused the request.');
        }

        return $this->ok('OK', $decoded);
    }

    /**
     * @param array<string, scalar|null> $fields
     *
     * @return array{body: string, http: int, error: string}
     */
    protected function send(string $url, array $fields): array
    {
        if ($this->transport !== null) {
            return ($this->transport)($url, $fields);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $raw  = curl_exec($ch);
        $err  = curl_errno($ch) ? curl_error($ch) : '';
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['body' => is_string($raw) ? $raw : '', 'http' => $http, 'error' => $err];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{ok: bool, status: int, message: string, data: array<string, mixed>}
     */
    protected function ok(string $message, array $data): array
    {
        return ['ok' => true, 'status' => 200, 'message' => $message, 'data' => $data];
    }

    /** @return array{ok: bool, status: int, message: string} */
    protected function fail(int $status, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'message' => $message];
    }
}
