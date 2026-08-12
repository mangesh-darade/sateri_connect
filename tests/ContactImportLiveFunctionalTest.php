<?php

declare(strict_types=1);

/**
 * Live CSV + XLSX contact import commit against the tenant DB.
 *
 * Run: php tests/ContactImportLiveFunctionalTest.php
 */

define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);
chdir(FCPATH);

require FCPATH . '../app/Config/Paths.php';
$paths = new \Config\Paths();
require $paths->systemDirectory . '/Boot.php';
\CodeIgniter\Boot::bootSpark($paths);

use App\Libraries\ContactImportService;
use App\Models\ContactModel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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

echo "=== Contact Import Live Functional Test ===\n\n";

$db    = db_connect();
$model = model(ContactModel::class);
$svc   = new ContactImportService();

$mobiles = [
    '919111000001',
    '919111000002',
    '919111000003',
    '919111000004',
];

$cleanup = static function () use ($db, $mobiles): void {
    $ids = array_column(
        $db->table('contacts')->select('id')->whereIn('mobile', $mobiles)->get()->getResultArray(),
        'id'
    );
    if ($ids !== []) {
        $db->table('contact_tags')->whereIn('contact_id', $ids)->delete();
        $db->table('messages')->whereIn('contact_id', $ids)->delete();
        $db->table('conversations')->whereIn('contact_id', $ids)->delete();
        $db->table('contacts')->whereIn('id', $ids)->delete();
    }
};

$cleanup();

// --- CSV import with filled data ---
$csvPath = WRITEPATH . 'uploads/imports/_test_contacts.csv';
if (! is_dir(dirname($csvPath))) {
    mkdir(dirname($csvPath), 0755, true);
}
file_put_contents(
    $csvPath,
    "Name,Phone,Email,City,Tags\n"
    . "CSV Ada,{$mobiles[0]},ada@csv.import.test,Pune,\"vip,import\"\n"
    . "CSV Bob,{$mobiles[1]},bob@csv.import.test,Nashik,lead\n"
    . ",,missing@csv.import.test,Goa,\n" // missing mobile → skip + error
);

$csvPreview = $svc->parseUpload($csvPath, 'test_contacts.csv');
@unlink($csvPath);

check('CSV preview ok', ($csvPreview['row_count'] ?? 0) >= 2, json_encode($csvPreview));

$csvResult = $svc->commit($csvPreview['token'], [
    'Name'  => 'name',
    'Phone' => 'mobile',
    'Email' => 'email',
    'City'  => 'new:city',
    'Tags'  => 'tags',
], null, true);

check('CSV imported 2 contacts', (int) ($csvResult['imported'] ?? 0) === 2, json_encode($csvResult));
check('CSV skipped empty mobile row', (int) ($csvResult['skipped'] ?? 0) >= 1, json_encode($csvResult));
check('CSV reports missing mobile error', ! empty($csvResult['errors']), json_encode($csvResult['errors'] ?? []));
check('CSV Ada exists in DB', $model->findByMobile($mobiles[0]) !== null);
check('CSV Bob exists in DB', $model->findByMobile($mobiles[1]) !== null);

$ada = $model->findByMobile($mobiles[0]);
$adaCustom = $ada['custom_fields'] ?? [];
if (is_string($adaCustom)) {
    $adaCustom = json_decode($adaCustom, true) ?: [];
}
check('CSV Ada custom city saved', ($adaCustom['city'] ?? '') === 'Pune', json_encode($adaCustom));
check('CSV created city custom field key', in_array('city', $csvResult['custom_fields_created'] ?? [], true));

// Duplicate skip
$csvPath2 = WRITEPATH . 'uploads/imports/_test_contacts_dup.csv';
file_put_contents($csvPath2, "Name,Phone\nDup Ada,{$mobiles[0]}\n");
$dupPreview = $svc->parseUpload($csvPath2, 'dup.csv');
@unlink($csvPath2);
$dupResult = $svc->commit($dupPreview['token'], [
    'Name'  => 'name',
    'Phone' => 'mobile',
], null, true);
check('CSV skip duplicates works', (int) ($dupResult['imported'] ?? 0) === 0 && (int) ($dupResult['skipped'] ?? 0) === 1, json_encode($dupResult));

// --- XLSX import with filled data ---
$xlsxPath = WRITEPATH . 'uploads/imports/_test_contacts.xlsx';
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->fromArray([
    ['Full Name', 'Mobile', 'Email', 'Country', 'Notes'],
    ['XLSX Ravi', $mobiles[2], 'ravi@xlsx.import.test', 'IN', 'From excel'],
    ['XLSX Priya', $mobiles[3], 'priya@xlsx.import.test', 'IN', 'From excel'],
], null, 'A1');
(new Xlsx($spreadsheet))->save($xlsxPath);
$spreadsheet->disconnectWorksheets();

$xlsxPreview = $svc->parseUpload($xlsxPath, 'test_contacts.xlsx');
@unlink($xlsxPath);

check('XLSX preview format', ($xlsxPreview['format'] ?? '') === 'xlsx');
check('XLSX preview row count', (int) ($xlsxPreview['row_count'] ?? 0) === 2);

$xlsxResult = $svc->commit($xlsxPreview['token'], [
    'Full Name' => 'name',
    'Mobile'    => 'mobile',
    'Email'     => 'email',
    'Country'   => 'country',
    'Notes'     => 'notes',
], null, true);

check('XLSX imported 2 contacts', (int) ($xlsxResult['imported'] ?? 0) === 2, json_encode($xlsxResult));
check('XLSX Ravi exists', $model->findByMobile($mobiles[2]) !== null);
$priya = $model->findByMobile($mobiles[3]);
check('XLSX Priya notes saved', is_array($priya) && ($priya['notes'] ?? '') === 'From excel', json_encode($priya));

// --- Mapping error: no mobile ---
$noMobilePath = WRITEPATH . 'uploads/imports/_test_nomobile.csv';
file_put_contents($noMobilePath, "Name,Email\nNo Mobile,x@y.com\n");
$noMobilePreview = $svc->parseUpload($noMobilePath, 'nomobile.csv');
@unlink($noMobilePath);
$noMobileFailed = false;
try {
    $svc->commit($noMobilePreview['token'], [
        'Name'  => 'name',
        'Email' => 'email',
    ], null, true);
} catch (Throwable $e) {
    $noMobileFailed = str_contains($e->getMessage(), 'Mobile');
}
check('commit rejects mapping without mobile', $noMobileFailed);

$cleanup();

echo "\n=== Result: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
