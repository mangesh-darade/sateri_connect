<?php

/**
 * Pure-logic checks for AdsBriefService: SSRF refusals, name suggestion and page extraction.
 * No database and no network.
 *
 * Run: php tests/AdsBriefUnitTest.php
 */

define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Libraries\AdsBriefService;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok): void
{
    global $pass, $fail;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
}

$svc = new AdsBriefService();

foreach ([
    'http://example.com'                => 'plain http',
    'https://localhost/'                => 'localhost',
    'https://127.0.0.1/'                => 'loopback ip',
    'https://10.0.0.5/'                 => 'private ip',
    'https://169.254.169.254/latest/'   => 'cloud metadata ip',
    'https://user:pw@example.com/'      => 'credentials in url',
    'https://example.com:8443/'         => 'non-standard port',
] as $url => $label) {
    $r = $svc->readPage($url);
    check('readPage refuses ' . $label, $r['ok'] === false && $r['status'] === 422);
}

check('suggestName cuts the title at a separator', str_starts_with($svc->suggestName('Acme Sweets | Best mithai in Pune'), 'Acme Sweets - '));
check('suggestName falls back when empty', str_starts_with($svc->suggestName(''), 'New campaign - '));
check('suggestName ends with month and year', str_ends_with($svc->suggestName('Acme'), date('M Y')));

$html = '<html lang="en-IN"><head><title>Acme Sweets</title><meta name="description" content="Fresh mithai delivered">'
    . '<style>.x{}</style></head><body><nav>MENU</nav><h1>Diwali boxes</h1><h2>Order now</h2>'
    . '<script>var a=1;</script><p>Handmade   sweets</p><footer>FOOT</footer></body></html>';
$m = new ReflectionMethod($svc, 'extract');
$m->setAccessible(true);
$x = $m->invoke($svc, $html);
check('extract title', $x['title'] === 'Acme Sweets');
check('extract description', $x['description'] === 'Fresh mithai delivered');
check('extract lang', $x['lang'] === 'en-in');
check('extract headings', $x['headings'] === ['Diwali boxes', 'Order now']);
check('extract text drops script, nav and footer', $x['text'] === 'Diwali boxes Order now Handmade sweets');

echo PHP_EOL . "$pass passed, $fail failed" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
