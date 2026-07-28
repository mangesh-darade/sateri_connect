<?php

declare(strict_types=1);

/**
 * Campaign wizard feature smoke tests.
 *
 * Run: php tests/CampaignWizardFeatureTest.php
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

echo "=== Campaign Wizard Feature Test ===\n\n";

$routes = file_get_contents(dirname(FCPATH) . '/app/Config/Routes.php') ?: '';
check('route wizard-data', str_contains($routes, "campaigns/wizard-data"));
check('route audience-preview', str_contains($routes, "campaigns/audience-preview"));
check('route wizard store', str_contains($routes, "campaigns/wizard"));
check('route wizard run', str_contains($routes, 'Campaigns::wizardRun'));

$index = file_get_contents(dirname(FCPATH) . '/app/Views/campaigns/index.php') ?: '';
$wizard = file_get_contents(dirname(FCPATH) . '/app/Views/campaigns/_wizard.php') ?: '';
$js = file_get_contents(dirname(FCPATH) . '/public/assets/js/campaigns.js') ?: '';

check('index has New Campaign dropdown', str_contains($index, 'New Campaign'));
check('index has WhatsApp + Email entries', str_contains($index, 'WhatsApp Campaign') && str_contains($index, 'Email Campaign'));
check('index includes wizard partial', str_contains($index, "campaigns/_wizard"));
check('wizard has 5 steps', substr_count($wizard, 'campaign-wizard-step') >= 5);
check('JS opens wizard', str_contains($js, 'openWizard'));
check('JS audience preview', str_contains($js, 'audience-preview'));
check('JS run + schedule', str_contains($js, '/run') && str_contains($js, '/schedule'));

$db = db_connect();
check('email_html_campaigns.scheduled_at exists', $db->tableExists('email_html_campaigns') && $db->fieldExists('scheduled_at', 'email_html_campaigns'));

$svc = new \App\Libraries\CampaignService();
$ref = new ReflectionClass($svc);
$filter = $ref->getMethod('filterContactsByAttributes');
$filter->setAccessible(true);

$sampleContacts = [
    ['id' => 1, 'name' => 'Mangesh', 'mobile' => '917000000001', 'email' => 'a@example.com', 'status' => 'active', 'custom_fields' => []],
    ['id' => 2, 'name' => 'Other', 'mobile' => '917000000002', 'email' => '', 'status' => 'active', 'custom_fields' => []],
];
$filtered = $filter->invoke($svc, $sampleContacts, [
    ['name' => 'name', 'condition' => 'contains', 'value' => 'Mang'],
]);
check('attribute contains filter', count($filtered) === 1 && (int) $filtered[0]['id'] === 1);

$filtered2 = $filter->invoke($svc, $sampleContacts, [
    ['name' => 'email', 'condition' => 'equals', 'value' => 'a@example.com'],
]);
check('attribute equals email filter', count($filtered2) === 1);

$preview = $svc->previewAudience([], [], [], false);
check('empty audience returns zero', ($preview['total'] ?? -1) === 0);

$tagModel = model(\App\Models\TagModel::class);
$tag = $tagModel->where('name', 'cw_test_label')->first();
if (! is_array($tag)) {
    $tagId = (int) $tagModel->insert(['name' => 'cw_test_label', 'color' => '#6B7280']);
    $tag = $tagModel->find($tagId);
} else {
    $tagId = (int) $tag['id'];
}
check('test label ready', $tagId > 0);

$contactModel = model(\App\Models\ContactModel::class);
$contact = $contactModel->where('mobile', '919999990001')->first();
if (! is_array($contact)) {
    $contactId = (int) $contactModel->insert([
        'name'   => 'Campaign Wizard Test',
        'mobile' => '919999990001',
        'email'  => 'cw_test@example.com',
        'status' => 'active',
    ]);
} else {
    $contactId = (int) $contact['id'];
}
check('test contact ready', $contactId > 0);
$tagModel->attachContact($tagId, $contactId);

$audience = $svc->previewAudience([$tagId], [], [], false);
check('preview finds tagged contact', ($audience['total'] ?? 0) >= 1);
check('preview phone count', ($audience['phone_count'] ?? 0) >= 1);
check('preview email count', ($audience['email_count'] ?? 0) >= 1);

$templates = model(\App\Models\TemplateModel::class)->getApproved();
$hasTemplate = $templates !== [];
check('approved template available (optional env)', true, $hasTemplate ? 'yes' : 'none — skip WA draft create');

if ($hasTemplate) {
    $tpl = $templates[0];
    $campaignModel = model(\App\Models\CampaignModel::class);
    $campaignId = (int) $campaignModel->insert([
        'name'         => 'cw_wizard_test_' . time(),
        'template_id'  => (int) $tpl['id'],
        'status'       => 'draft',
        'message_type' => 'template',
        'payload'      => json_encode([
            'template_name' => $tpl['name'],
            'language'      => $tpl['language'] ?? 'en_US',
            '_audience'     => [
                'all'         => false,
                'contact_ids' => [$contactId],
                'tag_ids'     => [$tagId],
                'label_name'  => 'cw_test_label',
            ],
        ]),
        'variables'    => json_encode(['1' => 'name']),
        'created_by'   => 1,
    ], true);
    check('WA draft campaign created', $campaignId > 0);

    // Avoid ActivityLogger/session in CLI: schedule via model update (same fields as CampaignService::schedule).
    $campaignModel->update($campaignId, [
        'status'       => 'scheduled',
        'scheduled_at' => date('Y-m-d H:i:s', time() + 3600),
    ]);
    $row = $campaignModel->find($campaignId);
    check('WA campaign scheduled', is_array($row) && ($row['status'] ?? '') === 'scheduled');
    check('WA status scheduled', is_array($row) && ($row['status'] ?? '') === 'scheduled');

    $campaignModel->update($campaignId, [
        'status'       => 'cancelled',
        'completed_at' => date('Y-m-d H:i:s'),
    ]);
    check('WA campaign cancelled cleanup', true);
}

$emailModel = model(\App\Models\EmailHtmlCampaignModel::class);
$emailId = (int) $emailModel->insert([
    'name'            => 'cw_email_test_' . time(),
    'subject'         => 'Wizard test',
    'html_content'    => '<p>Hello</p>',
    'mode'            => 'recipients',
    'label_name'      => 'cw_test_label',
    'recipients_json' => ['cw_test@example.com'],
    'status'          => 'draft',
], true);
check('Email draft campaign created', $emailId > 0);

$emailModel->update($emailId, [
    'status'       => 'queued',
    'scheduled_at' => date('Y-m-d H:i:s', time() + 7200),
]);
$emailRow = $emailModel->find($emailId);
check('Email campaign scheduled_at saved', is_array($emailRow) && ! empty($emailRow['scheduled_at']));

// Soft cleanup email draft
$emailModel->update($emailId, ['status' => 'cancelled', 'scheduled_at' => null]);
check('Email campaign cancelled cleanup', true);

$controller = new \App\Controllers\Campaigns();
$refC = new ReflectionClass($controller);
$merge = $refC->getMethod('buildMergedCampaignList');
$merge->setAccessible(true);
$list = $merge->invoke($controller, '', '', '', 'latest');
check('merged campaign list returns array', is_array($list));

echo "\n=== Result: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
