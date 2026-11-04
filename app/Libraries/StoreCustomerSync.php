<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\ContactModel;
use App\Models\TagModel;
use Throwable;

/**
 * Store customer accounts (Shopify customers/create and customers/update, WooCommerce customer.created and
 * customer.updated) become Contacts. A contact needs a phone number to be reachable on WhatsApp, so a customer with
 * no phone is skipped; if they add one later the update event creates the contact then.
 *
 * Opt-in is never assumed: the contact is only marked opted in when the client says the signup asks for consent.
 *
 * Services return ['ok', 'status', 'message', 'data'?].
 */
class StoreCustomerSync
{
    public const TAG = 'Shopify';

    /** Tag on a contact that has an email but no phone yet (it cannot be reached on WhatsApp). */
    public const TAG_EMAIL_ONLY = 'Email only';

    public function __construct(private ?CartRecoveryService $cart = null)
    {
        $this->cart = $this->cart ?? new CartRecoveryService();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findByEmail(string $email): ?array
    {
        $row = model(ContactModel::class)->where('LOWER(email)', $email)->where('deleted_at', null)->orderBy('id', 'ASC')->first();

        return is_array($row) ? $row : null;
    }

    /**
     * A checkout or order carries the customer's details too (name, email and the phone they typed), so a customer
     * who signed up with only an email still becomes a contact once they give a phone number at checkout.
     *
     * @param array<string, mixed> $o Shopify checkout / order or WooCommerce order payload.
     *
     * @return array{ok: bool, status: int, message: string, data?: array<string, mixed>}
     */
    public function fromOrder(string $platform, array $o): array
    {
        if ($platform === 'woocommerce') {
            $b = (array) ($o['billing'] ?? []);

            return $this->sync($platform, [
                'first_name' => (string) ($b['first_name'] ?? ''),
                'last_name'  => (string) ($b['last_name'] ?? ''),
                'email'      => (string) ($b['email'] ?? $o['email'] ?? ''),
                'billing'    => ['phone' => (string) ($b['phone'] ?? '')],
                '_source'    => 'WooCommerce checkout',
            ]);
        }

        $cu = (array) ($o['customer'] ?? []);
        $sh = (array) ($o['shipping_address'] ?? []);
        $bi = (array) ($o['billing_address'] ?? []);

        return $this->sync($platform, [
            'first_name' => (string) ($cu['first_name'] ?? $sh['first_name'] ?? $bi['first_name'] ?? ''),
            'last_name'  => (string) ($cu['last_name'] ?? $sh['last_name'] ?? $bi['last_name'] ?? ''),
            'email'      => (string) ($o['email'] ?? $cu['email'] ?? ''),
            'phone'      => (string) ($o['phone'] ?? $sh['phone'] ?? $bi['phone'] ?? $cu['phone'] ?? $cu['default_address']['phone'] ?? ''),
            '_source'    => 'Shopify checkout',
        ]);
    }

    /**
     * @param array<string, mixed> $c Shopify customer or WooCommerce customer payload.
     *
     * @return array{ok: bool, status: int, message: string, data?: array<string, mixed>}
     */
    public function sync(string $platform, array $c): array
    {
        $cfg = $this->cart->config();
        if (! $cfg['sync_customers']) {
            return ['ok' => true, 'status' => 200, 'message' => 'Customer sync is off.'];
        }

        $woo   = $platform === 'woocommerce';
        $label = $woo ? 'WooCommerce' : 'Shopify';
        $first = trim((string) ($c['first_name'] ?? ($woo ? ($c['billing']['first_name'] ?? '') : '')));
        $last  = trim((string) ($c['last_name'] ?? ($woo ? ($c['billing']['last_name'] ?? '') : '')));
        $name  = trim($first . ' ' . $last);
        $email = strtolower(trim((string) ($c['email'] ?? '')));
        $email = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
        $phone = $this->cart->phoneFor((string) ($woo
            ? ($c['billing']['phone'] ?? '')
            : ($c['phone'] ?? $c['default_address']['phone'] ?? $c['addresses'][0]['phone'] ?? '')));

        if ($phone === '' && $email === '') {
            return ['ok' => true, 'status' => 200, 'message' => 'Skipped: the customer has neither a phone number nor an email.'];
        }

        try {
            $contacts = model(ContactModel::class);
            $tags     = model(TagModel::class);
            $source   = (string) ($c['_source'] ?? ($label . ' account'));

            // No phone yet: keep the customer as an email-only contact. It gets the phone later (below).
            if ($phone === '') {
                $existing = $this->findByEmail($email);
                $created  = $existing === null;
                if ($created) {
                    $id = (int) $contacts->insert([
                        'channel'       => 'whatsapp',
                        'name'          => $name !== '' ? $name : null,
                        'email'         => $email,
                        'status'        => 'active',
                        'custom_fields' => ['source' => $source],
                    ]);
                    if ($id <= 0) {
                        throw new \RuntimeException('Could not create the email-only contact.');
                    }
                } else {
                    $id = (int) $existing['id'];
                    if ($name !== '' && empty($existing['name'])) {
                        $contacts->update($id, ['name' => $name]);
                    }
                }
                $tags->attachContact((int) $tags->findOrCreateByName($woo ? 'WooCommerce' : self::TAG)['id'], $id);
                if (empty($existing['mobile'] ?? '')) {
                    $tags->attachContact((int) $tags->findOrCreateByName(self::TAG_EMAIL_ONLY)['id'], $id);
                }

                return ['ok' => true, 'status' => 200, 'message' => $created ? 'Contact created (email only, no phone yet).' : 'Contact updated (email only).', 'data' => ['contact_id' => $id, 'created' => $created]];
            }

            // The phone arrived: give it to the email-only contact of this customer instead of making a second one.
            if ($email !== '' && $contacts->findByMobile($phone) === null) {
                $mine = $this->findByEmail($email);
                if ($mine !== null && trim((string) ($mine['mobile'] ?? '')) === '') {
                    $upgrade = ['mobile' => $phone];
                    if ($contacts->findByChannelExternalId('whatsapp', $phone, true) === null) {
                        $upgrade['external_id'] = $phone;
                    }
                    if ($name !== '' && empty($mine['name'])) {
                        $upgrade['name'] = $name;
                    }
                    $contacts->update((int) $mine['id'], $upgrade);
                    $only = $tags->where('name', self::TAG_EMAIL_ONLY)->first();
                    if (is_array($only)) {
                        $tags->detachContact((int) $only['id'], (int) $mine['id']);
                    }
                }
            }

            $created  = false;
            $contact  = $contacts->findOrCreateForChannel('whatsapp', $phone, array_filter([
                'mobile' => $phone,
                'name'   => $name,
                'email'  => $email,
            ], static fn ($v): bool => $v !== ''), $created);
            $id = (int) $contact['id'];

            $updates = [];
            if ($email !== '' && empty($contact['email'])) {
                $updates['email'] = $email;
            }
            if ($name !== '' && empty($contact['name'])) {
                $updates['name'] = $name;
            }
            $fields = is_array($contact['custom_fields'] ?? null) ? $contact['custom_fields'] : [];
            if (empty($fields['source'])) {
                $fields['source']  = $source;
                $updates['custom_fields'] = $fields;
            }
            if ($updates !== []) {
                $contacts->update($id, $updates);
            }

            $tag = $tags->findOrCreateByName($woo ? 'WooCommerce' : self::TAG);
            $tags->attachContact((int) $tag['id'], $id);

            if ($cfg['customer_optin']) {
                $consent = service('whatsAppConsent');
                if (! $consent->isOptedOut($contact)) {
                    $consent->optIn($id, 'website_form'); // signup form on the store
                }
            }

            return ['ok' => true, 'status' => 200, 'message' => $created ? 'Contact created.' : 'Contact updated.', 'data' => ['contact_id' => $id, 'created' => $created]];
        } catch (Throwable $e) {
            log_message('error', 'StoreCustomerSync ' . $e::class . ': ' . $e->getMessage());

            return ['ok' => false, 'status' => 500, 'message' => 'Could not save the customer as a contact.'];
        }
    }
}
