<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\ContactModel;
use App\Models\ConversationModel;
use DateTimeImmutable;
use RuntimeException;

/**
 * Team Inbox chat history export (CSV) and import (CSV / XLSX, e.g. a Cheerio chat export).
 *
 * Imported messages are history only: nothing is sent, no workflow / keyword fires, consent is not implied,
 * and they are tagged (external_message_id "import:…") so the 24h customer-care window ignores them.
 */
class ChatTransferService
{
    public const MAX_BYTES     = 20 * 1024 * 1024;
    public const MAX_ROWS      = 50000;
    public const IMPORT_PREFIX = 'import:';

    /** Export column order = import format, so an export can be re-imported as-is. */
    public const EXPORT_COLUMNS = ['timestamp', 'mobile', 'name', 'direction', 'type', 'message', 'media_url', 'status'];

    public const FIELDS = [
        'mobile'    => 'Phone number',
        'name'      => 'Contact name',
        'timestamp' => 'Date & time',
        'date'      => 'Date only',
        'time'      => 'Time only',
        'direction' => 'Direction / sender',
        'type'      => 'Message type',
        'message'   => 'Message text',
        'media_url' => 'Media URL',
        'status'    => 'Delivery status',
        'skip'      => '— Ignore —',
    ];

    private const HEADER_ALIASES = [
        'mobile'    => ['mobile', 'phone', 'phonenumber', 'mobilenumber', 'mobileno', 'number', 'contactnumber', 'whatsappnumber', 'whatsapp', 'waid', 'customerphone', 'customernumber', 'customermobile', 'contactphone', 'msisdn'],
        'name'      => ['name', 'contactname', 'customername', 'fullname', 'profilename', 'username'],
        'timestamp' => ['timestamp', 'datetime', 'sentat', 'createdat', 'receivedat', 'messagetime', 'messagedatetime', 'time_stamp', 'dateandtime', 'datetimeist'],
        'date'      => ['date', 'messagedate', 'day'],
        'time'      => ['time'],
        'direction' => ['direction', 'sender', 'sentby', 'from', 'messagedirection', 'inout', 'author', 'by', 'senttype', 'origin'],
        'type'      => ['type', 'messagetype', 'msgtype', 'contenttype', 'mediatype'],
        'message'   => ['message', 'messagetext', 'text', 'body', 'content', 'msg', 'messagebody', 'chat', 'messages', 'caption'],
        'media_url' => ['mediaurl', 'media', 'attachment', 'attachmenturl', 'fileurl', 'url', 'medialink', 'link'],
        'status'    => ['status', 'messagestatus', 'deliverystatus'],
    ];

    private const INBOUND_WORDS  = ['inbound', 'incoming', 'in', 'received', 'receive', 'customer', 'user', 'contact', 'client', 'lead', 'fromcustomer', 'fromuser', 'them', 'guest', 'visitor'];
    private const OUTBOUND_WORDS = ['outbound', 'outgoing', 'out', 'sent', 'send', 'agent', 'business', 'bot', 'chatbot', 'admin', 'operator', 'system', 'campaign', 'broadcast', 'api', 'automation', 'workflow', 'template', 'team', 'me', 'owner', 'support'];

    private const DATE_FORMATS = [
        'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.uP', 'Y-m-d\TH:i:s',
        'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y, H:i:s', 'd/m/Y, H:i', 'd/m/Y h:i A', 'd/m/Y, h:i A', 'd/m/Y h:i:s A', 'd/m/Y, h:i:s A',
        'd-m-Y H:i:s', 'd-m-Y H:i', 'd-m-Y h:i A', 'd.m.Y H:i:s', 'd.m.Y H:i',
        'd/m/y H:i', 'd/m/y, H:i', 'd/m/y h:i A', 'd/m/y, h:i A',
        'Y/m/d H:i:s', 'Y/m/d H:i', 'd M Y H:i', 'd M Y, H:i', 'd M Y h:i A', 'd M Y, h:i A', 'M d, Y h:i A', 'M d, Y H:i',
        'Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'd M Y',
    ];

