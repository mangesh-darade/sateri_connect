<?php

/**
 * Checks that an uploaded file is matched against the template's media header
 * type consistently in the backend, the wizard UI, and the file picker.
 */

define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);

require dirname(__DIR__) . '/vendor/autoload.php';

$pass = 0;
$fail = 0;

function check(string $label, bool $ok): void
{
    global $pass, $fail;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
}

echo "=== WhatsApp Template Media Unit Test ===\n\n";

use App\Libraries\WhatsAppTemplateMedia as Media;

check('image header accepts png', Media::matchesHeaderType('image', 'image/png'));
check('image header accepts jpeg', Media::matchesHeaderType('image', 'image/jpeg'));
check('image header rejects mp4', ! Media::matchesHeaderType('image', 'video/mp4'));

check('video header accepts mp4', Media::matchesHeaderType('video', 'video/mp4'));
// Regression: mangesh_testing is a VIDEO template and a PNG was uploaded.
check('video header rejects png', ! Media::matchesHeaderType('video', 'image/png'));
check('video header rejects pdf', ! Media::matchesHeaderType('video', 'application/pdf'));

check('document header accepts pdf', Media::matchesHeaderType('document', 'application/pdf'));
check('document header accepts msword', Media::matchesHeaderType('document', 'application/msword'));
check('document header rejects png', ! Media::matchesHeaderType('document', 'image/png'));

check('text header accepts anything', Media::matchesHeaderType('text', 'image/png'));
check('unknown mime is not blocked', Media::matchesHeaderType('video', ''));
check('header type is case insensitive', Media::matchesHeaderType('VIDEO', 'video/mp4'));

check('image is a media header', Media::isMediaHeader('image'));
check('text is not a media header', ! Media::isMediaHeader('text'));
check('none is not a media header', ! Media::isMediaHeader('none'));

check('video accept limits picker to video', Media::acceptAttribute('video') === 'video/mp4,video/3gpp');
check('document accept limits picker to pdf', Media::acceptAttribute('document') === 'application/pdf');
check('image accept excludes video', ! str_contains(Media::acceptAttribute('image'), 'video/'));
check('unknown header falls back to all types', str_contains(Media::acceptAttribute('none'), 'application/pdf'));

$message = Media::mismatchMessage('mangesh_testing', 'video', 'image/png');
check('mismatch message names the template', str_contains($message, 'mangesh_testing'));
check('mismatch message states what is needed', str_contains($message, 'an MP4 video'));
check('mismatch message states what was uploaded', str_contains($message, 'image/png'));
check('mismatch message handles a blank template name', str_contains(
    Media::mismatchMessage('', 'document', 'image/png'),
    'this template'
));

$campaigns = file_get_contents(dirname(__DIR__) . '/app/Controllers/Campaigns.php');
check('controller uses the shared header rule', str_contains($campaigns, 'WhatsAppTemplateMedia::matchesHeaderType'));
check('controller uses the shared message', str_contains($campaigns, 'WhatsAppTemplateMedia::mismatchMessage'));
check('controller no longer inlines the mime match', ! str_contains($campaigns, '$mimeOk = match'));
check('wizard payload exposes accept', str_contains($campaigns, "'media_accept'"));
check('wizard payload exposes expected label', str_contains($campaigns, "'media_expected'"));

$js = file_get_contents(dirname(__DIR__) . '/public/assets/js/campaigns.js');
check('wizard mirrors the header rule', str_contains($js, 'function headerMediaMismatch'));
check('wizard blocks the file before uploading', str_contains($js, 'var mismatch = headerMediaMismatch(mime)'));
check('file picker is limited to the header type', str_contains($js, "attr('accept', tpl.media_accept"));
check('upload hint uses the expected label', str_contains($js, 'tpl.media_expected'));

echo "\n=== Result: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
