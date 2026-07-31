<?php

/**
 * Unit checks for LocalMediaUrl + Meta media upload regex crash fix.
 */

define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);
require dirname(__DIR__) . '/vendor/autoload.php';

$pass = 0;
$fail = 0;

function check(string $label, bool $ok): void
{
    global $pass, $fail;
    if ($ok) {
        echo "[PASS] {$label}\n";
        $pass++;
    } else {
        echo "[FAIL] {$label}\n";
        $fail++;
    }
}

echo "=== Local Media URL Unit Test ===\n\n";

use App\Libraries\LocalMediaUrl;

$url = 'http://localhost/sateri_connect/public/media/serve/1785490684_1538474598a23b5e1eda.png';
check('parses serve filename', LocalMediaUrl::filenameFromUrl($url) === '1785490684_1538474598a23b5e1eda.png');
check('parses encoded filename', LocalMediaUrl::filenameFromUrl('https://demo.elintpos.in/media/serve/a%20b.png') === 'a b.png');
check('empty url returns empty', LocalMediaUrl::filenameFromUrl('') === '');
check('non-serve url returns empty', LocalMediaUrl::filenameFromUrl('https://cdn.example.com/x.png') === '');
check('detects localhost', LocalMediaUrl::isLocalHost($url));
check('detects 127.0.0.1', LocalMediaUrl::isLocalHost('http://127.0.0.1/media/serve/x.png'));
check('public host is not local', ! LocalMediaUrl::isLocalHost('https://cdn.example.com/x.png'));

$campaignSrc = file_get_contents(dirname(__DIR__) . '/app/Controllers/Campaigns.php');
$metaSrc = file_get_contents(dirname(__DIR__) . '/app/Libraries/MetaCloudAPI.php');
$cheerioSrc = file_get_contents(dirname(__DIR__) . '/app/Libraries/CheerioDirectAPI.php');

check('Campaigns uses LocalMediaUrl helper', str_contains($campaignSrc, 'LocalMediaUrl::filenameFromUrl'));
check('Meta uses LocalMediaUrl helper', str_contains($metaSrc, 'LocalMediaUrl::filenameFromUrl'));
check('Cheerio uses LocalMediaUrl helper', str_contains($cheerioSrc, 'LocalMediaUrl::filenameFromUrl'));
check('broken # delimiter pattern removed from Campaigns', ! preg_match("/preg_match\\('#\\/media\\/serve\\/\\(\\[\\^\\/\\?#\\]/", $campaignSrc));
check('Meta uploadMedia uses native cURL', str_contains($metaSrc, 'curl_init($url)') && str_contains($metaSrc, 'new \\CURLFile'));
check('Meta uploadMedia applies resolveSslVerify', str_contains($metaSrc, 'resolveSslVerify()') && str_contains($metaSrc, 'CURLOPT_CAINFO'));
check('Meta multipart avoids Guzzle-style numeric parts', ! str_contains($metaSrc, "\$multipart[] = [\n                                'name'"));
check('Meta extractApiError stringifies array details safely', str_contains($metaSrc, 'stringifyErrorDetails'));

$templatesSrc = file_get_contents(dirname(__DIR__) . '/app/Controllers/Templates.php');
check('header-media fails closed when Meta ID missing', str_contains($templatesSrc, 'WhatsApp upload failed'));

$js = file_get_contents(dirname(__DIR__) . '/public/assets/js/campaigns.js');
check('wizard rejects localhost media without Meta ID', str_contains($js, 'isLocalMediaUrl(url)'));

echo "\n=== Result: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
