<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * JWT authentication configuration for the REST API.
 */
class Jwt extends BaseConfig
{
    /**
     * HMAC signing secret. Prefer JWT_SECRET from .env; falls back to encryption.key.
     */
    public string $secret = '';

    /**
     * Signing algorithm (HS256 recommended for shared-secret tokens).
     */
    public string $algo = 'HS256';

    /**
     * Token time-to-live in seconds (default 24 hours).
     */
    public int $ttl = 86400;

    /**
     * Token issuer claim (optional).
     */
    public string $issuer = 'sateri_connect';

    public function __construct()
    {
        parent::__construct();

        $envSecret = env('JWT_SECRET', '');
        if (is_string($envSecret) && $envSecret !== '') {
            $this->secret = $envSecret;
        } elseif ($this->secret === '' && (! defined('ENVIRONMENT') || ENVIRONMENT !== 'production')) {
            $encryptionKey = config('Encryption')->key ?? '';
            $this->secret  = is_string($encryptionKey) ? $encryptionKey : '';
        }
    }
}
