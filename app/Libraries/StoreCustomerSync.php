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

    public function __construct(private ?CartRecoveryService $cart = null)
    {
        $this->cart = $this->cart ?? new CartRecoveryService();
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

        if ($phone === '') {
            return ['ok' => true, 'status' => 200, 'message' => 'Skipped: the customer has no phone number yet.'];
        }

        try {
            $contacts = model(ContactModel::class);
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
                $fields['source']  = $label . ' account';
                $updates['custom_fields'] = $fields;
            }
            if ($updates !== []) {
                $contacts->update($id, $updates);
            }

            $tag = model(TagModel::class)->findOrCreateByName($woo ? 'WooCommerce' : self::TAG);
            model(TagModel::class)->attachContact((int) $tag['id'], $id);

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
