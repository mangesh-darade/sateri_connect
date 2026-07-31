<?php

declare(strict_types=1);

/**
 * Unit checks for body variable example validation + preview mapping.
 *
 * Run: php tests/TemplatesVariableExamplesUnitTest.php
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

echo "=== Template Variable Examples Unit Test ===\n\n";

$controller = new \App\Controllers\Templates();
$ref = new ReflectionClass($controller);

$extract = $ref->getMethod('extractPlaceholders');
$extract->setAccessible(true);
$placeholders = $extract->invoke($controller, 'satericonnect{{1}}{{2}}{{3}}');
check('extracts three placeholders', $placeholders === ['1', '2', '3']);

$build = $ref->getMethod('buildSubmitComponents');
$build->setAccessible(true);
$components = $build->invoke($controller, [
    'template_type' => 'default',
    'header_type' => 'none',
    'header' => '',
    'header_media_source' => '',
    'header_media_preview_url' => '',
    'body' => 'Hello {{1}}, code {{2}}, id {{3}}',
    'footer' => 'Thanks',
    'body_examples' => 'Vipin, xzcsdff, ORD-9',
    'cta_type' => '',
    'cta_button_text' => '',
    'cta_url' => '',
    'cta_url_example' => '',
    'cta_phone_number' => '',
    'carousel_cards' => [],
]);

$bodyComponent = null;
foreach ($components as $component) {
    if (strtoupper((string) ($component['type'] ?? '')) === 'BODY') {
        $bodyComponent = $component;
        break;
    }
}
check('body component built', is_array($bodyComponent));
$examples = $bodyComponent['example']['body_text'][0] ?? null;
check('body examples are array of 3', is_array($examples) && count($examples) === 3);
check('maps second example correctly', is_array($examples) && ($examples[1] ?? null) === 'xzcsdff');

$view = file_get_contents(dirname(FCPATH) . '/app/Views/templates/create.php');
check('UI has Add variable', str_contains($view, 'Add variable'));
check('UI has per-variable example inputs', str_contains($view, 'js-var-example'));
check('UI has variable ratio warning', str_contains($view, 'templateVarRatioWarning'));
check('UI keeps empty placeholders in preview', str_contains($view, "return replacement !== '' ? replacement : ('{{' + key + '}}')"));

$ratio = $ref->getMethod('validateBodyVariableRatio');
$ratio->setAccessible(true);
$shortBody = 'Hello {{1}}, your order {{2}} for {{3}} is confirmed. Delivery on {{4}}. Tracking ID: {{5}}.';
$longBody = 'Hello {{1}}, thank you for shopping with us. Your order {{2}} for item {{3}} is confirmed and currently being prepared by our team. Expected delivery date is {{4}}. You can track your shipment anytime using tracking ID {{5}}.';
check('rejects dense 5-var short body', is_string($ratio->invoke($controller, $shortBody)));
check('accepts longer 5-var body', $ratio->invoke($controller, $longBody) === null);

// Meta rule: words + variables >= (3 x variables) + 1, counting whitespace tokens.
check('rejects production body that Meta refused', is_string($ratio->invoke($controller, 'mangesh_test2{{1}}  mangesh_test2 done')));
check('glued variable does not count as an extra word', is_string($ratio->invoke($controller, 'order{{1}} shipped today')));
check('accepts exactly (3n)+1 words for one variable', $ratio->invoke($controller, 'Hi {{1}} your order shipped') === null);
check('rejects one word below the limit', is_string($ratio->invoke($controller, 'Hi {{1}} shipped')));
check('accepts exactly (3n)+1 words for two variables', $ratio->invoke($controller, 'Hi {{1}} your order {{2}} has shipped today already') === null);
check('no variables means no ratio limit', $ratio->invoke($controller, 'Thanks') === null);
check('ratio message states the required word count', str_contains(
    (string) $ratio->invoke($controller, 'mangesh_test2{{1}}  mangesh_test2 done'),
    'at least 4 words for 1 variable'
));

$placement = $ref->getMethod('validateBodyVariablePlacement');
$placement->setAccessible(true);
check('rejects body starting with a variable', is_string($placement->invoke($controller, '{{1}} your order is ready now')));
check('rejects body ending with a variable', is_string($placement->invoke($controller, 'Your order is ready {{1}}')));
check('accepts variable in the middle', $placement->invoke($controller, 'Hi {{1}} your order shipped') === null);
check('placement ignores empty body', $placement->invoke($controller, '   ') === null);

check('UI mirrors the (3n)+1 rule', str_contains($view, '(placeholders.length * 3) + 1'));
check('UI blocks leading/trailing variables', str_contains($view, 'getBodyVariablePlacementError'));
check('UI has message preview phone frame', str_contains($view, 'template-phone-frame'));
check('UI has format toolbar', str_contains($view, 'js-body-format'));

$controllerSrc = file_get_contents(dirname(FCPATH) . '/app/Controllers/Templates.php');
check('backend requires sample text per variable', str_contains($controllerSrc, 'Please enter sample text for variable {{'));

echo "\n=== Result: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
