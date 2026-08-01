<?php

declare(strict_types=1);

/**
 * An unmapped subdomain must say so instead of showing a generic
 * "could not open a database connection" message on every screen.
 *
 * Run: php tests/TenantDatabaseGuardTest.php
 */

define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);
chdir(FCPATH);

require FCPATH . '../app/Config/Paths.php';
$paths = new \Config\Paths();
require $paths->systemDirectory . '/Boot.php';
\CodeIgniter\Boot::bootSpark($paths);

use App\Libraries\ErrorPresenter;
use App\Libraries\SubdomainDatabase;
use CodeIgniter\Database\Exceptions\DatabaseException;

$pass   = 0;
$fail   = 0;
$errors = [];

function check(string $label, bool $condition, string $detail = ''): void
{
    global $pass, $fail, $errors;

    if ($condition) {
        $pass++;
        echo "[PASS] {$label}\n";

        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? " — {$detail}" : '');
    echo "[FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

echo "=== Tenant database guard ===\n";

$generic = new DatabaseException('Unable to connect to the database.');

// A mapped tenant keeps the existing generic wording.
$_SERVER['HTTP_HOST'] = 'localhost';
putenv('database.tenant');
unset($_ENV['database.tenant'], $_SERVER['database.tenant']);

check('mapped tenant is reported as configured', SubdomainDatabase::isTenantConfigured());
$mapped = ErrorPresenter::present($generic, 500);
check(
    'mapped tenant keeps the generic connection message',
    str_contains($mapped['message'], 'could not open a database connection'),
    $mapped['message']
);

// An unmapped subdomain must name itself and point at the switch.
$_SERVER['HTTP_HOST'] = 'demoelintommetaapi.example.com';
check('unmapped subdomain resolves to its own key', SubdomainDatabase::resolve() === 'demoelintommetaapi');
check('unmapped tenant is reported as unconfigured', ! SubdomainDatabase::isTenantConfigured());

$unmapped = ErrorPresenter::present($generic, 500);
check('unmapped tenant message names the subdomain', str_contains($unmapped['message'], 'demoelintommetaapi'), $unmapped['message']);
check('unmapped tenant message points at the switch', str_contains($unmapped['message'], 'applyBySubdomain'), $unmapped['message']);
check('unmapped tenant still uses the database error screen', $unmapped['kind'] === 'database', (string) $unmapped['kind']);

// A precise server error must not be replaced by the tenant hint.
$_SERVER['HTTP_HOST'] = 'demoelintommetaapi.example.com';
$unknownDb = ErrorPresenter::present(new DatabaseException("Unknown database 'stadmin_demo'"), 500);
check('named missing database wins over the tenant hint', str_contains($unknownDb['message'], 'stadmin_demo'), $unknownDb['message']);

$denied = ErrorPresenter::present(new DatabaseException('Access denied for user'), 500);
check('access denied wins over the tenant hint', str_contains($denied['message'], 'login failed'), $denied['message']);

$_SERVER['HTTP_HOST'] = 'localhost';

echo "\n=== Result: {$pass} passed, {$fail} failed ===\n";

if ($errors !== []) {
    echo "\nFAILURES:\n";
    foreach ($errors as $error) {
        echo " - {$error}\n";
    }
}

exit($fail > 0 ? 1 : 0);
