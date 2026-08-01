<?php

declare(strict_types=1);

/**
 * Live-data check: every template/campaign in this database must produce a BODY
 * parameter count that matches its approved template, or Meta rejects the send
 * with (#132000).
 *
 * Read-only. Run: php tests/CampaignParamsLiveFunctionalTest.php
 */

define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);
chdir(FCPATH);

require FCPATH . '../app/Config/Paths.php';
$paths = new \Config\Paths();
require $paths->systemDirectory . '/Boot.php';
\CodeIgniter\Boot::bootSpark($paths);

use App\Libraries\CampaignService;
use App\Libraries\WhatsAppTemplateVariables;

$pass   = 0;
$fail   = 0;
$errors = [];

function check(string $label, bool $condition, string $detail = ''): void
{
    global $pass, $fail, $errors;

    if ($condition) {
        $pass++;
        echo "[PASS] {$label}\n";

        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? " — {$detail}" : '');
    echo "[FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

$service = (new ReflectionClass(CampaignService::class))->newInstanceWithoutConstructor();
$build   = new ReflectionMethod($service, 'buildTemplateComponents');

$contact = [
    'id'     => 999999,
    'name'   => 'Live Check',
    'mobile' => '919999999999',
    'email'  => 'live@example.com',
];

$db = db_connect();

echo "=== 1) TEMPLATES IN THIS DATABASE ===\n";

$templates = $db->table('templates')->get()->getResultArray();
echo 'templates found: ' . count($templates) . "\n";

$withVars = 0;
foreach ($templates as $template) {
    $body = (string) ($template['body'] ?? '');
    $keys = WhatsAppTemplateVariables::identitiesFromBody($body);
    if ($keys === []) {
        continue;
    }
    $withVars++;

    $name = (string) ($template['name'] ?? ('#' . $template['id']));

    // Worst case: the campaign saved nothing at all.
    $components = $build->invoke($service, [], $contact, null, $template);
    $body1      = null;
    foreach ($components as $component) {
        if (strtolower((string) ($component['type'] ?? '')) === 'body') {
            $body1 = $component;
        }
    }
    $count = $body1 !== null ? count($body1['parameters']) : 0;
    check(
        "{$name}: empty map still sends " . count($keys) . ' param(s)',
        $count === count($keys),
        "expected " . count($keys) . ", got {$count}"
    );

    // Worst case: a stale map with the wrong keys and too many entries.
    $stale      = ['1' => 'name', '2' => 'x', '3' => 'y', '4' => 'z', 'ghost' => 'q'];
    $components = $build->invoke($service, $stale, $contact, null, $template);
    $body2      = null;
    foreach ($components as $component) {
        if (strtolower((string) ($component['type'] ?? '')) === 'body') {
            $body2 = $component;
        }
    }
    $count2 = $body2 !== null ? count($body2['parameters']) : 0;
    check(
        "{$name}: stale map is trimmed to " . count($keys) . ' param(s)',
        $count2 === count($keys),
        "expected " . count($keys) . ", got {$count2}"
    );

    // Named templates must carry parameter_name; positional must not.
    $named = array_filter($keys, static fn (string $k): bool => ! ctype_digit($k));
    if ($body2 !== null) {
        $hasNames = false;
        foreach ($body2['parameters'] as $parameter) {
            if (isset($parameter['parameter_name'])) {
                $hasNames = true;
            }
        }
        check(
            "{$name}: parameter_name usage matches placeholder style",
            $hasNames === ($named !== []),
            $named !== [] ? 'named template must send parameter_name' : 'positional template must omit parameter_name'
        );
    }

    // No empty text values — Meta rejects blank parameters too.
    if ($body2 !== null) {
        $blank = false;
        foreach ($body2['parameters'] as $parameter) {
            if (trim((string) ($parameter['text'] ?? '')) === '') {
                $blank = true;
            }
        }
        check("{$name}: no blank parameter text", ! $blank);
    }
}

if ($withVars === 0) {
    echo "(no templates with body variables in this database)\n";
}

echo "\n=== 2) MEDIA-HEADER TEMPLATE + WIZARD HEADER PAYLOAD ===\n";

$mediaTemplate = null;
foreach ($templates as $template) {
    $headerType = strtolower(trim((string) ($template['header_type'] ?? '')));
    if (
        in_array($headerType, ['image', 'video', 'document'], true)
        && WhatsAppTemplateVariables::identitiesFromBody((string) ($template['body'] ?? '')) !== []
    ) {
        $mediaTemplate = $template;
        break;
    }
}

if ($mediaTemplate === null) {
    echo "(no media-header template with body variables; using a synthetic one)\n";
    $mediaTemplate = [
        'name'        => 'synthetic_media_template',
        'header_type' => 'image',
        'body'        => 'hey {{1}} this is {{2}} software for customer.',
        'variables'   => json_encode(['1', '2']),
        'raw_payload' => null,
    ];
}

$keys       = WhatsAppTemplateVariables::identitiesFromBody((string) $mediaTemplate['body']);
$headerOnly = [[
    'type'       => 'header',
    'parameters' => [['type' => strtolower((string) $mediaTemplate['header_type']), strtolower((string) $mediaTemplate['header_type']) => ['id' => '1234567890']]],
]];

$components = $build->invoke($service, ['1' => 'name'], $contact, $headerOnly, $mediaTemplate);
$types      = array_map(static fn (array $c): string => strtolower((string) $c['type']), $components);
check('media template keeps HEADER component', in_array('header', $types, true));
check('media template also sends BODY component', in_array('body', $types, true));

$bodyComponent = null;
foreach ($components as $component) {
    if (strtolower((string) $component['type']) === 'body') {
        $bodyComponent = $component;
    }
}
check(
    'media template body param count matches template',
    $bodyComponent !== null && count($bodyComponent['parameters']) === count($keys),
    'expected ' . count($keys) . ', got ' . ($bodyComponent !== null ? count($bodyComponent['parameters']) : 0)
);

echo "\n=== 3) EXISTING CAMPAIGNS IN THIS DATABASE ===\n";

$campaigns = $db->table('campaigns')
    ->where('template_id IS NOT NULL')
    ->orderBy('id', 'DESC')
    ->limit(25)
    ->get()
    ->getResultArray();

echo 'campaigns with a template: ' . count($campaigns) . "\n";

$decode = new ReflectionMethod($service, 'decodeVariables');
$repaired = 0;

foreach ($campaigns as $campaign) {
    $template = $db->table('templates')->where('id', (int) $campaign['template_id'])->get()->getRowArray();
    if ($template === null) {
        continue;
    }

    $keys   = WhatsAppTemplateVariables::identitiesFromBody((string) ($template['body'] ?? ''));
    $map    = $decode->invoke($service, $campaign['variables'] ?? null);
    $label  = 'campaign #' . $campaign['id'] . ' (' . ($template['name'] ?? '?') . ')';

    $components = $build->invoke($service, $map, $contact, null, $template);
    $bodyComponent = null;
    foreach ($components as $component) {
        if (strtolower((string) ($component['type'] ?? '')) === 'body') {
            $bodyComponent = $component;
        }
    }
    $sent = $bodyComponent !== null ? count($bodyComponent['parameters']) : 0;

    check("{$label}: sends " . count($keys) . ' param(s)', $sent === count($keys), "expected " . count($keys) . ", got {$sent}");

    if (count($map) !== count($keys) && $sent === count($keys)) {
        $repaired++;
        echo "       ↳ repaired: saved map had " . count($map) . " entr(ies) for " . count($keys) . " placeholder(s)\n";
    }
}

echo "\n=== RESULT: {$pass} passed, {$fail} failed";
if ($repaired > 0) {
    echo " ({$repaired} existing campaign(s) would have failed before this fix)";
}
echo " ===\n";

if ($errors !== []) {
    echo "\nFAILURES:\n";
    foreach ($errors as $error) {
        echo " - {$error}\n";
    }
}

exit($fail > 0 ? 1 : 0);