    // ------------------------------------------------------------------ export

    /**
     * Write matching messages to a temp CSV file.
     *
     * @param array{scope?: string, numbers?: string, tag_id?: int|string, contact_id?: int|string, from?: string, to?: string} $filters
     *
     * @return array{path: string, filename: string, rows: int}
     */
    public function exportToFile(array $filters): array
    {
        $db    = db_connect();
        $scope = $this->resolveScope($filters);
        $from  = $scope['from'];
        $to    = $scope['to'];

        $path = $this->exportDir() . DIRECTORY_SEPARATOR . 'chats_' . bin2hex(random_bytes(8)) . '.csv';
        $out  = fopen($path, 'wb');
        if ($out === false) {
            throw new RuntimeException('Unable to write export file.');
        }
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, self::EXPORT_COLUMNS, ',', '"', '');

        $rows   = 0;
        $offset = 0;
        $chunk  = 2000;
        do {
            $builder = $db->table('messages m')
                ->select('m.id, m.created_at, m.direction, m.message_type, m.content, m.media_url, m.status, c.mobile, c.name')
                ->join('contacts c', 'c.id = m.contact_id');
            $this->applyScope($builder, $scope);
            if ($from !== null) {
                $builder->where('m.created_at >=', $from . ' 00:00:00');
            }
            if ($to !== null) {
                $builder->where('m.created_at <=', $to . ' 23:59:59');
            }
            $batch = $builder->orderBy('m.contact_id', 'ASC')->orderBy('m.created_at', 'ASC')->orderBy('m.id', 'ASC')
                ->limit($chunk, $offset)->get()->getResultArray();

            foreach ($batch as $m) {
                fputcsv($out, array_map([ContactExportService::class, 'safeCell'], [
                    (string) $m['created_at'],
                    (string) $m['mobile'],
                    (string) ($m['name'] ?? ''),
                    (string) $m['direction'],
                    (string) ($m['message_type'] ?? 'text'),
                    (string) ($m['content'] ?? ''),
                    (string) ($m['media_url'] ?? ''),
                    (string) ($m['status'] ?? ''),
                ]), ',', '"', '');
                $rows++;
            }
            $offset += $chunk;
        } while (count($batch) === $chunk);
        fclose($out);

