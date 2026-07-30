<?php

declare(strict_types=1);

/**
 * Deep timezone conversion checks (Settings TZ ↔ UTC storage ↔ display).
 *
 * Run: C:\wamp64\bin\php\php8.3.28\php.exe tests/AppTimezoneDeepTest.php
 */

define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);
chdir(FCPATH);

require FCPATH . '../app/Config/Paths.php';
$paths = new \Config\Paths();
require $paths->systemDirectory . '/Boot.php';
\CodeIgniter\Boot::bootSpark($paths);

use App\Libraries\AppDateTime;

$pass = 0;
$fail = 0;

function check(string $label, bool $condition, string $detail = ''): void
{
    global $pass, $fail;

    if ($condition) {
        $pass++;
        echo "[PASS] {$label}\n";

        return;
    }

    $fail++;
    echo "[FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

echo "=== App Timezone Deep Test ===\n\n";

helper('datetime');

// --- Asia/Kolkata conversions ---
AppDateTime::setTimezoneOverride('Asia/Kolkata');

$storage = AppDateTime::localToStorage('2026-07-30 15:30');
check(
    'Kolkata 15:30 → UTC storage 10:00',
    $storage === '2026-07-30 10:00:00',
    'got ' . var_export($storage, true)
);

$display = AppDateTime::format('2026-07-30 10:00:00', 'Y-m-d H:i');
check(
    'UTC 10:00 → Kolkata display 15:30',
    $display === '2026-07-30 15:30',
    'got ' . var_export($display, true)
);

$localInput = AppDateTime::toDatetimeLocal('2026-07-30 10:00:00');
check(
    'UTC → datetime-local in Kolkata',
    $localInput === '2026-07-30T15:30',
    'got ' . var_export($localInput, true)
);

[$dayStart, $dayEnd] = AppDateTime::dayBoundsUtc('2026-07-30');
check(
    'Kolkata day start is previous UTC evening',
    $dayStart === '2026-07-29 18:30:00',
    'got ' . var_export($dayStart, true)
);
check(
    'Kolkata day end is UTC afternoon',
    $dayEnd === '2026-07-30 18:29:59',
    'got ' . var_export($dayEnd, true)
);

check('helper format_app_datetime works', format_app_datetime('2026-07-30 10:00:00', 'g:i A') === '3:30 PM');
check('helper app_local_to_storage works', app_local_to_storage('2026-07-30T15:30') === '2026-07-30 10:00:00');
check('helper settings_timezone works', settings_timezone() === 'Asia/Kolkata');

// --- UTC identity ---
AppDateTime::setTimezoneOverride('UTC');
check('UTC localToStorage identity', AppDateTime::localToStorage('2026-07-30 12:00:00') === '2026-07-30 12:00:00');
check('UTC format identity', AppDateTime::format('2026-07-30 12:00:00', 'Y-m-d H:i:s') === '2026-07-30 12:00:00');

// --- America/New_York (DST-aware summer) ---
AppDateTime::setTimezoneOverride('America/New_York');
$ny = AppDateTime::localToStorage('2026-07-30 12:00');
check(
    'New York summer 12:00 → UTC 16:00',
    $ny === '2026-07-30 16:00:00',
    'got ' . var_export($ny, true)
);

// --- Invalid / empty ---
AppDateTime::setTimezoneOverride('Asia/Kolkata');
check('empty local → null', AppDateTime::localToStorage('') === null);
check('null local → null', AppDateTime::localToStorage(null) === null);
check('empty format → empty token', AppDateTime::format(null, 'Y-m-d', '—') === '—');
check('bad timezone falls back', (static function (): bool {
    AppDateTime::setTimezoneOverride('Not/A_Zone');
    $ok = AppDateTime::timezone() === 'UTC';
    AppDateTime::setTimezoneOverride('Asia/Kolkata');

    return $ok;
})());

// --- CampaignService.schedule conversion path (same as localToStorage) ---
AppDateTime::setTimezoneOverride('Asia/Kolkata');
$converted = AppDateTime::localToStorage('2026-07-30T15:30');
check(
    'Campaign schedule conversion path (datetime-local)',
    $converted === '2026-07-30 10:00:00',
    'got ' . var_export($converted, true)
);

// Optional live DB check when session is available
try {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        // Avoid spark CLI header conflict — conversion covered above.
        echo "[SKIP] CampaignService live schedule — CLI session unavailable\n";
    }
} catch (Throwable $e) {
    echo "[SKIP] CampaignService live schedule — " . $e->getMessage() . "\n";
}

// --- Settings value readable ---
$settingsTz = (string) (setting('app_timezone') ?: setting('timezone', 'UTC'));
check('settings timezone readable', $settingsTz !== '', 'got empty');
echo "    (current settings timezone: {$settingsTz})\n";

// Restore
AppDateTime::setTimezoneOverride(null);

echo "\n=== Result: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
