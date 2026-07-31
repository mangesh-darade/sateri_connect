<?php

declare(strict_types=1);

/**
 * Deep functional test for template multi-button create (Quick Reply / URL / Phone).
 *
 * Run: php tests/TemplatesMultiButtonDeepTest.php
 */

define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);
chdir(FCPATH);

require FCPATH . '../app/Config/Paths.php';
$paths = new Config\Paths();
require $paths->systemDirectory . '/Boot.php';
CodeIgniter\Boot::bootSpark($paths);

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

echo "=== Templates Multi-Button Deep Test ===\n\n";

$controller = new App\Controllers\Templates();

$build = new ReflectionMethod(App\Controllers\Templates::class, 'buildButtonsFromInput');
$build->setAccessible(true);
$validate = new ReflectionMethod(App\Controllers\Templates::class, 'validateTemplateButtons');
$validate->setAccessible(true);
$parse = new ReflectionMethod(App\Controllers\Templates::class, 'parseTemplateButtonsInput');
$parse->setAccessible(true);
$submit = new ReflectionMethod(App\Controllers\Templates::class, 'buildSubmitComponents');
$submit->setAccessible(true);

echo "-- buildButtonsFromInput --\n";
$built = $build->invoke($controller, [
    ['type' => 'quick_reply', 'text' => 'Yes'],
    ['type' => 'quick_reply', 'text' => 'No'],
    [
        'type'        => 'url',
        'text'        => 'Track',
        'url'         => 'https://example.com/orders/{{1}}',
        'url_example' => 'https://example.com/orders/ORD-1',
    ],
    [
        'type'         => 'phone_number',
        'text'         => 'Call us',
        'phone_number' => '+919876543210',
    ],
]);

check('builds 4 buttons', count($built) === 4);
check('quick reply payload', ($built[0]['type'] ?? '') === 'QUICK_REPLY' && ($built[0]['text'] ?? '') === 'Yes');
check('second quick reply payload', ($built[1]['type'] ?? '') === 'QUICK_REPLY' && ($built[1]['text'] ?? '') === 'No');
check('url payload + example', ($built[2]['type'] ?? '') === 'URL'
    && ($built[2]['url'] ?? '') === 'https://example.com/orders/{{1}}'
    && (($built[2]['example'][0] ?? '') === 'https://example.com/orders/ORD-1'));
check('phone payload', ($built[3]['type'] ?? '') === 'PHONE_NUMBER'
    && ($built[3]['phone_number'] ?? '') === '+919876543210');
check('skips empty text buttons', count($build->invoke($controller, [
    ['type' => 'quick_reply', 'text' => ''],
    ['type' => 'url', 'text' => 'Go', 'url' => 'https://example.com'],
])) === 1);

echo "\n-- validateTemplateButtons --\n";
check('valid mix passes', $validate->invoke($controller, [
    ['type' => 'quick_reply', 'text' => 'Yes'],
    ['type' => 'url', 'text' => 'Site', 'url' => 'https://example.com', 'url_example' => ''],
    ['type' => 'phone_number', 'text' => 'Call', 'phone_number' => '+919876543210'],
]) === null);