        return ['path' => $path, 'filename' => 'chats-' . $scope['label'] . '-' . date('Ymd-His') . '.csv', 'rows' => $rows];
    }

    /**
     * Validate export filters (all chats / pasted numbers / customer group / one chat + optional date range).
     *
     * @param array<string, mixed> $filters
     *
     * @return array{scope: string, numbers: list<string>, tag_id: int, contact_id: int, from: ?string, to: ?string, label: string}
     */
    public function resolveScope(array $filters): array
    {
        $scope   = (string) ($filters['scope'] ?? 'all');
        $scope   = in_array($scope, ['all', 'numbers', 'group', 'contact'], true) ? $scope : 'all';
        $numbers = $this->parseNumbers((string) ($filters['numbers'] ?? ''));
        $tagId   = (int) ($filters['tag_id'] ?? 0);
        $contact = (int) ($filters['contact_id'] ?? 0);

        if ($scope === 'numbers' && $numbers === []) {
            throw new RuntimeException('Paste at least one phone number to export.');
        }
        if ($scope === 'group' && $tagId <= 0) {
            throw new RuntimeException('Choose a customer group to export.');
        }
        if ($scope === 'contact' && $contact <= 0) {
            throw new RuntimeException('Open a chat first to export it.');
        }

        return [
            'scope'      => $scope,
            'numbers'    => $numbers,
            'tag_id'     => $tagId,
            'contact_id' => $contact,
            'from'       => $this->parseDay((string) ($filters['from'] ?? '')),
            'to'         => $this->parseDay((string) ($filters['to'] ?? '')),
            'label'      => match ($scope) {
                'numbers' => 'numbers',
                'group'   => 'group-' . $tagId,
                'contact' => 'contact-' . $contact,
                default   => 'all',
            },
        ];
    }

    /**
     * Limit a query that already joins contacts as "c" to the resolved scope (live contacts only).
     *
     * @param array{scope: string, numbers: list<string>, tag_id: int, contact_id: int} $scope
     */
    public function applyScope(\CodeIgniter\Database\BaseBuilder $builder, array $scope): void
    {
        $builder->where('c.deleted_at', null);
        if ($scope['scope'] === 'numbers') {
            $builder->whereIn('c.mobile', $scope['numbers']);
        } elseif ($scope['scope'] === 'group') {
            $builder->join('contact_tags ct', 'ct.contact_id = c.id')->where('ct.tag_id', $scope['tag_id']);
        } elseif ($scope['scope'] === 'contact') {
            $builder->where('c.id', $scope['contact_id']);
        }
    }

    public function exportDir(): string
    {
        $dir = WRITEPATH . 'uploads/exports';
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException('Unable to create export directory.');
        }

        return $dir;
    }

    /** Example file in the import format. */
    public function sampleCsv(): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, self::EXPORT_COLUMNS, ',', '"', '');
        fputcsv($out, ['2026-09-01 10:15:00', '919876543210', 'Rahul Patil', 'inbound', 'text', 'Hi, price kay ahe?', '', 'received'], ',', '"', '');
        fputcsv($out, ['2026-09-01 10:16:30', '919876543210', 'Rahul Patil', 'outbound', 'text', 'Namaskar Rahul! Price list pathavto.', '', 'read'], ',', '"', '');
        fputcsv($out, ['2026-09-01 10:17:05', '919876543210', 'Rahul Patil', 'outbound', 'document', 'Price list', 'https://example.com/price-list.pdf', 'delivered'], ',', '"', '');
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    // ------------------------------------------------------------------ import

    /**
     * Stage an upload and return what the mapping screen needs.
     *
     * @return array{token: string, filename: string, headers: list<string>, mapping: array<string, string>, fields: array<string, string>,
     *               sample_rows: list<list<string>>, row_count: int, truncated: bool, stats: array<string, mixed>}
     */
    public function preview(string $tempPath, string $originalName): array
    {
        if (! is_file($tempPath) || ! is_readable($tempPath)) {
            throw new RuntimeException('Unable to read uploaded file.');
        }
        if (filesize($tempPath) > self::MAX_BYTES) {
            throw new RuntimeException('File exceeds the 20 MB limit. Split it into smaller files.');
        }
        $ext    = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $parsed = (new ContactImportService())->readSpreadsheet($tempPath, $originalName, self::MAX_ROWS);
        if ($parsed['headers'] === [] || $parsed['rows'] === []) {
            throw new RuntimeException('The file has no chat rows.');
        }

        $dir = $this->stagingDir();
        $token = bin2hex(random_bytes(16));
        if (! copy($tempPath, $dir . DIRECTORY_SEPARATOR . $token . '.' . $ext)) {
            throw new RuntimeException('Unable to stage uploaded file.');
        }

        $mapping = $this->suggestMapping($parsed['headers']);
        $rows    = array_slice($parsed['rows'], 0, self::MAX_ROWS);

        return [
            'token'       => $token . '.' . $ext,
            'filename'    => $originalName,
            'headers'     => $parsed['headers'],
            'mapping'     => $mapping,
            'fields'      => self::FIELDS,
            'sample_rows' => array_slice($rows, 0, 5),
            'row_count'   => count($rows),
            'truncated'   => count($parsed['rows']) > self::MAX_ROWS,
            'stats'       => $this->analyse($parsed['headers'], $rows, $mapping),
        ];
    }

    /**
     * @param array<string, string> $mapping header => field
     *
     * @return array{imported: int, duplicates: int, skipped: int, contacts_created: int, contacts_matched: int, errors: list<string>}
     */
    public function import(string $token, array $mapping, bool $createContacts = true): array
    {
        $path    = $this->stagedPath($token);
        $parsed  = (new ContactImportService())->readSpreadsheet($path, $token, self::MAX_ROWS);
        $headers = $parsed['headers'];
        $rows    = array_slice($parsed['rows'], 0, self::MAX_ROWS);
        $col     = $this->resolveColumns($headers, $mapping);
        $this->assertRequired($col);

        @set_time_limit(600);
        $db        = db_connect();
        $contacts  = model(ContactModel::class);
        $convModel = model(ConversationModel::class);
        $hasChannel = $db->fieldExists('channel', 'messages');
        $now       = date('Y-m-d H:i:s');

        $result = ['imported' => 0, 'duplicates' => 0, 'skipped' => 0, 'contacts_created' => 0, 'contacts_matched' => 0, 'errors' => []];
        $cache  = [];   // mobile => ['id' => int, 'conversation_id' => int, 'keys' => array<string,true>]
        $buffer = [];
        $touched = [];

        foreach ($rows as $i => $row) {
            $rowNum = $i + 2;
            $rec    = $this->recordFromRow($row, $col);
            if (is_string($rec)) {
                $this->skip($result, 'Row ' . $rowNum . ': ' . $rec);
                continue;
            }

            if (! isset($cache[$rec['mobile']])) {
                $contact = $contacts->findByMobile($rec['mobile']);
                if ($contact === null) {
                    if (! $createContacts) {
                        $this->skip($result, 'Row ' . $rowNum . ': ' . $rec['mobile'] . ' is not a saved contact.');
                        continue;
                    }
                    $id = (int) $contacts->insert([
                        'channel' => 'whatsapp',
                        'name'    => $rec['name'] !== '' ? ContactExportService::safeCell($rec['name']) : null,
                        'mobile'  => $rec['mobile'],
                        'status'  => 'active',
                    ]);
                    if ($id <= 0) {
                        $this->skip($result, 'Row ' . $rowNum . ': could not create contact ' . $rec['mobile'] . ' — ' . implode(', ', $contacts->errors()));
                        continue;
                    }
                    $contact = ['id' => $id];
                    $result['contacts_created']++;
                } else {
                    $result['contacts_matched']++;
                }
                $contactId = (int) $contact['id'];
                $conv      = $convModel->findOrCreateForContact($contactId);
                $keys      = $db->table('messages')->select('external_message_id')
                    ->where('contact_id', $contactId)->like('external_message_id', self::IMPORT_PREFIX, 'after')
                    ->get()->getResultArray();
                $cache[$rec['mobile']] = [
                    'id'              => $contactId,
                    'conversation_id' => (int) $conv['id'],
                    'keys'            => array_fill_keys(array_column($keys, 'external_message_id'), true),
                ];
            }
            $ref = &$cache[$rec['mobile']];

            $key = self::IMPORT_PREFIX . sha1(implode('|', [$rec['mobile'], $rec['direction'], $rec['at'], $rec['message'], $rec['media_url']]));
            if (isset($ref['keys'][$key])) {
                $result['duplicates']++;
                unset($ref);
                continue;
            }
            $ref['keys'][$key] = true;

            $insert = [
                'contact_id'          => $ref['id'],
                'conversation_id'     => $ref['conversation_id'],
                'direction'           => $rec['direction'],
                'message_type'        => $rec['type'],
                'external_message_id' => $key,
                'content'             => $rec['message'] !== '' ? $rec['message'] : null,
                'media_url'           => $rec['media_url'] !== '' ? $rec['media_url'] : null,
                'payload'             => json_encode(['imported' => true, 'imported_at' => $now], JSON_UNESCAPED_UNICODE),
                'status'              => $rec['status'],
                'is_read'             => 1,
                'created_at'          => $rec['at'],
                'updated_at'          => $now,
            ];
            if ($hasChannel) {
                $insert['channel'] = 'whatsapp';
            }
            $buffer[] = $insert;
            $touched[$ref['id']] = $ref['conversation_id'];
            unset($ref);

            if (count($buffer) >= 500) {
                $result['imported'] += $this->flush($buffer);
            }
        }
        $result['imported'] += $this->flush($buffer);

        foreach ($touched as $contactId => $conversationId) {
            $this->refreshLastMessage((int) $contactId, (int) $conversationId);
        }
        @unlink($path);

        (new ActivityLogger())->log('import', 'chat', 'Imported ' . $result['imported'] . ' chat messages', [
            'imported' => $result['imported'], 'duplicates' => $result['duplicates'], 'contacts_created' => $result['contacts_created'],
        ]);

        return $result;
    }

    /**
     * Re-check a staged file after the user changed the column mapping.
     *
     * @param array<string, string> $mapping
     *
     * @return array<string, mixed>
     */
    public function analyseStaged(string $token, array $mapping): array
    {
        $path   = $this->stagedPath($token);
        $parsed = (new ContactImportService())->readSpreadsheet($path, $token, self::MAX_ROWS);

        return $this->analyse($parsed['headers'], array_slice($parsed['rows'], 0, self::MAX_ROWS), $mapping);
    }

    /**
     * @param list<string> $headers
     *
     * @return array<string, string> header => field
     */
    public function suggestMapping(array $headers): array
    {
        $mapping = [];
        $used    = [];
        foreach ($headers as $header) {
            $norm  = preg_replace('/[^a-z0-9]/', '', strtolower($header)) ?? '';
            $field = 'skip';
            foreach (self::HEADER_ALIASES as $candidate => $aliases) {
                if (! isset($used[$candidate]) && in_array($norm, $aliases, true)) {
                    $field = $candidate;
                    break;
                }
            }
            if ($field !== 'skip') {
                $used[$field] = true;
            }
            $mapping[$header] = $field;
        }

        return $mapping;
    }

    /** "inbound" / "outbound" from free-form sender / direction text, or null when unknown. */
    public function normalizeDirection(string $value, string $mobile = ''): ?string
    {
        $norm = preg_replace('/[^a-z]/', '', strtolower($value)) ?? '';
        if ($norm === '' && $value === '') {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if ($digits !== '' && $mobile !== '' && strlen($digits) >= 7 && str_ends_with($mobile, substr($digits, -10))) {
            return 'inbound';
        }
        if (in_array($norm, self::INBOUND_WORDS, true)) {
            return 'inbound';
        }
        if (in_array($norm, self::OUTBOUND_WORDS, true)) {
            return 'outbound';
        }

        return null;
    }

    /** Parse many export date styles (Indian d/m first, ISO, epoch, Excel serial) to app-time "Y-m-d H:i:s". */
    public function parseTimestamp(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $ts = null;
        if (is_numeric($value)) {
            $n = (float) $value;
            if ($n > 1e12) {
                $ts = (int) floor($n / 1000);
            } elseif ($n > 1e9) {
                $ts = (int) $n;
            } elseif ($n > 20000 && $n < 80000) {
                $ts = (int) round(($n - 25569) * 86400) - (int) date('Z');
            }
        } else {
            foreach (self::DATE_FORMATS as $format) {
                $dt = DateTimeImmutable::createFromFormat('!' . $format, $value);
                $errors = DateTimeImmutable::getLastErrors();
                if ($dt !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                    $ts = $dt->getTimestamp();
                    break;
                }
            }
            if ($ts === null) {
                $parsed = strtotime($value);
                $ts     = $parsed !== false ? $parsed : null;
            }
        }
        if ($ts === null || $ts < strtotime('2009-01-01') || $ts > time() + 3600) {
            return null;
        }

        return date('Y-m-d H:i:s', $ts);
    }

    // ------------------------------------------------------------------ internals

    /**
     * @param list<string>          $headers
     * @param list<list<string>>    $rows
     * @param array<string, string> $mapping
     *
     * @return array<string, mixed>
     */
    protected function analyse(array $headers, array $rows, array $mapping): array
    {
        $col = $this->resolveColumns($headers, $mapping);
        $missing = $this->missingRequired($col);
        if ($missing !== []) {
            return ['ready' => false, 'missing' => $missing];
        }
        $mobiles = [];
        $valid = 0;
        $errors = [];
        $min = null;
        $max = null;
        foreach ($rows as $i => $row) {
            $rec = $this->recordFromRow($row, $col);
            if (is_string($rec)) {
                if (count($errors) < 5) {
                    $errors[] = 'Row ' . ($i + 2) . ': ' . $rec;
                }
                continue;
            }
            $valid++;
            $mobiles[$rec['mobile']] = true;
            $min = $min === null || $rec['at'] < $min ? $rec['at'] : $min;
            $max = $max === null || $rec['at'] > $max ? $rec['at'] : $max;
        }

        return [
            'ready'    => true,
            'missing'  => [],
            'valid'    => $valid,
            'invalid'  => count($rows) - $valid,
            'contacts' => count($mobiles),
            'from'     => $min,
            'to'       => $max,
            'errors'   => $errors,
        ];
    }

    /**
     * @param list<string>          $headers
     * @param array<string, string> $mapping
     *
     * @return array<string, int> field => column index
     */
    protected function resolveColumns(array $headers, array $mapping): array
    {
        $col = [];
        foreach ($headers as $idx => $header) {
            $field = (string) ($mapping[$header] ?? 'skip');
            if ($field !== 'skip' && isset(self::FIELDS[$field]) && ! isset($col[$field])) {
                $col[$field] = $idx;
            }
        }

        return $col;
    }

    /**
     * @param array<string, int> $col
     *
     * @return list<string>
     */
    protected function missingRequired(array $col): array
    {
        $missing = [];
        if (! isset($col['mobile'])) {
            $missing[] = self::FIELDS['mobile'];
        }
        if (! isset($col['direction'])) {
            $missing[] = self::FIELDS['direction'];
        }
        if (! isset($col['timestamp']) && ! isset($col['date'])) {
            $missing[] = self::FIELDS['timestamp'];
        }
        if (! isset($col['message']) && ! isset($col['media_url'])) {
            $missing[] = self::FIELDS['message'];
        }

        return $missing;
    }

    /**
     * @param array<string, int> $col
     */
    protected function assertRequired(array $col): void
    {
        $missing = $this->missingRequired($col);
        if ($missing !== []) {
            throw new RuntimeException('Map these columns before importing: ' . implode(', ', $missing) . '.');
        }
    }

    /**
     * @param list<string>       $row
     * @param array<string, int> $col
     *
     * @return array{mobile: string, name: string, at: string, direction: string, type: string, message: string, media_url: string, status: string}|string
     *         Record, or the reason the row is skipped.
     */
    protected function recordFromRow(array $row, array $col): array|string
    {
        $get = static fn (string $field): string => isset($col[$field]) ? trim((string) ($row[$col[$field]] ?? '')) : '';

        $mobile = normalize_phone($get('mobile'));
        if (strlen($mobile) < 8 || strlen($mobile) > 15) {
            return 'missing or invalid phone number "' . $get('mobile') . '".';
        }
        $when = $get('timestamp');
        if ($when === '' || (isset($col['date']) && ! isset($col['timestamp']))) {
            $when = trim($get('date') . ' ' . $get('time'));
        }
        $at = $this->parseTimestamp($when);
        if ($at === null) {
            return 'unreadable or future date "' . $when . '".';
        }
        $direction = $this->normalizeDirection($get('direction'), $mobile);
        if ($direction === null) {
            return 'unknown direction "' . $get('direction') . '" (use inbound / outbound).';
        }
        $message = (string) preg_replace("/^'(?=[=@+\\-])/", '', $get('message'));
        $media   = $get('media_url');
        if ($media !== '' && preg_match('#^https?://#i', $media) !== 1) {
            $media = '';
        }
        if ($message === '' && $media === '') {
            return 'empty message.';
        }

        return [
            'mobile'    => $mobile,
            'name'      => mb_substr($get('name'), 0, 150),
            'at'        => $at,
            'direction' => $direction,
            'type'      => $this->normalizeType($get('type'), $media),
            'message'   => $message,
            'media_url' => mb_substr($media, 0, 500),
            'status'    => $this->normalizeStatus($get('status'), $direction),
        ];
    }

    protected function normalizeType(string $type, string $media): string
    {
        $type = preg_replace('/[^a-z_]/', '', strtolower($type)) ?? '';
        $known = ['text', 'image', 'video', 'audio', 'document', 'sticker', 'location', 'contacts', 'template', 'interactive', 'button', 'voice', 'file'];
        if (in_array($type, $known, true)) {
            return match ($type) {
                'voice' => 'audio',
                'file'  => 'document',
                default => $type,
            };
        }
        if ($media !== '') {
            $ext = strtolower(pathinfo((string) parse_url($media, PHP_URL_PATH), PATHINFO_EXTENSION));

            return match (true) {
                in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true) => 'image',
                in_array($ext, ['mp4', '3gp', 'mov'], true)                => 'video',
                in_array($ext, ['mp3', 'ogg', 'opus', 'aac', 'm4a', 'amr'], true) => 'audio',
                default                                                     => 'document',
            };
        }

        return 'text';
    }

    protected function normalizeStatus(string $status, string $direction): string
    {
        if ($direction === 'inbound') {
            return 'received';
        }
        $status = strtolower(trim($status));

        return in_array($status, ['sent', 'delivered', 'read', 'failed'], true) ? $status : 'sent';
    }

    /**
     * @param list<array<string, mixed>> $buffer
     */
    protected function flush(array &$buffer): int
    {
        if ($buffer === []) {
            return 0;
        }
        $count = count($buffer);
        db_connect()->table('messages')->insertBatch($buffer);
        $buffer = [];

        return $count;
    }

    /**
     * Point the inbox list at the newest message (imported or live) without touching last_reply_at (24h window).
     */
    public function refreshLastMessage(int $contactId, int $conversationId): void
    {
        $db     = db_connect();
        $latest = $db->table('messages')->select('id, created_at')->where('contact_id', $contactId)
            ->orderBy('created_at', 'DESC')->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();
        if (! is_array($latest)) {
            return;
        }
        $db->table('conversations')->where('id', $conversationId)
            ->groupStart()->where('last_message_at', null)->orWhere('last_message_at <=', $latest['created_at'])->groupEnd()
            ->update(['last_message_id' => (int) $latest['id'], 'last_message_at' => $latest['created_at']]);
        $db->table('contacts')->where('id', $contactId)
            ->groupStart()->where('last_message_at', null)->orWhere('last_message_at <', $latest['created_at'])->groupEnd()
            ->update(['last_message_at' => $latest['created_at']]);
    }

    /**
     * @param array<string, mixed> $result
     */
    protected function skip(array &$result, string $error): void
    {
        $result['skipped']++;
        if (count($result['errors']) < 20) {
            $result['errors'][] = $error;
        }
    }

    /**
     * @return list<string>
     */
    protected function parseNumbers(string $text): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', $text) ?: [] as $part) {
            $digits = normalize_phone($part);
            if (strlen($digits) >= 8) {
                $out[$digits] = true;
            }
        }

        return array_keys($out);
    }

    protected function parseDay(string $value): ?string
    {
        $value = trim($value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    protected function stagedPath(string $token): string
    {
        if (preg_match('/^[a-f0-9]{32}\.(csv|xlsx)$/', $token) !== 1) {
            throw new RuntimeException('Invalid import session. Upload the file again.');
        }
        $path = $this->stagingDir() . DIRECTORY_SEPARATOR . $token;
        if (! is_file($path)) {
            throw new RuntimeException('Import file expired. Upload the file again.');
        }

        return $path;
    }

    public function stagingDir(): string
    {
        $dir = WRITEPATH . 'uploads/chat-imports';
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException('Unable to create import staging directory.');
        }

        return $dir;
    }
}
