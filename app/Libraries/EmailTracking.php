<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Builds public email tracking / unsubscribe URLs and the marketing footer.
 *
 * URLs carry the tenant key (`t`) so public endpoints can resolve the client DB
 * without a session, and `{{email_url}}` / `{{email_sig}}` so each driver
 * personalizes (and signs) them per recipient.
 */
class EmailTracking
{
    public const RECIPIENT_TAG = '{{email_url}}';
    public const SIGNATURE_TAG = '{{email_sig}}';

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
            $query['sig']   = self::SIGNATURE_TAG;
        }

        return self::withQuery('emails/unsubscribe', $query);
    }

    /**
     * Signed click-tracking URL: the redirector only follows targets signed for this log.
     */
    public static function clickUrl(int $logId, string $targetUrl, bool $perRecipient = true): string
    {
        $query = [
            'url' => $targetUrl,
            'sig' => EmailLinkSigner::clickSignature($logId, $targetUrl),
        ];
        if ($perRecipient) {
            $query['e'] = self::RECIPIENT_TAG;
        }

        return self::withQuery('emails/track/click/' . $logId, $query);
    }

    /**
     * Rewrites absolute http(s) links in HTML to signed click-tracking URLs.
     * Merge-tag links, the unsubscribe link and non-web schemes (mailto:, tel:, #) are left untouched.
     */
    public static function rewriteLinks(string $html, int $logId, bool $perRecipient = true): string
    {
        if ($logId <= 0 || stripos($html, 'href') === false) {
            return $html;
        }

        $skip = site_url('emails/');

        return (string) preg_replace_callback(
            '/(<a\b[^>]*?\bhref\s*=\s*)(["\'])(.*?)\2/is',
            static function (array $m) use ($logId, $perRecipient, $skip): string {
                $url = html_entity_decode(trim($m[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (str_contains($url, '{{') || str_starts_with($url, $skip) || ! EmailLinkSigner::isSafeRedirectUrl($url)) {
                    return $m[0];
                }

                return $m[1] . $m[2] . htmlspecialchars(self::clickUrl($logId, $url, $perRecipient), ENT_QUOTES, 'UTF-8') . $m[2];
            },
            $html
        );
    }

    /**
     * Inserts the unsubscribe link (into `{{unsubscribe_url}}` or a footer) plus the sender's postal address (CAN-SPAM).
     */
    public static function applyMarketingFooter(string $body, string $unsubUrl, bool $isHtml = true): string
    {
        $address = self::companyAddress();

        if (! $isHtml) {
            $body = str_replace('{{unsubscribe_url}}', $unsubUrl, $body, $replaced);
            $lines = [];
            if ($replaced === 0) {
                $lines[] = 'To stop receiving these emails, unsubscribe: ' . $unsubUrl;
            }
            if ($address !== '') {
                $lines[] = $address;
            }

            return $lines === [] ? $body : rtrim($body) . "\n\n--\n" . implode("\n", $lines);
        }

        $safeUrl = htmlspecialchars($unsubUrl, ENT_QUOTES, 'UTF-8');
        $body    = str_replace('{{unsubscribe_url}}', $safeUrl, $body, $replaced);

        $parts = [];
        if ($replaced === 0) {
            $parts[] = 'To stop receiving these emails, <a href="' . $safeUrl . '" style="color: #64748b; text-decoration: underline;">unsubscribe here</a>.';
        }
        if ($address !== '') {
            $parts[] = nl2br(htmlspecialchars($address, ENT_QUOTES, 'UTF-8'), false);
        }
        if ($parts === []) {
            return $body;
        }

        return $body . '<div style="margin-top: 32px; padding-top: 16px; border-top: 1px solid #e2e8f0; font-size: 11px; color: #94a3b8; text-align: center;">'
            . implode('<br>', $parts)
            . '</div>';
    }

    /**
     * Sender's physical postal address for the marketing footer (Settings → Email Settings).
     */
    public static function companyAddress(): string
    {
        try {
            return trim((string) service('settingsService')->get('email_company_address', ''));
        } catch (\Throwable) {
            return '';
        }
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
            // Merge tags must stay literal so drivers can personalize them.
            $literal = $value === self::RECIPIENT_TAG || $value === self::SIGNATURE_TAG;
            $parts[] = $key . '=' . ($literal ? $value : rawurlencode($value));
        }

        return site_url($path) . ($parts !== [] ? '?' . implode('&', $parts) : '');
    }
}