check('rejects invalid type', is_string($validate->invoke($controller, [
    ['type' => 'otp', 'text' => 'Bad'],
])));
check('rejects missing quick reply text', is_string($validate->invoke($controller, [
    ['type' => 'quick_reply', 'text' => ''],
])));
check('rejects invalid url', is_string($validate->invoke($controller, [
    ['type' => 'url', 'text' => 'Go', 'url' => 'not-a-url', 'url_example' => ''],
])));
check('requires url example for placeholder', is_string($validate->invoke($controller, [
    ['type' => 'url', 'text' => 'Go', 'url' => 'https://example.com/{{1}}', 'url_example' => ''],
])));
check('rejects 3 website buttons', is_string($validate->invoke($controller, [
    ['type' => 'url', 'text' => 'A', 'url' => 'https://a.com', 'url_example' => ''],
    ['type' => 'url', 'text' => 'B', 'url' => 'https://b.com', 'url_example' => ''],
    ['type' => 'url', 'text' => 'C', 'url' => 'https://c.com', 'url_example' => ''],
])));
check('rejects 2 phone buttons', is_string($validate->invoke($controller, [
    ['type' => 'phone_number', 'text' => 'A', 'phone_number' => '+911111111111'],
    ['type' => 'phone_number', 'text' => 'B', 'phone_number' => '+922222222222'],
])));
check('rejects more than 10 buttons', is_string($validate->invoke($controller, array_fill(0, 11, [
    'type' => 'quick_reply',
    'text' => 'Ok',
]))));
check('allows 10 quick replies', $validate->invoke($controller, array_map(
    static fn (int $i): array => ['type' => 'quick_reply', 'text' => 'Btn' . $i],
    range(1, 10)
)) === null);

echo "\n-- parseTemplateButtonsInput --\n";
$parsed = $parse->invoke($controller, json_encode([
    ['type' => 'Quick_Reply', 'text' => '  Hello  ', 'url' => '', 'phone_number' => ''],
    ['type' => 'URL', 'text' => 'Shop', 'url' => ' https://shop.example/ ', 'url_example' => ''],
]));
check('parses JSON string', count($parsed) === 2);
check('normalizes type + trims text', ($parsed[0]['type'] ?? '') === 'quick_reply' && ($parsed[0]['text'] ?? '') === 'Hello');
check('trims url', ($parsed[1]['url'] ?? '') === 'https://shop.example/');
check('empty payload -> []', $parse->invoke($controller, '') === []);
check('invalid JSON -> []', $parse->invoke($controller, '{bad') === []);

echo "\n-- buildSubmitComponents BUTTONS --\n";
$components = $submit->invoke($controller, [
    'name' => 'test_multi_btn',
    'language' => 'en_US',
    'category' => 'UTILITY',
    'template_type' => 'default',
    'header_type' => 'none',
    'header' => '',
    'header_media_source' => '',
    'header_media_preview_url' => '',
    'body' => 'Hello {{1}}',
    'footer' => 'Thanks',
    'body_examples' => 'Ravi',
    'template_buttons' => [
        ['type' => 'quick_reply', 'text' => 'Yes'],
        ['type' => 'url', 'text' => 'Open', 'url' => 'https://example.com', 'url_example' => ''],
    ],
    'carousel_cards' => [],
]);

$buttonComponent = null;
foreach ($components as $component) {
    if (strtoupper((string) ($component['type'] ?? '')) === 'BUTTONS') {
        $buttonComponent = $component;
        break;
    }
}
check('submit includes BUTTONS component', is_array($buttonComponent));
check('submit BUTTONS has quick_reply + url', is_array($buttonComponent)
    && count($buttonComponent['buttons'] ?? []) === 2
    && ($buttonComponent['buttons'][0]['type'] ?? '') === 'QUICK_REPLY'
    && ($buttonComponent['buttons'][1]['type'] ?? '') === 'URL');

$noButtons = $submit->invoke($controller, [
    'name' => 'test_no_btn',
    'language' => 'en_US',
    'category' => 'UTILITY',
    'template_type' => 'default',
    'header_type' => 'none',
    'header' => '',
    'header_media_source' => '',
    'header_media_preview_url' => '',
    'body' => 'Hello',
    'footer' => '',
    'body_examples' => '',
    'template_buttons' => [],
    'carousel_cards' => [],
]);
$hasButtons = false;
foreach ($noButtons as $component) {
    if (strtoupper((string) ($component['type'] ?? '')) === 'BUTTONS') {
        $hasButtons = true;
    }
}
check('no BUTTONS component when empty', ! $hasButtons);

echo "\n-- Meta Graph create payload normalization --\n";
$meta = new App\Libraries\MetaCloudAPI();
$normalize = new ReflectionMethod(App\Libraries\MetaCloudAPI::class, 'normalizeCreateComponents');
$normalize->setAccessible(true);

