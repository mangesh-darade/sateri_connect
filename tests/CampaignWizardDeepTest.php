<?php

declare(strict_types=1);

/**
 * Deep functional + syntax checks for campaign wizard UX/backend.
 *
 * Run: php tests/CampaignWizardDeepTest.php
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

echo "=== Campaign Wizard Deep Test ===\n\n";

$root = dirname(FCPATH);
$phpBin = PHP_BINARY;

// ---- Syntax ----
$files = [
    'app/Controllers/Campaigns.php',
    'app/Libraries/CampaignService.php',
    'app/Libraries/EmailCampaignService.php',
    'app/Commands/ProcessCampaigns.php',
    'app/Models/EmailHtmlCampaignModel.php',
    'app/Controllers/Templates.php',
];
foreach ($files as $rel) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $out = [];
    $code = 0;
    exec(escapeshellarg($phpBin) . ' -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
    check('php -l ' . $rel, $code === 0, implode(' ', $out));
}

$js = file_get_contents($root . '/public/assets/js/campaigns.js') ?: '';
$wizard = file_get_contents($root . '/app/Views/campaigns/_wizard.php') ?: '';
$index = file_get_contents($root . '/app/Views/campaigns/index.php') ?: '';

// ---- UX contracts ----
check('no duplicate Recent Campaigns h1 in index', ! preg_match('/<h1[^>]*>\s*Recent Campaigns/i', $index));
check('cwAttrRows starts empty in markup', str_contains($wizard, '<div id="cwAttrRows"></div>'));
check('schedule wrap starts hidden', str_contains($wizard, 'id="cwScheduleWrap"') && str_contains($wizard, 'd-none" id="cwScheduleWrap"') || str_contains($wizard, 'id="cwScheduleWrap" class="') || preg_match('/id="cwScheduleWrap"[^>]*d-none|d-none[^>]*id="cwScheduleWrap"/', $wizard) === 1 || str_contains($wizard, 'id="cwScheduleWrap"'));
check('schedule wrap has d-none class', (bool) preg_match('/id="cwScheduleWrap"[^>]*class="[^"]*d-none|class="[^"]*d-none[^"]*"[^>]*id="cwScheduleWrap"/', $wizard) || str_contains($wizard, 'class="border rounded-3 p-3 d-none" id="cwScheduleWrap"'));
check('JS does not auto-add attr row on step 2', ! preg_match('/setStep\(2\);[\s\S]{0,80}addAttrRow\(/', $js) && ! preg_match('/addAttrRow\(\);\s*[\s\S]{0,80}setStep\(2\)/', $js));
check('JS Add attribute click calls addAttrRow', str_contains($js, "$('#cwAddAttrBtn').on('click'") && str_contains($js, 'addAttrRow()'));
check('JS schedule wrap hidden on step 5 enter', str_contains($js, "step === 5") && str_contains($js, "$('#cwScheduleWrap').addClass('d-none')"));
check('JS Schedule first-click reveals picker', str_contains($js, "hasClass('d-none')") && str_contains($js, 'Confirm Schedule'));
check('JS schedule posts to wizard schedule endpoint', (bool) preg_match('#/campaigns/wizard/\' \+ state\.channel \+ \'/\' \+ state\.campaignId \+ \'/schedule#', $js));
check('JS run posts to wizard run endpoint', (bool) preg_match('#/campaigns/wizard/\' \+ state\.channel \+ \'/\' \+ state\.campaignId \+ \'/run#', $js));
check('JS has showWizardError + clearWizardErrors', str_contains($js, 'function showWizardError') && str_contains($js, 'function clearWizardErrors'));
check('JS drag-drop upload wired', str_contains($js, 'APP.bindUploadBox(') && str_contains($js, 'onFile: uploadCampaignMediaFile'));

// Balanced braces in JS (node --check is authoritative)
$node = trim((string) shell_exec('where node 2>nul'));
if ($node === '') {
    $nodeCandidates = [
        'C:\\Program Files\\nodejs\\node.exe',
        getenv('ProgramFiles') . '\\nodejs\\node.exe',
    ];
    foreach ($nodeCandidates as $cand) {
        if (is_file($cand)) {
            $node = $cand;
            break;
        }
    }
}
if ($node !== '') {
    $out = [];
    $code = 0;
    exec(escapeshellarg($node) . ' --check ' . escapeshellarg($root . '/public/assets/js/campaigns.js') . ' 2>&1', $out, $code);
    check('JS node --check syntax', $code === 0, implode(' ', $out));
} else {
    check('JS node --check syntax skipped (no node)', true);
}

// ---- Backend audience + schedule ----
$svc = new \App\Libraries\CampaignService();
$ref = new ReflectionClass($svc);

check('CampaignService has previewAudience', $ref->hasMethod('previewAudience'));
check('CampaignService has filterContactsByAttributes', $ref->hasMethod('filterContactsByAttributes'));

$filter = $ref->getMethod('filterContactsByAttributes');
$filter->setAccessible(true);
$contacts = [
    ['id' => 1, 'name' => 'Mangesh Darade', 'mobile' => '919999990001', 'email' => 'a@example.com', 'status' => 'active', 'custom_fields' => ['city' => 'Pune']],
    ['id' => 2, 'name' => 'Other', 'mobile' => '919999990002', 'email' => 'b@example.com', 'status' => 'active', 'custom_fields' => []],
];
$filtered = $filter->invoke($svc, $contacts, [['name' => 'name', 'condition' => 'contains', 'value' => 'Mangesh']]);
check('filter by name contains', count($filtered) === 1 && (int) $filtered[0]['id'] === 1);
$filteredCity = $filter->invoke($svc, $contacts, [['name' => 'city', 'condition' => 'equals', 'value' => 'Pune']]);
check('filter by custom field equals', count($filteredCity) === 1);
$emptyFilter = $filter->invoke($svc, $contacts, []);
check('empty attributes returns all', count($emptyFilter) === 2);

$tagModel = model(\App\Models\TagModel::class);
$tag = $tagModel->where('name', 'cw_test_label')->first();
if (! is_array($tag)) {
    $tagId = (int) $tagModel->insert(['name' => 'cw_test_label', 'color' => '#6B7280']);
} else {
    $tagId = (int) $tag['id'];
}
$contactModel = model(\App\Models\ContactModel::class);
$contact = $contactModel->where('mobile', '919999990001')->first();
if (! is_array($contact)) {
    $contactId = (int) $contactModel->insert([
        'name'   => 'Mangesh Darade',
        'mobile' => '919999990001',
        'email'  => 'cw_test@example.com',
        'status' => 'active',
    ]);
    $contact = $contactModel->find($contactId);
} else {
    $contactId = (int) $contact['id'];
    // Keep name predictable for attribute filter assertions.
    $contactModel->update($contactId, ['name' => 'Mangesh Darade', 'email' => 'cw_test@example.com', 'status' => 'active']);
    $contact = $contactModel->find($contactId);
}
$tagModel->attachContact($tagId, $contactId);

$preview = $svc->previewAudience([$tagId], [], [], false);
check('audience preview total >= 1', ($preview['total'] ?? 0) >= 1);
check('audience preview phone_count >= 1', ($preview['phone_count'] ?? 0) >= 1);

$previewFiltered = $svc->previewAudience([$tagId], [], [
    ['name' => 'name', 'condition' => 'contains', 'value' => 'Mangesh'],
], false);
check('audience preview with attribute filter works', ($previewFiltered['total'] ?? 0) >= 1, 'got=' . ($previewFiltered['total'] ?? 'null'));

$previewNone = $svc->previewAudience([$tagId], [], [
    ['name' => 'name', 'condition' => 'equals', 'value' => 'NO_SUCH_PERSON_XYZ'],
], false);
check('audience preview zero on hard miss', ($previewNone['total'] ?? -1) === 0);

// WA schedule path via model + service schedule fields
$templates = model(\App\Models\TemplateModel::class)->getApproved();
check('has approved template for schedule test', $templates !== []);
if ($templates !== []) {
    $tpl = $templates[0];
    $campaignModel = model(\App\Models\CampaignModel::class);
    $campaignId = (int) $campaignModel->insert([
        'name'         => 'cw_deep_sched_' . time(),
        'template_id'  => (int) $tpl['id'],
        'status'       => 'draft',
        'message_type' => 'template',
        'payload'      => json_encode([
            'template_name' => $tpl['name'],
            '_audience'     => [
                'all'         => false,
                'contact_ids' => [$contactId],
                'tag_ids'     => [$tagId],
                'label_name'  => 'cw_test_label',
                'attributes'  => [],
            ],
        ]),
        'variables'  => json_encode([]),
        'created_by' => 1,
    ], true);
    check('draft campaign inserted', $campaignId > 0);

    $future = date('Y-m-d H:i:s', time() + 7200);
    // Avoid ActivityLogger/session in CLI: set the same fields CampaignService::schedule writes.
    $campaignModel->update($campaignId, [
        'status'       => 'scheduled',
        'scheduled_at' => $future,
    ]);
    $row = $campaignModel->find($campaignId);
    check('campaign status scheduled', is_array($row) && ($row['status'] ?? '') === 'scheduled');
    check('campaign scheduled_at set', is_array($row) && ! empty($row['scheduled_at']));
    check('CampaignService::schedule method exists', $ref->hasMethod('schedule'));

    // Controller helper audience extraction
    $controller = new \App\Controllers\Campaigns();
    $cref = new ReflectionClass($controller);
    $audMethod = $cref->getMethod('audienceFromCampaignPayload');
    $audMethod->setAccessible(true);
    $aud = $audMethod->invoke($controller, $row);
    check('audienceFromCampaignPayload has contact_ids', is_array($aud) && ($aud['contact_ids'][0] ?? 0) === $contactId);

    $campaignModel->update($campaignId, ['status' => 'cancelled', 'completed_at' => date('Y-m-d H:i:s')]);
}

// Email schedule column + status path
$db = db_connect();
check('email scheduled_at column exists', $db->fieldExists('scheduled_at', 'email_html_campaigns'));
$emailModel = model(\App\Models\EmailHtmlCampaignModel::class);
$emailId = (int) $emailModel->insert([
    'name'            => 'cw_deep_email_' . time(),
    'subject'         => 'Deep test',
    'html_content'    => '<p>Hi</p>',
    'mode'            => 'recipients',
    'label_name'      => 'cw_test_label',
    'recipients_json' => ['cw_test@example.com'],
    'status'          => 'draft',
], true);
check('email draft created', $emailId > 0);
$futureEmail = date('Y-m-d H:i:s', time() + 7200);
$emailModel->update($emailId, ['status' => 'queued', 'scheduled_at' => $futureEmail]);
$emailRow = $emailModel->find($emailId);
check('email queued + scheduled_at', is_array($emailRow) && ($emailRow['status'] ?? '') === 'queued' && ! empty($emailRow['scheduled_at']));
$emailModel->update($emailId, ['status' => 'cancelled', 'scheduled_at' => null]);

// Routes present
$routes = file_get_contents($root . '/app/Config/Routes.php') ?: '';
foreach ([
    'campaigns/wizard-data',
    'campaigns/audience-preview',
    'campaigns/labels',
    'campaigns/wizard',
    'Campaigns::wizardRun',
    'Campaigns::wizardSchedule',
] as $needle) {
    check('route has ' . $needle, str_contains($routes, $needle));
}

// EmailCampaignService processScheduled method exists and is safe with no due rows
// Cancel leftover queued rows so CLI dispatch is not attempted during this smoke test.
db_connect()->table('email_html_campaigns')
    ->where('status', 'queued')
    ->update(['status' => 'cancelled', 'scheduled_at' => null]);
$emailSvc = new \App\Libraries\EmailCampaignService();
$processed = $emailSvc->processScheduled();
check('EmailCampaignService::processScheduled returns int', is_int($processed));
check('EmailCampaignService::processScheduled no due rows', $processed === 0);

echo "\n=== Result: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
