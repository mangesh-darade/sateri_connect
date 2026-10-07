<?php

/**
 * Settings helper functions.
 */

if (! function_exists('setting')) {
    /**
     * Read an application setting with optional default.
     */
    function setting(string $key, mixed $default = null): mixed
    {
        try {
            return service('settingsService')->get($key, $default);
        } catch (\Throwable $e) {
            log_message('debug', 'setting() helper fallback: {msg}', ['msg' => $e->getMessage()]);

            try {
                $model = model(\App\Models\SettingModel::class);
                if (method_exists($model, 'getValue')) {
                    return $model->getValue($key, $default);
                }

                $row = $model->where('key', $key)->first();

                return $row['value'] ?? $default;
            } catch (\Throwable $inner) {
                return $default;
            }
        }
    }
}

if (! function_exists('setting_asset_url')) {
    /**
     * Public URL for a stored branding asset path (logo / favicon), or empty string.
     */
    function setting_asset_url(string $key): string
    {
        $path = trim((string) setting($key, ''));
        if ($path === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        return base_url(ltrim(str_replace('\\', '/', $path), '/'));
    }
}

if (! function_exists('favicon_link_tags')) {
    /**
     * Favicon <link> tags: the uploaded icon when set, otherwise a generated badge with the
     * first letter of the page title (so the browser never falls back to /favicon.ico).
     */
    function favicon_link_tags(string $faviconUrl, string $title): string
    {
        if ($faviconUrl === '') {
            $letter = mb_strtoupper(mb_substr(trim($title), 0, 1)) ?: 'S';
            $svg    = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
                . '<rect width="64" height="64" rx="14" fill="#0b6e4f"/>'
                . '<text x="32" y="44" text-anchor="middle" font-family="Segoe UI,Arial,sans-serif" font-size="36" font-weight="700" fill="#fff">'
                . htmlspecialchars($letter, ENT_XML1 | ENT_QUOTES, 'UTF-8')
                . '</text></svg>';
            $faviconUrl = 'data:image/svg+xml,' . rawurlencode($svg);
        }

        $href = htmlspecialchars($faviconUrl, ENT_QUOTES, 'UTF-8');

        return '<link rel="icon" href="' . $href . '">' . "\n"
            . '    <link rel="shortcut icon" href="' . $href . '">' . "\n"
            . '    <link rel="apple-touch-icon" href="' . $href . '">';
    }
}

if (! function_exists('whatsapp_provider')) {
    /** Active WhatsApp transport: cheerio | meta */
    function whatsapp_provider(): string
    {
        try {
            return service('settingsService')->getWhatsAppProvider();
        } catch (\Throwable $e) {
            return 'cheerio';
        }
    }
}

if (! function_exists('is_cheerio_provider')) {
    function is_cheerio_provider(): bool
    {
        return whatsapp_provider() === 'cheerio';
    }
}

if (! function_exists('is_meta_provider')) {
    function is_meta_provider(): bool
    {
        return whatsapp_provider() === 'meta';
    }
}

if (! function_exists('whatsapp_provider_label')) {
    /** Full product label, e.g. "Meta Cloud API" */
    function whatsapp_provider_label(): string
    {
        return is_meta_provider() ? 'Meta Cloud API' : 'Cheerio Direct API';
    }
}

if (! function_exists('whatsapp_provider_short')) {
    /** Short UI label for buttons / badges: Meta | Cheerio */
    function whatsapp_provider_short(): string
    {
        return is_meta_provider() ? 'Meta' : 'Cheerio';
    }
}

if (! function_exists('whatsapp_provider_dashboard_url')) {
    function whatsapp_provider_dashboard_url(): string
    {
        return is_meta_provider()
            ? 'https://business.facebook.com/'
            : 'https://app.cheerio.in/';
    }
}

if (! function_exists('whatsapp_provider_dashboard_label')) {
    function whatsapp_provider_dashboard_label(): string
    {
        return is_meta_provider() ? 'Meta Business Manager' : 'Cheerio Dashboard';
    }
}

if (! function_exists('whatsapp_sync_label')) {
    /** e.g. "Sync from Meta" / "Sync from Cheerio" */
    function whatsapp_sync_label(string $noun = ''): string
    {
        $base = 'Sync from ' . whatsapp_provider_short();

        return $noun !== '' ? ($base . ' ' . $noun) : $base;
    }
}

if (! function_exists('email_provider')) {
    /** Active email transport: smtp | sendgrid | cheerio */
    function email_provider(): string
    {
        try {
            return service('settingsService')->getEmailProvider();
        } catch (\Throwable $e) {
            return 'smtp';
        }
    }
}

if (! function_exists('email_provider_label')) {
    function email_provider_label(): string
    {
        return match (email_provider()) {
            'sendgrid' => 'SendGrid',
            'cheerio'  => 'Cheerio Email API',
            default    => 'SMTP',
        };
    }
}

if (! function_exists('email_provider_short')) {
    function email_provider_short(): string
    {
        return match (email_provider()) {
            'sendgrid' => 'SendGrid',
            'cheerio'  => 'Cheerio',
            default    => 'SMTP',
        };
    }
}

if (! function_exists('is_smtp_email_provider')) {
    function is_smtp_email_provider(): bool
    {
        return email_provider() === 'smtp';
    }
}

if (! function_exists('is_sendgrid_email_provider')) {
    function is_sendgrid_email_provider(): bool
    {
        return email_provider() === 'sendgrid';
    }
}

if (! function_exists('is_cheerio_email_provider')) {
    function is_cheerio_email_provider(): bool
    {
        return email_provider() === 'cheerio';
    }
}

if (! function_exists('is_ai_enabled')) {
    /** Whether AI Assistant & Gemini Auto-Bot is enabled in Settings */
    function is_ai_enabled(): bool
    {
        try {
            return (bool) setting('ai_enabled', 0);
        } catch (\Throwable) {
            return false;
        }
    }
}
