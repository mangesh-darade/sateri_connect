<?php

/**
 * Verifies Meta template header samples resolve back to the uploaded file,
 * including the localhost case where the sample is a provider media ID.
 */

define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);
chdir(FCPATH);

require FCPATH . '../app/Config/Paths.php';
$paths = new Config\Paths();

require $paths->systemDirectory . '/Boot.php';
\CodeIgniter\Boot::bootSpark($paths);

$pass = 0;
$fail = 0;

function check(string $label, bool $ok): void
{
    global $pass, $fail;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
}

echo "=== Meta Template Header Sample Test ===\n\n";

$model = model(\App\Models\MediaModel::class);

$filename = 'unit_' . bin2hex(random_bytes(6)) . '.png';
$relative = 'uploads/media/' . $filename;
$fullPath = WRITEPATH . 'uploads/media/' . $filename;
if (! is_dir(dirname($fullPath))) {
    mkdir(dirname($fullPath), 0755, true);
}
// 1x1 transparent PNG
file_put_contents($fullPath, base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
));

$waMediaId = '9' . random_int(100000000000000, 999999999999999);
$publicUrl = 'http://localhost/sateri_connect/public/media/serve/' . $filename;

$mediaId = $model->insert([
    'filename'      => $filename,
    'original_name' => 'unit.png',
    'mime_type'     => 'image/png',
    'size'          => filesize($fullPath),
    'path'          => $relative,
    'wa_media_id'   => $waMediaId,
    'url'           => $publicUrl,
    'uploaded_by'   => null,
]);

check('media row inserted', (int) $mediaId > 0);

$api = new \App\Libraries\MetaCloudAPI();
$ref = new ReflectionClass($api);

$findRow = $ref->getMethod('findMediaRowForSample');
$findRow->setAccessible(true);

$byUrl = $findRow->invoke($api, $publicUrl);
check('resolves media row by localhost serve URL', is_array($byUrl) && $byUrl['filename'] === $filename);

$byMediaId = $findRow->invoke($api, $waMediaId);
check('resolves media row by provider media ID', is_array($byMediaId) && $byMediaId['filename'] === $filename);

$byFilename = $findRow->invoke($api, $filename);
check('resolves media row by filename', is_array($byFilename) && $byFilename['filename'] === $filename);

check('unknown sample stays unresolved', $findRow->invoke($api, 'no_such_sample_12345') === null);

$resolveSample = $ref->getMethod('resolveSampleFile');
$resolveSample->setAccessible(true);

[$path, $mime, $isTemp] = $resolveSample->invoke($api, $waMediaId, 'IMAGE');
check('media ID resolves to the stored file', $path !== '' && is_file($path));
check('resolved file keeps stored mime type', $mime === 'image/png');
check('stored file is not treated as temporary', $isTemp === false);

// Regression: this is the exact combination that produced
// "Could not read the header sample file for this template."
$normalize = $ref->getMethod('normalizeCreateComponents');
$normalize->setAccessible(true);

$looksLikeHandle = $ref->getMethod('looksLikeUploadHandle');
$looksLikeHandle->setAccessible(true);
check('provider media ID is not mistaken for an upload handle', ! $looksLikeHandle->invoke($api, $waMediaId));

$resolveHandle = $ref->getMethod('resolveTemplateHeaderHandle');
$resolveHandle->setAccessible(true);

$failedBeforeFix = false;
try {
    // No credentials in unit context, so the upload call fails — but it must fail
    // while uploading, not while locating the sample file.
    $resolveHandle->invoke($api, $waMediaId, 'IMAGE', []);
} catch (Throwable $e) {
    $failedBeforeFix = str_contains($e->getMessage(), 'Could not read the header sample file');
}
check('header handle no longer fails at sample lookup', ! $failedBeforeFix);

$fallbackFailed = false;
try {
    $resolveHandle->invoke($api, 'unknown_sample_ref', 'IMAGE', [$publicUrl]);
} catch (Throwable $e) {
    $fallbackFailed = str_contains($e->getMessage(), 'Could not read the header sample file');
}
check('falls back to header_url sample when handle is unknown', ! $fallbackFailed);

$stillThrows = false;
try {
    $resolveHandle->invoke($api, 'unknown_sample_ref', 'IMAGE', []);
} catch (Throwable $e) {
    $stillThrows = str_contains($e->getMessage(), 'Could not read the header sample file');
}
check('genuinely missing sample still reports a clear error', $stillThrows);

$model->delete((int) $mediaId, true);
@unlink($fullPath);

echo "\n=== Result: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
