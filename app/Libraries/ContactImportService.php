<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\ContactModel;
use App\Models\TagModel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use RuntimeException;

/**
 * Multi-step contact CSV/XLSX import: parse → map → commit.
 */
class ContactImportService
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_ROWS  = 5000;

    private const ALLOWED_EXTENSIONS = ['csv', 'xlsx'];

    /**
     * Core / special destinations that are not custom_fields keys.
     *
     * @return list<string>
     */
    public static function fixedDestinations(): array
    {
        return ['name', 'mobile', 'email', 'country', 'notes', 'tags', 'skip'];
    }

    /**
     * @return list<array{value:string,label:string}>
     */
    public function destinations(): array
    {
        $out = [
            ['value' => 'skip', 'label' => '— Skip column —'],
            ['value' => 'name', 'label' => 'Name'],
            ['value' => 'mobile', 'label' => 'Mobile / Phone'],
            ['value' => 'email', 'label' => 'Email'],
            ['value' => 'country', 'label' => 'Country'],
            ['value' => 'notes', 'label' => 'Notes'],
            ['value' => 'tags', 'label' => 'Tags (comma-separated)'],
        ];

        foreach (ContactAttributes::knownKeys() as $key) {
            if (in_array($key, self::fixedDestinations(), true) || $key === 'status' || $key === 'birthday' || $key === 'phone') {
                continue;
            }
            $out[] = ['value' => 'custom:' . $key, 'label' => 'Custom: ' . $key];
        }

        return $out;
    }

    /**
     * Suggest a destination for a spreadsheet header.
     */
    public function suggestDestination(string $header): string
    {
        $h = strtolower(trim($header));
        $h = preg_replace('/[\s\-]+/', '_', $h) ?? $h;

        $aliases = [
            'name'     => 'name',
            'full_name'=> 'name',
            'fullname' => 'name',
            'mobile'   => 'mobile',
            'phone'    => 'mobile',
            'mobile_no'=> 'mobile',
            'phone_number' => 'mobile',
            'email'    => 'email',
            'email_address' => 'email',
            'country'  => 'country',
            'notes'    => 'notes',
            'note'     => 'notes',
            'tags'     => 'tags',
            'tag'      => 'tags',
            'group'    => 'tags',
            'groups'   => 'tags',
        ];

        if (isset($aliases[$h])) {
            return $aliases[$h];
        }

        foreach (ContactAttributes::coreKeys() as $core) {
            if ($core === $h) {
                return $core;
            }
        }

        // Unknown headers become new custom fields.
        return 'new:' . $this->sanitizeCustomKey($header);
    }

    public function sanitizeCustomKey(string $header): string
    {
        $key = strtolower(trim($header));
        $key = preg_replace('/[^a-z0-9_]+/', '_', $key) ?? $key;
        $key = trim($key, '_');
        if ($key === '' || preg_match('/^\d/', $key)) {
            $key = 'field_' . ($key !== '' ? $key : 'custom');
        }
        if (in_array($key, self::fixedDestinations(), true) || in_array($key, ContactAttributes::coreKeys(), true)) {
            $key = 'custom_' . $key;
        }

        return $key;
    }

    /**
     * Detect allowed spreadsheet format from original filename.
     *
     * @throws RuntimeException
     */
    public function detectFormat(string $originalName): string
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (! in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            throw new RuntimeException('Please upload a CSV or XLSX file.');
        }

        return $ext;
    }

    /**
     * Store upload and return preview payload for the mapping UI.
     *
     * @return array{
     *     token: string,
     *     filename: string,
     *     format: string,
     *     headers: list<string>,
     *     sample_rows: list<list<string>>,
     *     suggested_mapping: array<string,string>,
     *     destinations: list<array{value:string,label:string}>,
     *     row_count: int,
     *     truncated: bool,
     *     warning: ?string
     * }
     */
    public function parseUpload(string $tempPath, string $originalName = 'import.csv'): array
    {
        if (! is_file($tempPath) || ! is_readable($tempPath)) {
            throw new RuntimeException('Unable to read uploaded file.');
        }
        if (filesize($tempPath) > self::MAX_BYTES) {
            throw new RuntimeException('File exceeds 5MB limit.');
        }

        $format = $this->detectFormat($originalName);
        $parsed = $format === 'xlsx'
            ? $this->readXlsx($tempPath)
            : $this->readCsv($tempPath);

        $headers    = $parsed['headers'];
        $dataRows   = $parsed['rows'];
        $totalRows  = count($dataRows);
        $truncated = $totalRows > self::MAX_ROWS;
        if ($truncated) {
            $dataRows = array_slice($dataRows, 0, self::MAX_ROWS);
        }

        if ($headers === []) {
            throw new RuntimeException('File has no header row.');
        }
        if ($dataRows === []) {
            throw new RuntimeException('File has headers but no data rows.');
        }

        $dir = WRITEPATH . 'uploads/imports';
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException('Unable to create import staging directory.');
        }

        $token    = bin2hex(random_bytes(16));
        $destPath = $dir . DIRECTORY_SEPARATOR . $token . '.csv';
        if (! $this->writeStagedCsv($destPath, $headers, $dataRows)) {
            throw new RuntimeException('Unable to stage uploaded file.');
        }

        $suggested = [];
        foreach ($headers as $h) {
            $suggested[$h] = $this->suggestDestination($h);
        }

        $sampleRows = array_slice($dataRows, 0, 5);
        $warning    = $truncated
            ? 'Only the first ' . self::MAX_ROWS . ' of ' . $totalRows . ' rows will be imported.'
            : null;

        return [
            'token'             => $token,
            'filename'          => $originalName,
            'format'            => $format,
            'headers'           => $headers,
            'sample_rows'       => $sampleRows,
            'suggested_mapping' => $suggested,
            'destinations'      => $this->destinations(),
            'row_count'         => count($dataRows),
            'truncated'         => $truncated,
            'warning'           => $warning,
        ];
    }

    /**
     * @param array<string, string> $mapping header => destination
     *
     * @return array{imported:int,skipped:int,updated:int,errors:list<string>,custom_fields_created:list<string>,truncated:bool}
     */
    public function commit(string $token, array $mapping, ?int $tagId, bool $skipDuplicates): array
    {
        $token = preg_replace('/[^a-f0-9]/', '', strtolower($token)) ?? '';
        if (strlen($token) !== 32) {
            throw new RuntimeException('Invalid import session. Upload the file again.');
        }

        $path = WRITEPATH . 'uploads/imports' . DIRECTORY_SEPARATOR . $token . '.csv';
        if (! is_file($path)) {
            throw new RuntimeException('Import file expired. Upload the file again.');
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to read staged import file.');
        }

        $header = fgetcsv($handle, 0, ',', '"', '\\');
        if ($header === false) {
            fclose($handle);
            throw new RuntimeException('Staged import file is empty.');
        }

        $headers = [];
        foreach ($header as $i => $h) {
            $label = trim((string) $h);
            $headers[] = $label !== '' ? $label : ('Column_' . ($i + 1));
        }

        $resolved = $this->resolveMapping($headers, $mapping);
        if ($resolved['mobile'] === null) {
            fclose($handle);
            throw new RuntimeException('Map at least one column to Mobile / Phone.');
        }

        $model    = model(ContactModel::class);
        $tagModel = model(TagModel::class);
        $imported = 0;
        $skipped  = 0;
        $updated  = 0;
        $errors   = [];
        $rowNum   = 0;
        $truncated = false;
        $createdCustom = $resolved['new_custom_keys'];

        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if ($this->rowIsEmpty($row)) {
                continue;
            }
            $rowNum++;
            if ($rowNum > self::MAX_ROWS) {
                $truncated = true;
                $skipped++;
                break;
            }

            $values = $this->rowValues($headers, $row, $resolved);
            $mobile = normalize_phone((string) ($values['mobile'] ?? ''));
            if ($mobile === '') {
                $skipped++;
                if (count($errors) < 10) {
                    $errors[] = 'Row ' . $rowNum . ': missing or invalid mobile.';
                }
                continue;
            }

            $name = (string) ($values['name'] ?? '');
            if ($name !== '' && (
                str_starts_with($name, '=')
                || str_starts_with($name, '+')
                || str_starts_with($name, '-')
                || str_starts_with($name, '@')
            )) {
                $name = "'" . $name;
            }

            $existing = $model->findByMobile($mobile);
            if ($existing !== null && $skipDuplicates) {
                $skipped++;
                continue;
            }

            $customFields = is_array($values['custom_fields'] ?? null) ? $values['custom_fields'] : [];
            if ($existing !== null) {
                $prev = $existing['custom_fields'] ?? [];
                if (is_string($prev)) {
                    $decoded = json_decode($prev, true);
                    $prev    = is_array($decoded) ? $decoded : [];
                }
                if (! is_array($prev)) {
                    $prev = [];
                }
                $customFields = array_merge($prev, $customFields);
            }

            $payload = [
                'channel'       => $existing['channel'] ?? 'whatsapp',
                'name'          => $name !== '' ? $name : ($existing['name'] ?? null),
                'mobile'        => $mobile,
                'email'         => $values['email'] ?? ($existing['email'] ?? null),
                'country'       => $values['country'] ?? ($existing['country'] ?? null),
                'notes'         => $values['notes'] ?? ($existing['notes'] ?? null),
                'status'        => $existing['status'] ?? 'active',
                'custom_fields' => $customFields !== [] ? $customFields : ($existing['custom_fields'] ?? null),
            ];

            if ($existing !== null) {
                $ok = $model->update((int) $existing['id'], $payload);
                $contactId = (int) $existing['id'];
                if ($ok) {
                    $updated++;
                } else {
                    $skipped++;
                    $errors[] = $mobile . ': ' . implode(', ', $model->errors());
                    continue;
                }
            } else {
                $contactId = (int) $model->insert($payload);
                if ($contactId > 0) {
                    $imported++;
                } else {
                    $skipped++;
                    $errors[] = $mobile . ': ' . implode(', ', $model->errors());
                    continue;
                }
            }

            if ($tagId !== null && $tagId > 0) {
                $tagModel->attachContact($tagId, $contactId);
            }

            foreach ($values['tag_names'] as $tagName) {
                $tag = $tagModel->findOrCreateByName($tagName);
                if (is_array($tag) && ! empty($tag['id'])) {
                    $tagModel->attachContact((int) $tag['id'], $contactId);
                }
            }
        }

        fclose($handle);
        @unlink($path);

        return [
            'imported'              => $imported,
            'updated'               => $updated,
            'skipped'               => $skipped,
            'errors'                => array_slice($errors, 0, 10),
            'custom_fields_created' => array_values(array_unique($createdCustom)),
            'truncated'             => $truncated,
        ];
    }

    /**
     * @return array{headers:list<string>,rows:list<list<string>>}
     */
    protected function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open uploaded CSV.');
        }

        // Strip UTF-8 BOM if present.
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $header = fgetcsv($handle, 0, ',', '"', '\\');
        if ($header === false || $header === [null] || $header === []) {
            fclose($handle);
            throw new RuntimeException('CSV is empty.');
        }

        $headers = $this->normalizeHeaders($header);
        $rows    = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if ($this->rowIsEmpty($row)) {
                continue;
            }
            $rows[] = $this->normalizeRow($row, count($headers));
            if (count($rows) > self::MAX_ROWS) {
                // Keep counting past the limit so preview can warn; stop reading huge files.
                // Continue until one extra row, then break after loop check below.
            }
            if (count($rows) > self::MAX_ROWS + 50) {
                break;
            }
        }
        fclose($handle);

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * @return array{headers:list<string>,rows:list<list<string>>}
     */
    protected function readXlsx(string $path): array
    {
        try {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($path);
        } catch (ReaderException $e) {
            throw new RuntimeException('Unable to read XLSX file. ' . $e->getMessage());
        } catch (\Throwable $e) {
            throw new RuntimeException('Unable to read XLSX file. Make sure it is a valid Excel workbook.');
        }

        $sheet = $spreadsheet->getActiveSheet();
        $matrix = $sheet->toArray(null, true, true, false);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        if ($matrix === []) {
            throw new RuntimeException('XLSX is empty.');
        }

        $headerRow = array_shift($matrix);
        if (! is_array($headerRow) || $this->rowIsEmpty($headerRow)) {
            throw new RuntimeException('XLSX has no header row.');
        }

        $headers = $this->normalizeHeaders($headerRow);
        $rows    = [];
        foreach ($matrix as $row) {
            if (! is_array($row) || $this->rowIsEmpty($row)) {
                continue;
            }
            $rows[] = $this->normalizeRow($row, count($headers));
            if (count($rows) > self::MAX_ROWS + 50) {
                break;
            }
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * @param list<mixed> $header
     *
     * @return list<string>
     */
    protected function normalizeHeaders(array $header): array
    {
        $headers = [];
        foreach ($header as $i => $h) {
            $label = trim((string) $h);
            $headers[] = $label !== '' ? $label : ('Column_' . ($i + 1));
        }

        return $headers;
    }

    /**
     * @param list<mixed> $row
     *
     * @return list<string>
     */
    protected function normalizeRow(array $row, int $columnCount): array
    {
        $out = [];
        for ($i = 0; $i < $columnCount; $i++) {
            $cell = $row[$i] ?? '';
            if (is_float($cell) || is_int($cell)) {
                // Avoid scientific notation for phone-like numbers.
                if (is_float($cell) && floor($cell) === $cell) {
                    $out[] = sprintf('%.0f', $cell);
                } else {
                    $out[] = (string) $cell;
                }
            } else {
                $out[] = trim((string) $cell);
            }
        }

        return $out;
    }

    /**
     * @param list<string>       $headers
     * @param list<list<string>> $rows
     */
    protected function writeStagedCsv(string $path, array $headers, array $rows): bool
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            return false;
        }

        if (fputcsv($handle, $headers, ',', '"', '\\') === false) {
            fclose($handle);

            return false;
        }

        foreach ($rows as $row) {
            if (fputcsv($handle, $row, ',', '"', '\\') === false) {
                fclose($handle);

                return false;
            }
        }

        fclose($handle);

        return true;
    }

    /**
     * @param list<string>          $headers
     * @param array<string, string> $mapping
     *
     * @return array{
     *     mobile:?int,
     *     name:?int,
     *     email:?int,
     *     country:?int,
     *     notes:?int,
     *     tags:?int,
     *     custom: array<int,string>,
     *     new_custom_keys: list<string>
     * }
     */
    protected function resolveMapping(array $headers, array $mapping): array
    {
        $resolved = [
            'mobile'          => null,
            'name'            => null,
            'email'           => null,
            'country'         => null,
            'notes'           => null,
            'tags'            => null,
            'custom'          => [],
            'new_custom_keys' => [],
        ];

        foreach ($headers as $index => $header) {
            $dest = (string) ($mapping[$header] ?? $this->suggestDestination($header));
            if ($dest === '' || $dest === 'skip') {
                continue;
            }

            if (in_array($dest, ['name', 'mobile', 'email', 'country', 'notes', 'tags'], true)) {
                if ($resolved[$dest] === null) {
                    $resolved[$dest] = $index;
                }
                continue;
            }

            if (str_starts_with($dest, 'custom:')) {
                $key = $this->sanitizeCustomKey(substr($dest, 7));
                $resolved['custom'][$index] = $key;
                continue;
            }

            if (str_starts_with($dest, 'new:')) {
                $key = $this->sanitizeCustomKey(substr($dest, 4));
                $resolved['custom'][$index] = $key;
                $resolved['new_custom_keys'][] = $key;
                continue;
            }

            // Treat bare unknown destinations as new custom keys.
            $key = $this->sanitizeCustomKey($dest);
            $resolved['custom'][$index] = $key;
            $resolved['new_custom_keys'][] = $key;
        }

        return $resolved;
    }

    /**
     * @param list<string> $headers
     * @param list<mixed>  $row
     * @param array<string, mixed> $resolved
     *
     * @return array{name?:string,mobile?:string,email?:?string,country?:?string,notes?:?string,custom_fields:array<string,string>,tag_names:list<string>}
     */
    protected function rowValues(array $headers, array $row, array $resolved): array
    {
        $get = static function (?int $idx) use ($row): string {
            if ($idx === null) {
                return '';
            }

            return trim((string) ($row[$idx] ?? ''));
        };

        $custom = [];
        foreach ($resolved['custom'] as $idx => $key) {
            $val = trim((string) ($row[$idx] ?? ''));
            if ($val !== '') {
                $custom[$key] = $val;
            }
        }

        $tagNames = [];
        $tagsRaw  = $get($resolved['tags']);
        if ($tagsRaw !== '') {
            foreach (preg_split('/[,;|]/', $tagsRaw) ?: [] as $part) {
                $part = trim((string) $part);
                if ($part !== '') {
                    $tagNames[] = $part;
                }
            }
        }

        return [
            'name'          => $get($resolved['name']),
            'mobile'        => $get($resolved['mobile']),
            'email'         => ($v = $get($resolved['email'])) !== '' ? $v : null,
            'country'       => ($v = $get($resolved['country'])) !== '' ? $v : null,
            'notes'         => ($v = $get($resolved['notes'])) !== '' ? $v : null,
            'custom_fields' => $custom,
            'tag_names'     => $tagNames,
        ];
    }

    /**
     * @param list<mixed> $row
     */
    protected function rowIsEmpty(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
