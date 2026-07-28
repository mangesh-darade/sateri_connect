<?php

declare(strict_types=1);

/**
 * Unit checks for carousel template component builder.
 *
 * Run: php tests/TemplatesCarouselUnitTest.php
 */

define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);
chdir(FCPATH);

require FCPATH . '../app/Config/Paths.php';
$paths = new \Config\Paths();
require $paths->systemDirectory . '/Boot.php';
\CodeIgniter\Boot::bootSpark($paths);

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

echo "=== Template Carousel Unit Test ===\n\n";

$controller = new \App\Controllers\Templates();
$ref = new ReflectionClass($controller);
$method = $ref->getMethod('buildSubmitComponents');
$method->setAccessible(true);

$components = $method->invoke($controller, [
    'template_type' => 'carousel',
    'body' => 'Browse our picks {{1}}',
    'body_examples' => 'today',
    'carousel_cards' => [
        [
            'media_type' => 'image',
            'media_source' => 'https://cdn.example.com/a.jpg',
            'media_preview_url' => 'https://cdn.example.com/a.jpg',
            'body' => 'Item A',
            'cta_type' => 'url',
            'cta_button_text' => 'Buy',
            'cta_url' => 'https://shop.example.com/a',
            'cta_url_example' => '',
            'cta_phone_number' => '',
        ],
        [
            'media_type' => 'image',
            'media_source' => 'https://cdn.example.com/b.jpg',
            'media_preview_url' => 'https://cdn.example.com/b.jpg',
            'body' => 'Item B',
            'cta_type' => 'url',
            'cta_button_text' => 'Buy',
            'cta_url' => 'https://shop.example.com/b',
            'cta_url_example' => '',
            'cta_phone_number' => '',
        ],
    ],
]);

check('carousel returns 2 top-level components', count($components) === 2);
check('first component is BODY', strtoupper((string) ($components[0]['type'] ?? '')) === 'BODY');
check('second component is CAROUSEL', strtoupper((string) ($components[1]['type'] ?? '')) === 'CAROUSEL');
check('carousel has 2 cards', count($components[1]['cards'] ?? []) === 2);

$card0 = $components[1]['cards'][0]['components'] ?? [];
$types = array_map(static fn ($c) => strtoupper((string) ($c['type'] ?? '')), $card0);
check('card has HEADER', in_array('HEADER', $types, true));
check('card has BODY', in_array('BODY', $types, true));
check('card has BUTTONS', in_array('BUTTONS', $types, true));

$header = null;
foreach ($card0 as $component) {
    if (strtoupper((string) ($component['type'] ?? '')) === 'HEADER') {
        $header = $component;
        break;
    }
}
check('card header format IMAGE', strtoupper((string) ($header['format'] ?? '')) === 'IMAGE');
check('card header includes handle', ! empty($header['example']['header_handle'][0]));

$validate = $ref->getMethod('validateCarouselCards');
$validate->setAccessible(true);
$tooFew = $validate->invoke($controller, [[
    'media_type' => 'image',
    'media_source' => 'https://cdn.example.com/a.jpg',
]]);
check('rejects fewer than 2 cards', is_string($tooFew));

$mixed = $validate->invoke($controller, [
    [
        'media_type' => 'image',
        'media_source' => 'https://cdn.example.com/a.jpg',
        'cta_type' => 'url',
        'cta_button_text' => 'Buy',
        'cta_url' => 'https://shop.example.com/a',
    ],
    [
        'media_type' => 'video',
        'media_source' => 'https://cdn.example.com/b.mp4',
        'cta_type' => 'url',
        'cta_button_text' => 'Buy',
        'cta_url' => 'https://shop.example.com/b',
    ],
]);
check('rejects mixed media types', is_string($mixed) && str_contains($mixed, 'same media type'));

$ok = $validate->invoke($controller, [
    [
        'media_type' => 'image',
        'media_source' => 'https://cdn.example.com/a.jpg',
        'body' => 'A',
        'cta_type' => 'url',
        'cta_button_text' => 'Buy',
        'cta_url' => 'https://shop.example.com/a',
    ],
    [
        'media_type' => 'image',
        'media_source' => 'https://cdn.example.com/b.jpg',
        'body' => 'B',
        'cta_type' => 'url',
        'cta_button_text' => 'Buy',
        'cta_url' => 'https://shop.example.com/b',
    ],
]);
check('accepts valid carousel cards', $ok === null);

echo "\n=== Result: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
