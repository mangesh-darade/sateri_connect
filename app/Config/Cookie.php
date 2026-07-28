<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;
use DateTimeInterface;

class Cookie extends BaseConfig
{
    public string $prefix = '';

    /**
     * @var DateTimeInterface|int|string
     */
    public $expires = 0;

    public string $path = '/';

    public string $domain = '';

    /**
     * Cookie will only be set if a secure HTTPS connection exists.
     * Forced true when ENVIRONMENT === production.
     */
    public bool $secure = false;

    public bool $httponly = true;

    /**
     * @var ''|'Lax'|'None'|'Strict'
     */
    public string $samesite = 'Lax';

    public bool $raw = false;

    public function __construct()
    {
        parent::__construct();

        if (defined('ENVIRONMENT') && ENVIRONMENT === 'production') {
            $this->secure = true;
        }
    }
}
