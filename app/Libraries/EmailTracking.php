<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Builds public email tracking / unsubscribe URLs.
 *
 * URLs carry the tenant key (`t`) so public endpoints can resolve the client DB
 * without a session, and `{{email_url}}` so each driver personalizes per recipient.
 */
class EmailTracking
{
    public const RECIPIENT_TAG = '{{email_url}}';

    public static function openPixelHtml(int $logId, bool $perRecipient = true): string
    {
        if ($logId <= 0) {
            return '';
        }

        $url = self::withQuery('emails/track/open/' . $logId, $perRecipient ? ['e' => self::RECIPIENT_TAG] : []);

        return '<img src="' . str_replace('&', '&amp;', $url) . '" width="1" height="1" alt="" style="display:none !important;" />';
    }

    public static function unsubscribeUrl(int $campaignId, bool $perRecipient = true): string
    {
        $query = ['cid' => $campaignId > 0 ? (string) $campaignId : null];
        if ($perRecipient) {
            $query['email'] = self::RECIPIENT_TAG;
        }

        return self::withQuery('emails/unsubscribe', $query);
    }

    public static function sesWebhookUrl(?string $tenantKey = null): string
    {
        $tenantKey = trim((string) ($tenantKey ?? TenantContext::get() ?? ''));

        return site_url('webhooks/ses' . ($tenantKey !== '' ? '/' . rawurlencode($tenantKey) : ''));
    }

    /**
     * @param array<string, string|null> $query
     */
    protected static function withQuery(string $path, array $query): string
    {
        $tenant = trim((string) (TenantContext::get() ?? ''));
        if ($tenant !== '') {
            $query['t'] = $tenant;
        }

        $parts = [];
        foreach ($query as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            // Merge tag must stay literal so drivers can personalize it.
            $parts[] = $key . '=' . ($value === self::RECIPIENT_TAG ? $value : rawurlencode($value));
        }

        return site_url($path) . ($parts !== [] ? '?' . implode('&', $parts) : '');
    }
}
