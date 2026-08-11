<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * WhatsApp transport configuration (Cheerio Direct API + Meta Graph).
 *
 * Credentials are stored encrypted in the settings table and loaded via SettingsService.
 * Active provider is selected with settings key `whatsapp_provider` = cheerio|meta.
 */
class WhatsApp extends BaseConfig
{
    /**
     * Cheerio Direct APIs base URL (no trailing slash required).
     */
    public string $baseUrl = 'https://newprod.api.cheerio.in/direct-apis';

    /**
     * Meta Graph API base URL.
     */
    public string $graphBaseUrl = 'https://graph.facebook.com';

    /**
     * Default Meta Graph API version when not set in settings.
     */
    public string $graphApiVersion = 'v25.0';

    /**
     * Default HTTP timeout in seconds.
     */
    public int $defaultTimeout = 30;

    /**
     * Maximum number of HTTP retries for transient failures (5xx / network).
     */
    public int $maxRetries = 3;

    /**
     * Seconds to wait between retries (exponential backoff base).
     */
    public int $retryDelaySeconds = 1;

    /**
     * SSL certificate verification for HTTPS calls.
     *
     * - true: use PHP/cURL defaults
     * - false: disable verification (local debugging only — never in production)
     * - string: absolute path to a CA bundle (cacert.pem)
     * - null: auto-detect (php.ini → env → writable/certs/cacert.pem → true)
     */
    public bool|string|null $sslVerify = null;
}
