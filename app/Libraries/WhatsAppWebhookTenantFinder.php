<?php

declare(strict_types=1);

namespace App\Libraries;

use Throwable;

/**
 * Finds the client that owns a WhatsApp webhook when its phone_number_id has no route in the master yet.
 *
 * Routes are published when a number is connected through Meta. A client that only ever used the External
 * provider has none, so on the shared host its webhooks (messages and delivered / read statuses) were dropped.
 * The payload also carries the business display number, which the client saved in Settings → External, so the
 * first webhook is matched on that. Processing then stores the number id and publishes the route
 * (SettingsService::resolveProviderFromPhoneNumberId), so this scan runs once per number.
 *
 * Matching a client here grants nothing: the payload must still pass that client's signature check.
 */
class WhatsAppWebhookTenantFinder
{
    public function __construct(private ?MasterTenantRepository $master = null, private ?TenantConnection $connection = null)
    {
    }

    /** Digits only; '' when it cannot be a phone number. */
    public static function normalizeDisplayPhone(?string $displayPhone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $displayPhone) ?? '';

        return strlen($digits) >= 7 && strlen($digits) <= 15 ? $digits : '';
    }

    /**
     * Switch to the active client whose External display number equals $displayPhone.
     * Leaves the current client untouched and returns false when there is none.
     */
    public function useTenantForDisplayPhone(?string $displayPhone): bool
    {
        $digits = self::normalizeDisplayPhone($displayPhone);
        if ($digits === '' || ! MasterTenantRepository::masterConfigured()) {
            return false;
        }

        $foundKey = 'wa_display_tenant_' . sha1($digits);
        $missKey  = 'wa_display_none_' . sha1($digits);

        try {
            $master     = $this->master ?? new MasterTenantRepository();
            $connection = $this->connection ?? new TenantConnection($master);
            $snapshot   = $connection->capture();

            $cached = '';
            try {
                if (cache($missKey) !== null) {
                    return false; // looked for this number a moment ago
                }
                $cached = (string) cache($foundKey);
            } catch (Throwable) {
                $cached = '';
            }
            if ($cached !== '') {
                if ($connection->apply($cached, 'webhook') && $this->currentTenantHasDisplayPhone($digits)) {
                    return true;
                }
                $connection->restore($snapshot);
            }

            // A full scan opens every client database: cap it per caller.
            try {
                $ip = (string) service('request')->getIPAddress();
                if (service('throttler')->check('wa_display_scan_' . md5($ip), 10, MINUTE) === false) {
                    return false;
                }
            } catch (Throwable) {
                // No throttler (CLI): carry on.
            }

            foreach ($master->listActiveTenants() as $tenant) {
                $key = (string) ($tenant['key'] ?? '');
                try {
                    if ($key !== '' && $connection->apply($key, 'webhook') && $this->currentTenantHasDisplayPhone($digits)) {
                        try {
                            cache()->save($foundKey, $key, DAY);
                        } catch (Throwable) {
                        }

                        return true;
                    }
                } catch (Throwable $e) {
                    log_message('warning', 'WhatsAppWebhookTenantFinder: client {key} skipped: {msg}', [
                        'key' => $key,
                        'msg' => $e->getMessage(),
                    ]);
                }
            }

            $connection->restore($snapshot);
            try {
                cache()->save($missKey, 1, 10 * MINUTE);
            } catch (Throwable) {
            }
        } catch (Throwable $e) {
            log_message('error', 'WhatsAppWebhookTenantFinder::useTenantForDisplayPhone failed: {msg}', ['msg' => $e->getMessage()]);
        }

        return false;
    }

    /** Fresh settings: the shared SettingsService caches the previous client's values. */
    protected function currentTenantHasDisplayPhone(string $digits): bool
    {
        if (! db_connect()->tableExists('settings')) {
            return false;
        }
        $saved = self::normalizeDisplayPhone((string) (new SettingsService())->get('cheerio_display_phone', ''));

        return $saved !== '' && hash_equals($saved, $digits);
    }
}
