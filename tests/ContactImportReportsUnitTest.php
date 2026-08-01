<?php

/**
 * Unit checks for ContactImportService mapping + Reports Excel filter wiring.
 */

define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);
if (! defined('WRITEPATH')) {
    define('WRITEPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'writable' . DIRECTORY_SEPARATOR);
}

require dirname(__DIR__) . '/vendor/autoload.php';

$pass = 0;
$fail = 0;

function check(string $label, bool $ok): void
{
    global $pass, $fail;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
}

echo "=== Contact Import + Reports Export Unit Test ===\n\n";

use App\Libraries\ContactImportService;

$svc = new ContactImportService();

check('maps mobile alias phone → mobile', $svc->suggestDestination('Phone') === 'mobile');
check('maps Full Name → name', $svc->suggestDestination('Full Name') === 'name');
check('maps email → email', $svc->suggestDestination('email') === 'email');
check('maps tags → tags', $svc->suggestDestination('Tags') === 'tags');
check('unknown City becomes new custom field', str_starts_with($svc->suggestDestination('City'), 'new:'));
check('sanitizes City key', $svc->sanitizeCustomKey('City') === 'city');
check('sanitizes Company Name key', $svc->sanitizeCustomKey('Company Name') === 'company_name');
check('avoids clobbering mobile core key', $svc->sanitizeCustomKey('mobile') === 'custom_mobile');

$tmp = tempnam(sys_get_temp_dir(), 'csv_imp_');
file_put_contents($tmp, "Name,Phone,City,Company Name\nAda,919999999999,Pune,Acme\n");

$preview = $svc->parseUpload($tmp, 'sample.csv');
@unlink($tmp);

check('preview returns token', isset($preview['token']) && strlen($preview['token']) === 32);
check('preview keeps original headers', $preview['headers'] === ['Name', 'Phone', 'City', 'Company Name']);
check('preview suggests mobile for Phone', ($preview['suggested_mapping']['Phone'] ?? '') === 'mobile');
check('preview suggests new field for City', str_starts_with((string) ($preview['suggested_mapping']['City'] ?? ''), 'new:'));
check('preview counts data rows', (int) ($preview['row_count'] ?? 0) === 1);
check('preview includes destinations list', is_array($preview['destinations'] ?? null) && count($preview['destinations']) >= 5);

$staged = WRITEPATH . 'uploads/imports/' . $preview['token'] . '.csv';
check('preview stages CSV under writable/uploads/imports', is_file($staged));

// Commit without DB models would fail in pure autoload — verify mapping resolution via reflection.
$ref = new ReflectionClass($svc);
$resolve = $ref->getMethod('resolveMapping');
$resolve->setAccessible(true);
$resolved = $resolve->invoke($svc, $preview['headers'], [
    'Name'         => 'name',
    'Phone'        => 'mobile',
    'City'         => 'new:city',
    'Company Name' => 'new:company_name',
]);
check('resolve maps mobile index', $resolved['mobile'] === 1);
check('resolve maps name index', $resolved['name'] === 0);
check('resolve collects custom City', ($resolved['custom'][2] ?? '') === 'city');
check('resolve collects custom Company', ($resolved['custom'][3] ?? '') === 'company_name');
check('resolve tracks newly created keys', in_array('city', $resolved['new_custom_keys'], true)
    && in_array('company_name', $resolved['new_custom_keys'], true));

$rowValues = $ref->getMethod('rowValues');
$rowValues->setAccessible(true);
$values = $rowValues->invoke($svc, $preview['headers'], ['Ada', '919999999999', 'Pune', 'Acme'], $resolved);
check('row values include custom city', ($values['custom_fields']['city'] ?? '') === 'Pune');
check('row values include custom company', ($values['custom_fields']['company_name'] ?? '') === 'Acme');
check('row values keep name', ($values['name'] ?? '') === 'Ada');

@unlink($staged);

$reports = file_get_contents(dirname(__DIR__) . '/app/Controllers/Reports.php');
$routes  = file_get_contents(dirname(__DIR__) . '/app/Config/Routes.php');
$importView = file_get_contents(dirname(__DIR__) . '/app/Views/contacts/import.php');
$contactsJs = file_get_contents(dirname(__DIR__) . '/public/assets/js/contacts.js');
$createView = file_get_contents(dirname(__DIR__) . '/app/Views/templates/create.php');
$reportsView = file_get_contents(dirname(__DIR__) . '/app/Views/reports/index.php');

check('exportExcel no longer dumps findAll for campaigns', ! preg_match('/type === \'campaigns\'[\s\S]{0,200}findAll\(\)/', $reports));
check('exportExcel writes campaign_breakdown section', str_contains($reports, 'campaign_breakdown'));
check('exportExcel supports contacts type', str_contains($reports, "type === 'contacts'"));
check('Reports has campaignContacts endpoint', str_contains($reports, 'function campaignContacts'));
check('route for campaign-contacts exists', str_contains($routes, 'reports/campaign-contacts'));
check('route for import preview exists', str_contains($routes, 'contacts/import/preview'));
check('route for import commit exists', str_contains($routes, 'contacts/import/commit'));
check('import UI has group select', str_contains($importView, 'importGroupId'));
check('import UI has mapping step', str_contains($importView, 'importStepMap'));
check('contacts.js posts preview', str_contains($contactsJs, '/contacts/import/preview'));
check('contacts.js posts commit with mapping JSON', str_contains($contactsJs, 'JSON.stringify(mapping)'));
check('template create status is Uploaded checkmark', str_contains($createView, "text('Uploaded ✓')"));
check('template create does not fill manual URL with preview path after upload', str_contains($createView, "\$headerMediaManualUrl.val('')"));
check('reports shows contacts table when filtered', str_contains($reportsView, 'reportCampaignContactsTable'));
check('reports contacts excel button present', str_contains($reportsView, 'Contacts Excel'));

echo "\n=== Result: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
