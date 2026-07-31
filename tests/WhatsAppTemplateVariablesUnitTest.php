<?php

define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Libraries\WhatsAppTemplateVariables;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok): void
{
    global $pass, $fail;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
}

echo "=== WhatsApp Template Variables Unit Test ===\n\n";

$components = [[
    'type' => 'BODY',
    'text' => 'Hi {{1}}, try {{2}} at {{3}}',
    'example' => [
        'body_text' => [[
            'Name',
            'Shampoo',
            'amazon.in/StacPro-Coasters-Glasses-Protector',
        ]],
    ],
]];

$definitions = WhatsAppTemplateVariables::definitionsFromComponents($components);
check('uses placeholder identities, not samples', array_column($definitions, 'key') === ['1', '2', '3']);
check('keeps Name as first example', ($definitions[0]['example'] ?? '') === 'Name');
check('smart maps Name example to contact name', ($definitions[0]['suggested_source'] ?? '') === 'name');
check('maps product sample to custom', ($definitions[1]['suggested_source'] ?? '') === 'custom');
check('maps URL sample to custom', ($definitions[2]['suggested_source'] ?? '') === 'custom');
$defaults = WhatsAppTemplateVariables::applyMappingDefaults([], $definitions);
check('backend auto-maps Name to contact name', ($defaults['1'] ?? '') === 'name');
check('backend uses product example as explicit custom value', ($defaults['2'] ?? '') === 'Shampoo');
check('backend uses URL example as explicit custom value', ($defaults['3'] ?? '') === 'amazon.in/StacPro-Coasters-Glasses-Protector');

$legacy = ['Name', 'Shampoo', 'amazon.in/StacPro-Coasters-Glasses-Protector'];
$repaired = WhatsAppTemplateVariables::definitionsForTemplate($legacy, 'Hi {{1}}, try {{2}} at {{3}}');
check('repairs legacy sample list to positional keys', array_column($repaired, 'key') === ['1', '2', '3']);
check('preserves legacy samples as hints', array_column($repaired, 'example') === $legacy);

$named = WhatsAppTemplateVariables::definitionsFromComponents([[
    'type' => 'BODY',
    'text' => 'Hi {{customer_name}}, email {{email_address}}',
    'example' => [
        'body_text_named_params' => [
            ['param_name' => 'customer_name', 'example' => 'Mangesh'],
            ['param_name' => 'email_address', 'example' => 'm@example.com'],
        ],
    ],
]]);
check('supports named variable identities', array_column($named, 'key') === ['customer_name', 'email_address']);
check('smart maps named customer_name', ($named[0]['suggested_source'] ?? '') === 'name');
check('smart maps named email_address', ($named[1]['suggested_source'] ?? '') === 'email');

$campaignJs = file_get_contents(dirname(__DIR__) . '/public/assets/js/campaigns.js');
check('wizard no longer defaults all variables to name', ! str_contains($campaignJs, "state.variables[key] || 'name'"));
check('wizard displays examples separately', str_contains($campaignJs, 'Example: '));
check('wizard resets mapping when template changes', str_contains($campaignJs, 'state.variables = {};'));

$templatesSource = file_get_contents(dirname(__DIR__) . '/app/Controllers/Templates.php');
$syncSource = file_get_contents(dirname(__DIR__) . '/app/Commands/SyncTemplates.php');
$apiSource = file_get_contents(dirname(__DIR__) . '/app/Controllers/Api/Templates.php');
check('web sync stores identities', str_contains($templatesSource, 'identitiesFromComponents'));
check('CLI sync stores identities', str_contains($syncSource, 'identitiesFromComponents'));
check('API sync stores identities', str_contains($apiSource, 'identitiesFromComponents'));

$serviceSource = file_get_contents(dirname(__DIR__) . '/app/Libraries/CampaignService.php');
check('send supports named parameter_name', str_contains($serviceSource, "\$parameter['parameter_name']"));

echo "\n=== Result: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
