<?php

/**
 * Unit checks for ContactImportService CSV/XLSX + mapping + Reports Excel filter wiring.
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
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$svc = new ContactImportService();

check('maps mobile alias phone → mobile', $svc->suggestDestination('Phone') === 'mobile');
check('maps Full Name → name', $svc->suggestDestination('Full Name') === 'name');
check('maps email → email', $svc->suggestDestination('email') === 'email');
check('maps tags → tags', $svc->suggestDestination('Tags') === 'tags');
check('unknown City becomes new custom field', str_starts_with($svc->suggestDestination('City'), 'new:'));
check('sanitizes City key', $svc->sanitizeCustomKey('City') === 'city');
check('sanitizes Company Name key', $svc->sanitizeCustomKey('Company Name') === 'company_name');
check('avoids clobbering mobile core key', $svc->sanitizeCustomKey('mobile') === 'custom_mobile');
check('detectFormat accepts csv', $svc->detectFormat('contacts.csv') === 'csv');
check('detectFormat accepts xlsx', $svc->detectFormat('contacts.xlsx') === 'xlsx');

$rejectedBadExt = false;
try {
    $svc->detectFormat('contacts.xls');
} catch (Throwable $e) {
    $rejectedBadExt = str_contains($e->getMessage(), 'CSV or XLSX');
}
check('detectFormat rejects .xls', $rejectedBadExt);

// --- CSV preview ---
$tmp = tempnam(sys_get_temp_dir(), 'csv_imp_');
file_put_contents($tmp, "Name,Phone,City,Company Name\nAda,919999999999,Pune,Acme\n");

$preview = $svc->parseUpload($tmp, 'sample.csv');
@unlink($tmp);

check('CSV preview returns token', isset($preview['token']) && strlen($preview['token']) === 32);
check('CSV preview format is csv', ($preview['format'] ?? '') === 'csv');
check('CSV preview keeps original headers', $preview['headers'] === ['Name', 'Phone', 'City', 'Company Name']);
check('CSV preview suggests mobile for Phone', ($preview['suggested_mapping']['Phone'] ?? '') === 'mobile');
check('CSV preview suggests new field for City', str_starts_with((string) ($preview['suggested_mapping']['City'] ?? ''), 'new:'));
check('CSV preview counts data rows', (int) ($preview['row_count'] ?? 0) === 1);
check('CSV preview includes destinations list', is_array($preview['destinations'] ?? null) && count($preview['destinations']) >= 5);
check('CSV preview not truncated', empty($preview['truncated']));

$staged = WRITEPATH . 'uploads/imports/' . $preview['token'] . '.csv';
check('CSV preview stages under writable/uploads/imports', is_file($staged));

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

// --- XLSX preview ---
$xlsxPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'xlsx_imp_' . bin2hex(random_bytes(4)) . '.xlsx';
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->fromArray([
    ['Full Name', 'Mobile', 'Email', 'City'],
    ['Ravi Kumar', '918888888888', 'ravi@import.test', 'Mumbai'],
    ['Priya Shah', '917777777777', 'priya@import.test', 'Surat'],
], null, 'A1');
(new Xlsx($spreadsheet))->save($xlsxPath);
$spreadsheet->disconnectWorksheets();

$xlsxPreview = $svc->parseUpload($xlsxPath, 'contacts_import.xlsx');
@unlink($xlsxPath);

check('XLSX preview returns token', isset($xlsxPreview['token']) && strlen($xlsxPreview['token']) === 32);
check('XLSX preview format is xlsx', ($xlsxPreview['format'] ?? '') === 'xlsx');
check('XLSX preview headers', $xlsxPreview['headers'] === ['Full Name', 'Mobile', 'Email', 'City']);
check('XLSX suggests name for Full Name', ($xlsxPreview['suggested_mapping']['Full Name'] ?? '') === 'name');
check('XLSX suggests mobile for Mobile', ($xlsxPreview['suggested_mapping']['Mobile'] ?? '') === 'mobile');
check('XLSX counts 2 data rows', (int) ($xlsxPreview['row_count'] ?? 0) === 2);
check('XLSX sample rows present', count($xlsxPreview['sample_rows'] ?? []) === 2);

$xlsxStaged = WRITEPATH . 'uploads/imports/' . $xlsxPreview['token'] . '.csv';
check('XLSX is staged as CSV for commit', is_file($xlsxStaged));
$stagedBody = (string) file_get_contents($xlsxStaged);
check('staged CSV contains Ravi', str_contains($stagedBody, 'Ravi Kumar'));
check('staged CSV contains Priya mobile', str_contains($stagedBody, '917777777777'));
@unlink($xlsxStaged);

// --- Error handling ---
$emptyCsv = tempnam(sys_get_temp_dir(), 'csv_empty_');
file_put_contents($emptyCsv, '');
$emptyFailed = false;
try {
    $svc->parseUpload($emptyCsv, 'empty.csv');
} catch (Throwable $e) {
    $emptyFailed = str_contains(strtolower($e->getMessage()), 'empty');
}
@unlink($emptyCsv);
check('empty CSV throws clear error', $emptyFailed);

$headerOnly = tempnam(sys_get_temp_dir(), 'csv_hdr_');
file_put_contents($headerOnly, "name,mobile\n");
$headerOnlyFailed = false;
try {
    $svc->parseUpload($headerOnly, 'headers_only.csv');
} catch (Throwable $e) {
    $headerOnlyFailed = str_contains(strtolower($e->getMessage()), 'no data');
}
@unlink($headerOnly);
check('header-only CSV throws clear error', $headerOnlyFailed);

$badNameFailed = false;
try {
    $svc->parseUpload(__FILE__, 'notes.txt');
} catch (Throwable $e) {
    $badNameFailed = str_contains($e->getMessage(), 'CSV or XLSX');
}
check('non csv/xlsx extension rejected', $badNameFailed);

$invalidTokenFailed = false;
try {
    $svc->commit('not-a-token', ['Phone' => 'mobile'], null, true);
} catch (Throwable $e) {
    $invalidTokenFailed = str_contains(strtolower($e->getMessage()), 'invalid import');
}
check('invalid commit token rejected', $invalidTokenFailed);

$expiredTokenFailed = false;
try {
    $svc->commit(str_repeat('ab', 16), ['Phone' => 'mobile'], null, true);
} catch (Throwable $e) {
    $expiredTokenFailed = str_contains(strtolower($e->getMessage()), 'expired');
}
check('missing staged file rejected', $expiredTokenFailed);

// --- Wiring / UI ---
$reports = file_get_contents(dirname(__DIR__) . '/app/Controllers/Reports.php');
$routes  = file_get_contents(dirname(__DIR__) . '/app/Config/Routes.php');
$importView = file_get_contents(dirname(__DIR__) . '/app/Views/contacts/import.php');
$contactsJs = file_get_contents(dirname(__DIR__) . '/public/assets/js/contacts.js');
$contactsCtrl = file_get_contents(dirname(__DIR__) . '/app/Controllers/Contacts.php');
$createView = file_get_contents(dirname(__DIR__) . '/app/Views/templates/create.php');
$reportsView = file_get_contents(dirname(__DIR__) . '/app/Views/reports/index.php');

check('exportExcel no longer dumps findAll for campaigns', ! preg_match('/type === \'campaigns\'[\s\S]{0,200}findAll\(\)/', $reports));
check('exportExcel writes campaign_breakdown section', str_contains($reports, 'campaign_breakdown'));
check('exportExcel supports contacts type', str_contains($reports, "type === 'contacts'"));
check('Reports has campaignContacts endpoint', str_contains($reports, 'function campaignContacts'));
check('route for campaign-contacts exists', str_contains($routes, 'reports/campaign-contacts'));
check('route for import preview exists', str_contains($routes, 'contacts/import/preview'));
check('route for import commit exists', str_contains($routes, 'contacts/import/commit'));
check('import UI accepts xlsx', str_contains($importView, '.xlsx'));
check('import UI has sample XLSX link', str_contains($importView, 'format=xlsx'));
check('import UI has group select', str_contains($importView, 'importGroupId'));
check('import UI has mapping step', str_contains($importView, 'importStepMap'));
check('import UI has truncation warning slot', str_contains($importView, 'importMapWarning'));
check('contacts.js validates csv/xlsx', str_contains($contactsJs, 'allowedImportFile'));
check('contacts.js posts preview', str_contains($contactsJs, '/contacts/import/preview'));
check('contacts.js posts commit with mapping JSON', str_contains($contactsJs, 'JSON.stringify(mapping)'));
check('Contacts controller has sample XLSX download', str_contains($contactsCtrl, 'downloadSampleXlsx'));
check('Contacts preview message mentions CSV or XLSX', str_contains($contactsCtrl, 'CSV or XLSX'));
check('template create status is Uploaded checkmark', str_contains($createView, "text('Uploaded ✓')"));
check('template create does not fill manual URL with preview path after upload', str_contains($createView, "\$headerMediaManualUrl.val('')"));
check('reports shows contacts table when filtered', str_contains($reportsView, 'reportCampaignContactsTable'));
check('reports contacts excel button present', str_contains($reportsView, 'Contacts Excel'));

echo "\n=== Result: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