$normalized = $normalize->invoke($meta, [
    [
        'type'    => 'HEADER',
        'format'  => 'IMAGE',
        'example' => [
            'header_handle' => ['https://cdn.example.com/logo.png'],
            'header_url'    => 'https://cdn.example.com/logo.png',
            'link'          => 'https://cdn.example.com/logo.png',
        ],
    ],
    ['type' => 'BODY', 'text' => 'mangesh {{1}} done. this', 'example' => ['body_text' => [['Ravi']]]],
    [
        'type'    => 'BUTTONS',
        'buttons' => [
            ['type' => 'QUICK_REPLY', 'text' => 'Yes'],
            ['type' => 'URL', 'text' => 'Track', 'url' => 'https://ex.com/{{1}}', 'example' => ['https://ex.com/1']],
        ],
    ],
    [
        'type'  => 'CAROUSEL',
        'cards' => [
            ['components' => [[
                'type'    => 'HEADER',
                'format'  => 'IMAGE',
                'example' => [
                    'header_handle' => ['https://cdn.example.com/c1.png'],
                    'link'          => 'https://cdn.example.com/c1.png',
                ],
            ]]],
        ],
    ],
]);

check('drops Cheerio-only link key from header example', ! array_key_exists('link', $normalized[0]['example']));
check('drops Cheerio-only header_url key', ! array_key_exists('header_url', $normalized[0]['example']));
check('keeps header_handle for Meta', ($normalized[0]['example']['header_handle'][0] ?? '') === 'https://cdn.example.com/logo.png');
check('keeps body_text example', ($normalized[1]['example']['body_text'][0][0] ?? '') === 'Ravi');
check('keeps URL button example list', ($normalized[2]['buttons'][1]['example'][0] ?? '') === 'https://ex.com/1');
check('normalizes carousel card headers', ! array_key_exists('link', $normalized[3]['cards'][0]['components'][0]['example'] ?? ['link' => 1]));

$textHeader = $normalize->invoke($meta, [[
    'type'    => 'HEADER',
    'format'  => 'TEXT',
    'text'    => 'Hi {{1}}',
    'example' => ['header_text' => ['Ravi'], 'link' => 'https://x.com'],
]]);
check('keeps header_text example', ($textHeader[0]['example']['header_text'][0] ?? '') === 'Ravi'
    && ! array_key_exists('link', $textHeader[0]['example']));

$onlyBadKeys = $normalize->invoke($meta, [[
    'type'    => 'HEADER',
    'format'  => 'IMAGE',
    'example' => ['link' => 'https://x.com'],
]]);
check('removes example entirely when no Meta keys remain', ! array_key_exists('example', $onlyBadKeys[0]));

echo "\n-- view / controller wiring --\n";
$view = file_get_contents(dirname(FCPATH) . '/app/Views/templates/create.php') ?: '';
$ctrl = file_get_contents(dirname(FCPATH) . '/app/Controllers/Templates.php') ?: '';
check('view renders type-specific fields only', str_contains($view, 'Only the fields the selected type actually needs'));
check('view has Quick Reply helper copy', str_contains($view, 'Quick Reply needs button text only'));
check('view posts template_buttons JSON', str_contains($view, 'name="template_buttons"') && str_contains($view, 'syncTemplateButtonsInput'));
check('controller accepts template_buttons post', str_contains($ctrl, "getPost('template_buttons')"));
check('controller keeps legacy cta_* fallback', str_contains($ctrl, "getPost('cta_type')") && str_contains($ctrl, 'Backward compatibility'));

$cheerio = file_get_contents(dirname(FCPATH) . '/app/Libraries/CheerioDirectAPI.php') ?: '';
check('Cheerio createTemplate untouched by Meta normalization', ! str_contains($cheerio, 'normalizeCreateComponents'));

echo "\nPassed: {$pass}  Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
