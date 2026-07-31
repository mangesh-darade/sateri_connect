<?php

declare(strict_types=1);

/**
 * Smoke test for WhatsApp template create flow.
 *
 * Run: php tests/TemplatesCreateFeatureTest.php
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

echo "=== Template Create Flow Smoke Test ===\n\n";

$root = dirname(FCPATH);

$files = [
    'app/Controllers/Templates.php',
    'app/Views/templates/create.php',
    'app/Models/TemplateModel.php',
    'app/Commands/EnsureWhatsAppSchema.php',
    'app/Database/Migrations/2026-07-28-154200_AddTemplateTypeToTemplates.php',
];

foreach ($files as $rel) {
    check("exists {$rel}", is_file($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel)));
}

$routes = file_get_contents($root . '/app/Config/Routes.php');
check('route GET templates/create', str_contains($routes, "get('templates/create', 'Templates::create')"));
check('route POST templates', str_contains($routes, "post('templates', 'Templates::store'"));
check('route POST templates/header-media', str_contains($routes, "post('templates/header-media', 'Templates::uploadHeaderMedia'"));

$controller = file_get_contents($root . '/app/Controllers/Templates.php');
check('Templates has create()', str_contains($controller, 'function create('));
check('Templates has store()', str_contains($controller, 'function store('));
check('Templates has uploadHeaderMedia()', str_contains($controller, 'function uploadHeaderMedia('));
check('Templates validates template_type', str_contains($controller, "in_array(\$templateType, ['default', 'carousel'], true)"));
check('Templates validates header type', str_contains($controller, "in_array(\$headerType, ['none', 'text', 'image', 'video', 'document'], true)"));
check('Templates validates button types', str_contains($controller, "in_array(\$type, ['quick_reply', 'url', 'phone_number'], true)"));
check('Templates supports Quick Reply buttons', str_contains($controller, "'type' => 'QUICK_REPLY'"));
check('Templates builds buttons from multi-button input', str_contains($controller, 'function buildButtonsFromInput('));
check('Templates validates max 10 buttons', str_contains($controller, 'You can add at most 10 buttons.'));
check('Templates supports AJAX success response', str_contains($controller, "if (\$this->request->isAJAX())"));
check('Templates inserts template_type', str_contains($controller, "'template_type'  => \$input['template_type']"));
check('Templates builds buttons component', str_contains($controller, "'type'    => 'BUTTONS'"));
check('Templates stores media header example', str_contains($controller, "'header_handle' => [\$handle]") || str_contains($controller, "'header_handle' => [\$headerMediaSource]"));
check('Templates builds carousel cards', str_contains($controller, "function buildCarouselCards("));
check('Templates validates carousel card count', str_contains($controller, 'Carousel templates need between 2 and 10 cards.'));
check('Templates requires marketing for carousel', str_contains($controller, 'Carousel templates must use the Marketing category.'));
check('Templates emits CAROUSEL component', str_contains($controller, "'type'  => 'CAROUSEL'"));

$view = file_get_contents($root . '/app/Views/templates/create.php');
check('view has step 1 basics', str_contains($view, '1. Basics'));
check('view has step 2 content', str_contains($view, '2. Content'));
check('view has step 2 basics dropdowns', str_contains($view, 'id="templateSummaryCategory"') && str_contains($view, 'id="templateSummaryType"') && str_contains($view, 'id="templateSummaryLanguage"'));
check('view has template type select', str_contains($view, 'Select template type'));
check('view has language select', str_contains($view, 'Select language'));
check('view has header type select', str_contains($view, 'Header Type'));
check('view has upload media box', str_contains($view, 'Choose File'));
check('view has manual media URL toggle', str_contains($view, 'Use media URL instead'));
check('view has add variable button', str_contains($view, 'Add variable'));
check('view has per-variable example chips', str_contains($view, 'template-var-chip') || str_contains($view, 'js-var-example'));
check('view has body format toolbar', str_contains($view, 'js-body-format'));
check('view has phone message preview', str_contains($view, 'Message Preview'));
check('view has CTA type field', str_contains($view, 'Button Type'));
check('view has Quick Reply button type', str_contains($view, 'Quick Reply'));
check('view has multi-button list', str_contains($view, 'id="templateButtonsList"') && str_contains($view, 'name="template_buttons"'));
check('view has CTA phone field', str_contains($view, 'CTA Phone Number'));
check('view has add button CTA builder', str_contains($view, 'Add Button'));
check('view has carousel cards builder', str_contains($view, 'Carousel Cards'));
check('view has add carousel card button', str_contains($view, 'Add Card'));
check('view has live preview', str_contains($view, 'Message Preview') || str_contains($view, 'Live Preview'));
check('view AJAX submit uses APP.post', str_contains($view, 'APP.post($form.attr(\'action\'), $form.serialize())'));

$model = file_get_contents($root . '/app/Models/TemplateModel.php');
check('model allows template_type', str_contains($model, "'template_type'"));
check('model validates template_type', str_contains($model, "permit_empty|in_list[default,carousel]"));

$schemaCommand = file_get_contents($root . '/app/Commands/EnsureWhatsAppSchema.php');
check('ensure schema includes templates table', str_contains($schemaCommand, "'templates' => ["));
check('ensure schema backfills template_type', str_contains($schemaCommand, "UPDATE `templates` SET `template_type` = 'default'"));

$migration = file_get_contents($root . '/app/Database/Migrations/2026-07-28-154200_AddTemplateTypeToTemplates.php');
check('migration adds template_type', str_contains($migration, "'template_type' => ["));
check('migration sets default type', str_contains($migration, "'default'    => 'default'"));

$ref = new ReflectionClass(\App\Controllers\Templates::class);
check('Templates controller class loads', $ref->isInstantiable());
foreach (['create', 'store', 'sync', 'preview'] as $method) {
    check("Templates::{$method} exists", $ref->hasMethod($method));
}

echo "\n=== Result: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
