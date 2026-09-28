<?php

/**
 * Campaign template BODY parameters must match the approved template exactly,
 * otherwise Meta rejects the send with (#132000) parameter count mismatch.
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

echo "=== Campaign Template Parameters Unit Test ===\n\n";

$service = (new ReflectionClass(\App\Libraries\CampaignService::class))->newInstanceWithoutConstructor();
$build = new ReflectionMethod($service, 'buildTemplateComponents');

$contact = ['id' => 1, 'name' => 'Ada', 'mobile' => '919999999999', 'email' => 'ada@example.com'];

$positional = [
    'body'        => 'hey {{1}} this is {{2}} software for customer.',
    'variables'   => json_encode(['1', '2']),
    'raw_payload' => null,
];

function throwsMissing(callable $fn): bool
{
    try {
        $fn();
    } catch (\RuntimeException $e) {
        return str_contains($e->getMessage(), 'Missing value for template variable');
    }

    return false;
}

// Policy: an unmapped variable must never go out as "-" or example text — the recipient is skipped instead.
check('unmapped variable blocks the send (no "-" placeholder)', throwsMissing(fn () => $build->invoke($service, ['1' => 'name'], $contact, null, $positional)));

$components = $build->invoke($service, ['1' => 'name', '2' => 'Acme'], $contact, null, $positional);
check('mapped template sends every placeholder', count($components[0]['parameters']) === 2);
check('mapped variable resolves from contact', $components[0]['parameters'][0]['text'] === 'Ada');
check('positional template omits parameter_name', ! isset($components[0]['parameters'][0]['parameter_name']));

$noName = $build->invoke($service, ['1' => 'name', '2' => 'Acme'], ['id' => 2, 'name' => '', 'mobile' => '919999999998'], null, $positional);
check('missing contact name uses neutral "Customer"', $noName[0]['parameters'][0]['text'] === 'Customer');

// Regression: campaign saved more variables than the template has.
$extra = $build->invoke($service, ['1' => 'name', '2' => 'Acme', '3' => 'stale'], $contact, null, $positional);
check('extra saved variables are dropped', count($extra[0]['parameters']) === 2);
check('second parameter keeps its custom value', $extra[0]['parameters'][1]['text'] === 'Acme');

// Empty map on a template that needs parameters.
check('empty map on a parameterised template blocks the send', throwsMissing(fn () => $build->invoke($service, [], $contact, null, $positional)));

// Template without placeholders must not send a body component.
$noVars = $build->invoke($service, ['1' => 'name'], $contact, null, [
    'body'      => 'Thanks for shopping with us.',
    'variables' => null,
]);
check('template without placeholders sends no body params', $noVars === []);

// Named parameters.
$named = [
    'body'        => 'Hi {{customer_name}}, your order {{order_id}} shipped.',
    'variables'   => json_encode(['customer_name', 'order_id']),
    'raw_payload' => null,
];
$namedOut = $build->invoke($service, ['order_id' => 'A-1001'], $contact, null, $named);
check('named template sends both parameters', count($namedOut[0]['parameters']) === 2);
check('named template sets parameter_name', ($namedOut[0]['parameters'][0]['parameter_name'] ?? '') === 'customer_name');
check('named variable auto-maps to contact name', $namedOut[0]['parameters'][0]['text'] === 'Ada');
check('named mapped value is used', $namedOut[0]['parameters'][1]['text'] === 'A-1001');

// Legacy campaign that stored a named template map positionally.
$legacy = $build->invoke($service, ['1' => 'name', '2' => 'A-1001'], $contact, null, $named);
check('positional map still fills named template', count($legacy[0]['parameters']) === 2);
check('positional map keeps named order', $legacy[0]['parameters'][1]['text'] === 'A-1001');

// Approval examples (e.g. "Mangesh") must never be sent to a real customer.
$withExample = [
    'body'        => 'hey {{1}} this is {{2}} software for customer.',
    'variables'   => json_encode(['1', '2']),
    'raw_payload' => json_encode([
        'components' => [[
            'type'    => 'BODY',
            'text'    => 'hey {{1}} this is {{2}} software for customer.',
            'example' => ['body_text' => [['Mangesh', 'ElintOm']]],
        ]],
    ]),
];
check('approval example text is not used as a fallback', throwsMissing(fn () => $build->invoke($service, [], $contact, null, $withExample)));

// Existing Meta components are passed through untouched.
$existing = [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'preset']]]];
check('prebuilt components pass through', $build->invoke($service, [], $contact, $existing, $positional) === $existing);

// Regression: media templates store a HEADER-only component in the wizard payload.
$headerOnly = [[
    'type'       => 'header',
    'parameters' => [['type' => 'image', 'image' => ['id' => '123']]],
]];
$withHeader = $build->invoke($service, ['1' => 'name', '2' => 'Acme'], $contact, $headerOnly, $positional);
check('header-only payload keeps its header', ($withHeader[0]['type'] ?? '') === 'header');
check('header-only payload still gets a body component', ($withHeader[1]['type'] ?? '') === 'body');
check('header-only payload sends every body parameter', count($withHeader[1]['parameters']) === 2);

$headerNoVars = $build->invoke($service, [], $contact, $headerOnly, [
    'body'      => 'Your order is on the way.',
    'variables' => null,
]);
check('header-only payload adds no body when template has none', $headerNoVars === $headerOnly);

// No template row available — fall back to the saved map.
$fallback = $build->invoke($service, ['1' => 'name', '2' => 'Acme'], $contact, null, null);
check('without template row the saved map is used', count($fallback[0]['parameters']) === 2);
check('fallback resolves contact field', $fallback[0]['parameters'][0]['text'] === 'Ada');

$src = file_get_contents(dirname(__DIR__) . '/app/Libraries/CampaignService.php');
check('bulk dispatch passes the template row', str_contains($src, 'is_array($templateRow) ? $templateRow : null'));
check('parameters are derived from template definitions', str_contains($src, 'parametersFromDefinitions'));

echo "\n=== Result: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
