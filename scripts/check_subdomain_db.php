<?php

declare(strict_types=1);

/**
 * Functional smoke: subdomain DB boot ignores .env and applies switch.
 */
define('ENVIRONMENT', 'development');
define('APPPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR);
define('ROOTPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);

if (! function_exists('env')) {
    function env($key, $default = null)
    {
        return $default;
    }
}

require dirname(__DIR__) . '/app/Libraries/SubdomainDatabase.php';

use App\Libraries\SubdomainDatabase;

$_SERVER['HTTP_HOST'] = 'localhost';
$tenant = SubdomainDatabase::resolve();
if ($tenant !== 'localhost') {
    fwrite(STDERR, "FAIL resolve localhost got={$tenant}\n");
    exit(1);
}

$_SERVER['HTTP_HOST'] = 'herbinn.elintpos.in';
$tenant = SubdomainDatabase::detectSubdomain();
if ($tenant !== 'herbinn') {
    fwrite(STDERR, "FAIL detect herbinn got={$tenant}\n");
    exit(1);
}

$_SERVER['HTTP_HOST'] = '127.0.0.1';
$tenant = SubdomainDatabase::detectSubdomain();
if ($tenant !== 'localhost') {
    fwrite(STDERR, "FAIL detect IP got={$tenant}\n");
    exit(1);
}

$default = SubdomainDatabase::defaultConnection();
if ($default['database'] !== '' || $default['username'] !== '') {
    fwrite(STDERR, "FAIL defaults should be empty credentials\n");
    exit(1);
}

echo "OK subdomain detect + empty defaults\n";
