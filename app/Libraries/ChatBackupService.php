<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\ContactModel;
use App\Models\ConversationModel;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Team Inbox full backup (ZIP) and restore.
 *
 * A backup holds chats with their media files, contacts (tags, custom fields, consent record), customer groups,
 * conversation status / assignment and internal notes, plus a readable chats.csv.
 *
 * Restore is additive and safe to run again: nothing is sent or queued, no workflow runs, existing chats and
 * conversation status are not overwritten, consent on an existing contact is only ever made stricter, and restored
 * messages are tagged "import:…" so they never open the 24h customer-care window.
 */
class ChatBackupService
{
    public const FORMAT            = 'sateri-chat-backup';
    public const VERSION           = 1;
    public const MAX_ZIP_BYTES     = 2147483648;   // 2 GB
    public const MAX_CHUNK_BYTES   = 1572864;      // 1.5 MB per upload request (fits the default 2 MB PHP limit)
    public const MAX_MEDIA_BYTES   = 104857600;    // 100 MB per media file
    public const MAX_RESTORE_MEDIA = 5368709120;   // 5 GB of media per restore
    public const MEDIA_EXTENSIONS  = [
        'jpg', 'jpeg', 'png', 'webp', 'gif', 'mp4', '3gp', 'mov', 'mp3', 'ogg', 'opus', 'aac', 'm4a', 'amr', 'wav',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'zip', 'bin',
    ];

    private const CONTACT_FIELDS = [
        'channel', 'external_id', 'name', 'mobile', 'country', 'email', 'notes', 'status', 'last_message_at', 'assigned_to',
        'birthday', 'custom_fields', 'wa_opt_in', 'wa_opt_in_at', 'wa_opt_in_source', 'wa_opted_out_at', 'wa_suppressed_until',
        'wa_suppress_reason', 'wa_consent_requested_at', 'created_at',
    ];
    private const CONSENT_FIELDS        = ['wa_opt_in', 'wa_opt_in_at', 'wa_opt_in_source', 'wa_opted_out_at', 'wa_suppressed_until', 'wa_suppress_reason', 'wa_consent_requested_at'];
    private const CONVERSATION_FIELDS   = ['channel', 'page_id', 'status', 'assigned_to', 'unread_count', 'intervened_at', 'ctwa_referral', 'created_at'];
    private const CONVERSATION_STATUSES = ['open', 'pending', 'resolved', 'chatbot', 'intervened', 'closed'];
    private const MESSAGE_STATUSES      = ['pending', 'sent', 'delivered', 'read', 'failed', 'received'];
    private const CHANNELS              = ['whatsapp', 'instagram', 'messenger'];

    private ChatTransferService $transfer;

    /** @var array<string, mixed> */
    private array $result = [];

    /** @var array<string, string|false> backup media name => restored file name (false = unavailable) */
    private array $mediaMap = [];

    private int $mediaBytes = 0;

    public function __construct(?ChatTransferService $transfer = null)
    {
        $this->transfer = $transfer ?? new ChatTransferService();
    }

    // ------------------------------------------------------------------ backup

    /**
     * Build the backup ZIP for the given scope (same filters as the CSV export).
     *
     * @param array<string, mixed> $filters
     *
     * @return array{path: string, filename: string, counts: array<string, int>}
     */
    public function createBackup(array $filters): array
    {
        @set_time_limit(0);
        $scope   = $this->transfer->resolveScope($filters);
        $db      = db_connect();
        $work    = $this->transfer->exportDir() . DIRECTORY_SEPARATOR . 'backup_' . bin2hex(random_bytes(8));
        $zipPath = $work . '.zip';
        if (! mkdir($work, 0755, true) && ! is_dir($work)) {
            throw new RuntimeException('Unable to create backup directory.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->removeDir($work);
            throw new RuntimeException('Unable to create backup file.');
        }

        $counts   = ['contacts' => 0, 'conversations' => 0, 'messages' => 0, 'notes' => 0, 'tags' => 0, 'media' => 0, 'media_missing' => 0];
        $userIds  = [];
        $csvPath  = null;

        try {
            // Contacts (+ their groups)
            $contactIds = [];
            $tagNames   = [];
            $fh         = $this->openWork($work, 'contacts.jsonl');
            $lastId     = 0;
            do {
                $builder = $db->table('contacts c')->select('c.*')->where('c.id >', $lastId);
                $this->transfer->applyScope($builder, $scope);
                $rows = $builder->orderBy('c.id', 'ASC')->limit(1000)->get()->getResultArray();
                if ($rows === []) {
                    break;
                }
                $ids    = array_map('intval', array_column($rows, 'id'));
                $lastId = max($ids);
                $tagsOf = [];
                foreach ($db->table('contact_tags ct')->select('ct.contact_id, t.name')->join('tags t', 't.id = ct.tag_id')
                    ->whereIn('ct.contact_id', $ids)->get()->getResultArray() as $t) {
                    $tagsOf[(int) $t['contact_id']][] = (string) $t['name'];
                    $tagNames[(string) $t['name']]    = true;
                }
                foreach ($rows as $c) {
                    $line = ['ref' => (int) $c['id']];
                    foreach (self::CONTACT_FIELDS as $field) {
                        if (array_key_exists($field, $c)) {
                            $line[$field] = $c[$field];
                        }
                    }
                    if (isset($line['custom_fields']) && is_string($line['custom_fields'])) {
                        $decoded               = json_decode($line['custom_fields'], true);
                        $line['custom_fields'] = is_array($decoded) ? $decoded : null;
                    }
                    $line['tags'] = $tagsOf[(int) $c['id']] ?? [];
                    if (! empty($c['assigned_to'])) {
                        $userIds[(int) $c['assigned_to']] = true;
                    }
                    $this->writeLine($fh, $line);
                    $contactIds[] = (int) $c['id'];
                    $counts['contacts']++;
                }
            } while (count($rows) === 1000);
            fclose($fh);

            // Customer groups
            $tagQuery = $db->table('tags')->select('name, color')->orderBy('name', 'ASC');
            if ($scope['scope'] !== 'all') {
                $tagQuery->whereIn('name', $tagNames === [] ? [''] : array_map('strval', array_keys($tagNames)));
            }
            $tags           = $tagQuery->get()->getResultArray();
            $counts['tags'] = count($tags);
            file_put_contents($work . DIRECTORY_SEPARATOR . 'tags.json', json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            // Conversations + internal notes
            $convFh = $this->openWork($work, 'conversations.jsonl');
            $noteFh = $this->openWork($work, 'notes.jsonl');
            foreach (array_chunk($contactIds, 1000) as $ids) {
                foreach ($db->table('conversations')->whereIn('contact_id', $ids)->orderBy('id', 'ASC')->get()->getResultArray() as $conv) {
                    $line = ['contact_ref' => (int) $conv['contact_id']];
                    foreach (self::CONVERSATION_FIELDS as $field) {
                        if (array_key_exists($field, $conv)) {
                            $line[$field] = $conv[$field];
                        }
                    }
                    if (! empty($conv['assigned_to'])) {
                        $userIds[(int) $conv['assigned_to']] = true;
                    }
                    $this->writeLine($convFh, $line);
                    $counts['conversations']++;
                }
                foreach ($db->table('internal_notes')->whereIn('contact_id', $ids)->orderBy('id', 'ASC')->get()->getResultArray() as $note) {
                    $userIds[(int) $note['user_id']] = true;
                    $this->writeLine($noteFh, [
                        'contact_ref' => (int) $note['contact_id'],
                        'user_id'     => (int) $note['user_id'],
                        'note'        => (string) $note['note'],
                        'is_internal' => (int) ($note['is_internal'] ?? 1),
                        'created_at'  => $note['created_at'],
                    ]);
                    $counts['notes']++;
                }
            }
            fclose($convFh);
            fclose($noteFh);

            // Messages (grouped by contact, oldest first) + local media files
            $msgFh = $this->openWork($work, 'messages.jsonl');
            $added = [];
            foreach (array_chunk($contactIds, 200) as $ids) {
                $offset = 0;
                do {
                    $builder = $db->table('messages')->whereIn('contact_id', $ids);
                    if ($scope['from'] !== null) {
                        $builder->where('created_at >=', $scope['from'] . ' 00:00:00');
                    }
                    if ($scope['to'] !== null) {
                        $builder->where('created_at <=', $scope['to'] . ' 23:59:59');
                    }
                    $batch = $builder->orderBy('contact_id', 'ASC')->orderBy('created_at', 'ASC')->orderBy('id', 'ASC')
                        ->limit(2000, $offset)->get()->getResultArray();
                    foreach ($batch as $m) {
                        $mediaFile = null;
                        $name      = LocalMediaUrl::filenameFromUrl((string) ($m['media_url'] ?? ''));
                        if ($name !== '') {
                            if (! isset($added[$name])) {
                                $file = $this->localMediaPath($name);
                                if ($file !== null) {
                                    $zip->addFile($file, 'media/' . $name);
                                    $zip->setCompressionName('media/' . $name, ZipArchive::CM_STORE);
                                    $counts['media']++;
                                }
                                $added[$name] = $file !== null;
                            }
                            if ($added[$name]) {
                                $mediaFile = $name;
                            } else {
                                $counts['media_missing']++;
                            }
                        }
                        $payload = $m['payload'] ?? null;
                        if (is_string($payload) && $payload !== '') {
                            $decoded = json_decode($payload, true);
                            $payload = is_array($decoded) ? $decoded : $payload;
                        }
                        $this->writeLine($msgFh, [
                            'contact_ref'   => (int) $m['contact_id'],
                            'direction'     => $m['direction'],
                            'message_type'  => $m['message_type'] ?? 'text',
                            'wamid'         => (string) (($m['wamid'] ?? '') ?: ($m['wa_message_id'] ?? '')),
                            'content'       => $m['content'],
                            'media_url'     => $m['media_url'],
                            'media_file'    => $mediaFile,
                            'payload'       => $payload,
                            'status'        => $m['status'],
                            'error_code'    => $m['error_code'] ?? null,
                            'error_message' => $m['error_message'] ?? null,
                            'is_read'       => (int) ($m['is_read'] ?? 1),
                            'channel'       => $m['channel'] ?? null,
                            'created_at'    => $m['created_at'],
                        ]);
                        $counts['messages']++;
                    }
                    $offset += 2000;
                } while (count($batch) === 2000);
            }
            fclose($msgFh);

            // Agents referenced by id → email, so a restore on another server can re-link assignments
            $users = [];
            $ids   = array_values(array_filter(array_keys($userIds)));
            if ($ids !== []) {
                foreach ($db->table('users')->select('id, email')->whereIn('id', $ids)->get()->getResultArray() as $u) {
                    $users[(string) $u['id']] = strtolower((string) $u['email']);
                }
            }

            $csvPath = $this->transfer->exportToFile($filters)['path'];

            foreach (['contacts.jsonl', 'tags.json', 'conversations.jsonl', 'notes.jsonl', 'messages.jsonl'] as $file) {
                $zip->addFile($work . DIRECTORY_SEPARATOR . $file, $file);
            }
            $zip->addFile($csvPath, 'chats.csv');
            $zip->addFromString('manifest.json', (string) json_encode([
                'format'     => self::FORMAT,
                'version'    => self::VERSION,
                'created_at' => date('Y-m-d H:i:s'),
                'timezone'   => date_default_timezone_get(),
                'source'     => (string) (parse_url(site_url(), PHP_URL_HOST) ?: ''),
                'scope'      => $scope['label'],
                'from'       => $scope['from'],
                'to'         => $scope['to'],
                'counts'     => $counts,
                'users'      => $users,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            if (! $zip->close()) {
                throw new RuntimeException('Unable to finish the backup file.');
            }
        } catch (Throwable $e) {
            @$zip->close();
            @unlink($zipPath);
            throw $e instanceof RuntimeException ? $e : new RuntimeException('Backup failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->removeDir($work);
            if ($csvPath !== null && is_file($csvPath)) {
                @unlink($csvPath);
            }
        }

        return [
            'path'     => $zipPath,
            'filename' => 'inbox-backup-' . $scope['label'] . '-' . date('Ymd-His') . '.zip',
            'counts'   => $counts,
        ];
    }

    // ------------------------------------------------------------------ upload (chunked)

    /**
     * Receive one slice of a backup ZIP. Large files are sent in slices so the PHP upload limit does not matter.
     *
     * @return array{upload_id: string, received: int, done: bool, token?: string, summary?: array<string, mixed>}
     */
    public function receiveChunk(string $uploadId, int $offset, string $chunkPath, bool $last): array
    {
        $dir = $this->transfer->stagingDir();
        if ($offset === 0) {
            $this->purgeStale($dir);
            $uploadId = bin2hex(random_bytes(16));
        } elseif (preg_match('/^[a-f0-9]{32}$/', $uploadId) !== 1) {
            throw new RuntimeException('Upload session expired. Choose the backup file again.');
        }
        $part = $dir . DIRECTORY_SEPARATOR . $uploadId . '.zip.part';
        $have = $offset === 0 ? 0 : (is_file($part) ? (int) filesize($part) : -1);
        if ($have !== $offset) {
            throw new RuntimeException('Upload was interrupted. Choose the backup file again.');
        }
        $size = is_file($chunkPath) ? (int) filesize($chunkPath) : 0;
        if ($size <= 0 || $size > self::MAX_CHUNK_BYTES) {
            throw new RuntimeException('Invalid upload slice.');
        }
        if ($have + $size > self::MAX_ZIP_BYTES) {
            @unlink($part);
            throw new RuntimeException('Backup file is larger than 2 GB.');
        }

        $in  = fopen($chunkPath, 'rb');
        $out = fopen($part, $offset === 0 ? 'wb' : 'ab');
        if ($in === false || $out === false) {
            throw new RuntimeException('Unable to store the uploaded backup.');
        }
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        clearstatcache(true, $part);

        $received = (int) filesize($part);
        if (! $last) {
            return ['upload_id' => $uploadId, 'received' => $received, 'done' => false];
        }

        $token = $uploadId . '.zip';
        $path  = $dir . DIRECTORY_SEPARATOR . $token;
        if (! rename($part, $path)) {
            throw new RuntimeException('Unable to store the uploaded backup.');
        }
        try {
            $summary = $this->inspect($token);
        } catch (Throwable $e) {
            @unlink($path);
            throw $e;
        }

        return ['upload_id' => $uploadId, 'received' => $received, 'done' => true, 'token' => $token, 'summary' => $summary];
    }

    /**
     * Read the manifest of a staged backup for the confirmation screen.
     *
     * @return array{created_at: string, source: string, scope: string, timezone: string, counts: array<string, int>, size: int}
     */
    public function inspect(string $token): array
    {
        $path     = $this->stagedPath($token);
        $zip      = $this->openZip($path);
        $manifest = $this->readManifest($zip);
        $zip->close();

        return [
            'created_at' => (string) ($manifest['created_at'] ?? ''),
            'source'     => (string) ($manifest['source'] ?? ''),
            'scope'      => (string) ($manifest['scope'] ?? 'all'),
            'timezone'   => (string) ($manifest['timezone'] ?? ''),
            'counts'     => array_map('intval', (array) ($manifest['counts'] ?? [])),
            'size'       => (int) filesize($path),
        ];
    }

    // ------------------------------------------------------------------ restore

    /**
     * Restore a staged backup into this workspace.
     *
     * @return array<string, mixed>
     */
    public function restore(string $token, int $actingUserId = 0): array
    {
        @set_time_limit(0);
        $path     = $this->stagedPath($token);
        $zip      = $this->openZip($path);
        $manifest = $this->readManifest($zip);

        $this->mediaMap   = [];
        $this->mediaBytes = 0;
        $this->result     = [
            'contacts_created' => 0, 'contacts_matched' => 0, 'tags_created' => 0, 'conversations_restored' => 0,
            'messages_restored' => 0, 'messages_duplicate' => 0, 'messages_skipped' => 0, 'media_restored' => 0,
            'media_missing' => 0, 'notes_restored' => 0, 'errors' => [],
        ];

        try {
            $users    = $this->mapUsers((array) ($manifest['users'] ?? []));
            $tags     = $this->restoreTags($zip);
            $contacts = $this->restoreContacts($zip, $users, $tags);
            $this->restoreConversations($zip, $contacts, $users);
            $this->restoreMessages($zip, $contacts);
            $this->restoreNotes($zip, $contacts, $users, $actingUserId);
        } finally {
            $zip->close();
        }
        @unlink($path);

        (new ActivityLogger())->log('import', 'chat', 'Restored inbox backup: ' . $this->result['messages_restored'] . ' messages', [
            'source'   => (string) ($manifest['source'] ?? ''),
            'messages' => $this->result['messages_restored'],
            'contacts' => $this->result['contacts_created'],
            'media'    => $this->result['media_restored'],
        ]);

        return $this->result;
    }

    /**
     * @param array<string, string> $backupUsers backup user id => email
     *
     * @return array<int, int> backup user id => local user id
     */
    protected function mapUsers(array $backupUsers): array
    {
        $emails = array_values(array_unique(array_filter(array_map(static fn ($e) => strtolower(trim((string) $e)), $backupUsers))));
        if ($emails === []) {
            return [];
        }
        $local = [];
        foreach (db_connect()->table('users')->select('id, email')->whereIn('email', $emails)->get()->getResultArray() as $u) {
            $local[strtolower((string) $u['email'])] = (int) $u['id'];
        }
        $map = [];
        foreach ($backupUsers as $id => $email) {
            $email = strtolower(trim((string) $email));
            if (isset($local[$email])) {
                $map[(int) $id] = $local[$email];
            }
        }

        return $map;
    }

    /**
     * @return array<string, int> lower-case tag name => local tag id
     */
    protected function restoreTags(ZipArchive $zip): array
    {
        $db  = db_connect();
        $map = [];
        foreach ($db->table('tags')->select('id, name')->get()->getResultArray() as $t) {
            $map[mb_strtolower((string) $t['name'])] = (int) $t['id'];
        }
        $list = json_decode((string) $zip->getFromName('tags.json'), true);
        foreach (is_array($list) ? $list : [] as $tag) {
            if (is_array($tag)) {
                $this->tagId($map, (string) ($tag['name'] ?? ''), (string) ($tag['color'] ?? ''));
            }
        }

        return $map;
    }

    /**
     * @param array<string, int> $map
     */
    protected function tagId(array &$map, string $name, string $color = ''): int
    {
        $name = mb_substr(trim($name), 0, 100);
        if ($name === '') {
            return 0;
        }
        $key = mb_strtolower($name);
        if (! isset($map[$key])) {
            $now = date('Y-m-d H:i:s');
            db_connect()->table('tags')->insert([
                'name'       => $name,
                'color'      => preg_match('/^#[0-9a-fA-F]{3,8}$/', $color) === 1 ? $color : '#6B7280',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $map[$key] = (int) db_connect()->insertID();
            $this->result['tags_created']++;
        }

        return $map[$key];
    }

    /**
     * @param array<int, int>    $users
     * @param array<string, int> $tags
     *
     * @return array<int, array{id: int, new: bool}> backup contact ref => local contact
     */
    protected function restoreContacts(ZipArchive $zip, array $users, array &$tags): array
    {
        $db    = db_connect();
        $model = model(ContactModel::class);
        $map   = [];

        $this->eachLine($zip, 'contacts.jsonl', function (array $row) use ($db, $model, $users, &$tags, &$map): void {
            $ref     = (int) ($row['ref'] ?? 0);
            $channel = in_array($row['channel'] ?? 'whatsapp', self::CHANNELS, true) ? (string) ($row['channel'] ?? 'whatsapp') : 'whatsapp';
            $mobile  = normalize_phone((string) ($row['mobile'] ?? ''));
            $extId   = mb_substr(trim((string) ($row['external_id'] ?? '')), 0, 191);
            if ($ref <= 0) {
                return;
            }
            if ($channel === 'whatsapp' && (strlen($mobile) < 8 || strlen($mobile) > 15)) {
                $this->error('Contact "' . ($row['name'] ?? '') . '" skipped: invalid phone number.');

                return;
            }
            if ($channel !== 'whatsapp' && $extId === '') {
                $this->error('Contact "' . ($row['name'] ?? '') . '" skipped: missing ' . $channel . ' id.');

                return;
            }

            $existing = $channel === 'whatsapp'
                ? $model->findByMobile($mobile, true)
                : $model->withDeleted()->where('channel', $channel)->where('external_id', $extId)->first();
            $assigned = isset($users[(int) ($row['assigned_to'] ?? 0)]) ? $users[(int) $row['assigned_to']] : null;

            if (is_array($existing)) {
                $id = (int) $existing['id'];
                if (! empty($existing['deleted_at'])) {
                    $db->table('contacts')->where('id', $id)->update(['deleted_at' => null]);
                }
                $update = $this->contactFillIns($existing, $row, $assigned) + $this->stricterConsent($existing, $row);
                if ($update !== [] && ! $model->update($id, $update)) {
                    $this->error('Contact ' . ($mobile ?: $extId) . ': ' . implode(', ', $model->errors()));
                }
                $map[$ref] = ['id' => $id, 'new' => false];
                $this->result['contacts_matched']++;
            } else {
                $email = trim((string) ($row['email'] ?? ''));
                $data  = [
                    'channel'         => $channel,
                    'external_id'     => $extId !== '' ? $extId : null,
                    'name'            => mb_substr(trim((string) ($row['name'] ?? '')), 0, 150) ?: null,
                    'mobile'          => $mobile !== '' ? $mobile : null,
                    'country'         => mb_substr(trim((string) ($row['country'] ?? '')), 0, 100) ?: null,
                    'email'           => filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null,
                    'notes'           => ($row['notes'] ?? '') !== '' ? (string) $row['notes'] : null,
                    'status'          => in_array($row['status'] ?? '', ['active', 'inactive', 'blocked'], true) ? $row['status'] : 'active',
                    'assigned_to'     => $assigned,
                    'birthday'        => $this->validDate((string) ($row['birthday'] ?? '')),
                    'custom_fields'   => is_array($row['custom_fields'] ?? null) ? $row['custom_fields'] : null,
                    'last_message_at' => $this->validDateTime((string) ($row['last_message_at'] ?? '')),
                ] + $this->consentFrom($row);
                $id = (int) $model->insert($data);
                if ($id <= 0) {
                    $this->error('Contact ' . ($mobile ?: $extId) . ' could not be created: ' . implode(', ', $model->errors()));

                    return;
                }
                $created = $this->validDateTime((string) ($row['created_at'] ?? ''));
                if ($created !== null) {
                    $db->table('contacts')->where('id', $id)->update(['created_at' => $created]);
                }
                $map[$ref] = ['id' => $id, 'new' => true];
                $this->result['contacts_created']++;
            }

            $tagIds = [];
            foreach ((array) ($row['tags'] ?? []) as $name) {
                if (($tagId = $this->tagId($tags, (string) $name)) > 0) {
                    $tagIds[$tagId] = true;
                }
            }
            if ($tagIds !== []) {
                $have = array_map('intval', array_column($db->table('contact_tags')->select('tag_id')->where('contact_id', $map[$ref]['id'])->get()->getResultArray(), 'tag_id'));
                foreach (array_diff(array_keys($tagIds), $have) as $tagId) {
                    $db->table('contact_tags')->insert(['contact_id' => $map[$ref]['id'], 'tag_id' => $tagId]);
                }
            }
        });

        return $map;
    }

    /**
     * Only fill fields that are empty on the existing contact.
     *
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    protected function contactFillIns(array $existing, array $row, ?int $assigned): array
    {
        $update = [];
        foreach (['name' => 150, 'country' => 100, 'notes' => 0] as $field => $max) {
            $value = trim((string) ($row[$field] ?? ''));
            if (trim((string) ($existing[$field] ?? '')) === '' && $value !== '') {
                $update[$field] = $max > 0 ? mb_substr($value, 0, $max) : $value;
            }
        }
        $email = trim((string) ($row['email'] ?? ''));
        if (trim((string) ($existing['email'] ?? '')) === '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $update['email'] = $email;
        }
        $birthday = $this->validDate((string) ($row['birthday'] ?? ''));
        if (empty($existing['birthday']) && $birthday !== null) {
            $update['birthday'] = $birthday;
        }
        if (empty($existing['assigned_to']) && $assigned !== null) {
            $update['assigned_to'] = $assigned;
        }
        if (is_array($row['custom_fields'] ?? null) && $row['custom_fields'] !== []) {
            $current = is_array($existing['custom_fields'] ?? null) ? $existing['custom_fields'] : [];
            $merged  = $current + $row['custom_fields'];
            if ($merged !== $current) {
                $update['custom_fields'] = $merged;
            }
        }
        if (($row['status'] ?? '') === 'blocked' && ($existing['status'] ?? '') !== 'blocked') {
            $update['status'] = 'blocked';
        }

        return $update;
    }

    /**
     * Existing contact: copy the backup consent record only when this contact has none yet; otherwise only apply
     * a newer opt-out or a longer suppression. Never grants opt-in over a local decision.
     *
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    protected function stricterConsent(array $existing, array $row): array
    {
        $hasHistory = ! empty($existing['wa_opt_in']) || ! empty($existing['wa_opt_in_at'])
            || ! empty($existing['wa_opted_out_at']) || ! empty($existing['wa_consent_requested_at']);
        if (! $hasHistory) {
            return array_filter($this->consentFrom($row), static fn ($v) => $v !== null);
        }

        $update = [];
        $out    = $this->validDateTime((string) ($row['wa_opted_out_at'] ?? ''));
        $optIn  = (string) ($existing['wa_opt_in_at'] ?? '');
        if ($out !== null && $out > (string) ($existing['wa_opted_out_at'] ?? '') && ($optIn === '' || $optIn < $out)) {
            $update['wa_opt_in']       = 0;
            $update['wa_opted_out_at'] = $out;
        }
        $until = $this->validDateTime((string) ($row['wa_suppressed_until'] ?? ''));
        if ($until !== null && $until > date('Y-m-d H:i:s') && $until > (string) ($existing['wa_suppressed_until'] ?? '')) {
            $update['wa_suppressed_until'] = $until;
            $update['wa_suppress_reason']  = mb_substr((string) ($row['wa_suppress_reason'] ?? ''), 0, 191) ?: null;
        }

        return $update;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    protected function consentFrom(array $row): array
    {
        $out = [];
        foreach (self::CONSENT_FIELDS as $field) {
            if (! array_key_exists($field, $row)) {
                continue;
            }
            $out[$field] = match ($field) {
                'wa_opt_in'                        => (int) (bool) $row[$field],
                'wa_opt_in_source', 'wa_suppress_reason' => ($row[$field] ?? '') !== '' ? mb_substr((string) $row[$field], 0, 191) : null,
                default                            => $this->validDateTime((string) ($row[$field] ?? '')),
            };
        }
        if (! empty($out['wa_opted_out_at']) && (empty($out['wa_opt_in_at']) || $out['wa_opt_in_at'] < $out['wa_opted_out_at'])) {
            $out['wa_opt_in'] = 0;
        }

        return $out;
    }

    /**
     * Create conversations that do not exist yet (with their status / assignment). Existing ones are left as they are.
     *
     * @param array<int, array{id: int, new: bool}> $contacts
     * @param array<int, int>                      $users
     */
    protected function restoreConversations(ZipArchive $zip, array &$contacts, array $users): void
    {
        $db         = db_connect();
        $convModel  = model(ConversationModel::class);
        $hasChannel = $db->fieldExists('channel', 'conversations');
        $hasPage    = $db->fieldExists('page_id', 'conversations');

        $this->eachLine($zip, 'conversations.jsonl', function (array $row) use ($db, $convModel, $hasChannel, $hasPage, &$contacts, $users): void {
            $ref = (int) ($row['contact_ref'] ?? 0);
            if (! isset($contacts[$ref])) {
                return;
            }
            $contactId = $contacts[$ref]['id'];
            $channel   = in_array($row['channel'] ?? 'whatsapp', self::CHANNELS, true) ? (string) ($row['channel'] ?? 'whatsapp') : 'whatsapp';
            $exists    = $db->table('conversations')->select('id')->where('contact_id', $contactId);
            if ($hasChannel) {
                $exists->where('channel', $channel);
            }
            $found = $exists->get()->getRowArray();
            if (is_array($found)) {
                $contacts[$ref]['conversation_id'] = (int) $found['id'];

                return;
            }

            $data = [
                'contact_id'    => $contactId,
                'status'        => in_array($row['status'] ?? '', self::CONVERSATION_STATUSES, true) ? $row['status'] : 'open',
                'assigned_to'   => $users[(int) ($row['assigned_to'] ?? 0)] ?? null,
                'unread_count'  => max(0, (int) ($row['unread_count'] ?? 0)),
                'intervened_at' => $this->validDateTime((string) ($row['intervened_at'] ?? '')),
                'ctwa_referral' => is_string($row['ctwa_referral'] ?? null) ? $row['ctwa_referral'] : null,
            ];
            if ($hasChannel) {
                $data['channel'] = $channel;
            }
            if ($hasPage && ($row['page_id'] ?? '') !== '') {
                $data['page_id'] = (string) $row['page_id'];
            }
            try {
                $id = (int) $convModel->insert($data);
            } catch (Throwable $e) {
                $id = 0;
            }
            if ($id <= 0) {
                $id = (int) ($convModel->findOrCreateForContact($contactId, $channel)['id'] ?? 0);
            } else {
                $created = $this->validDateTime((string) ($row['created_at'] ?? ''));
                if ($created !== null) {
                    $db->table('conversations')->where('id', $id)->update(['created_at' => $created]);
                }
                $this->result['conversations_restored']++;
            }
            $contacts[$ref]['conversation_id'] = $id;
        });
    }

    /**
     * @param array<int, array{id: int, new: bool, conversation_id?: int}> $contacts
     */
    protected function restoreMessages(ZipArchive $zip, array &$contacts): void
    {
        $db         = db_connect();
        $convModel  = model(ConversationModel::class);
        $hasChannel = $db->fieldExists('channel', 'messages');
        $now        = date('Y-m-d H:i:s');
        $buffer     = [];
        $touched    = [];
        $current    = 0;
        $seen       = [];

        $flush = function () use (&$buffer, $db): void {
            if ($buffer !== []) {
                $db->table('messages')->insertBatch($buffer);
                $this->result['messages_restored'] += count($buffer);
                $buffer = [];
            }
        };

        $this->eachLine($zip, 'messages.jsonl', function (array $row) use (
            $zip, $db, $convModel, $hasChannel, $now, &$contacts, &$buffer, &$touched, &$current, &$seen, $flush
        ): void {
            $ref = (int) ($row['contact_ref'] ?? 0);
            if (! isset($contacts[$ref])) {
                $this->result['messages_skipped']++;

                return;
            }
            $contactId = $contacts[$ref]['id'];
            if ($contactId !== $current) {
                $flush();
                $current = $contactId;
                $seen    = $this->existingSignatures($contactId);
                if (empty($contacts[$ref]['conversation_id'])) {
                    $contacts[$ref]['conversation_id'] = (int) ($convModel->findOrCreateForContact($contactId)['id'] ?? 0);
                }
            }

            $direction = (string) ($row['direction'] ?? '');
            $at        = $this->transfer->parseTimestamp((string) ($row['created_at'] ?? ''));
            if (! in_array($direction, ['inbound', 'outbound'], true) || $at === null) {
                $this->result['messages_skipped']++;

                return;
            }
            $type    = preg_match('/^[a-z_]{1,30}$/', (string) ($row['message_type'] ?? '')) === 1 ? (string) $row['message_type'] : 'text';
            $content = isset($row['content']) && $row['content'] !== '' ? (string) $row['content'] : null;
            $wamid   = mb_substr(trim((string) ($row['wamid'] ?? '')), 0, 191);
            $sig     = $this->signature($direction, $type, $at, $content);
            if (($wamid !== '' && isset($seen['w:' . $wamid])) || isset($seen[$sig])) {
                $this->result['messages_duplicate']++;

                return;
            }
            $seen[$sig] = true;
            if ($wamid !== '') {
                $seen['w:' . $wamid] = true;
            }

            $mediaUrl = trim((string) ($row['media_url'] ?? ''));
            if (($row['media_file'] ?? '') !== '' && ($restored = $this->restoreMedia($zip, (string) $row['media_file'])) !== null) {
                $mediaUrl = site_url('media/serve/' . $restored);
            } elseif ($mediaUrl !== '' && LocalMediaUrl::filenameFromUrl($mediaUrl) !== '') {
                $this->result['media_missing']++;
            }
            if ($mediaUrl !== '' && preg_match('#^https?://#i', $mediaUrl) !== 1) {
                $mediaUrl = '';
            }

            $payload = $row['payload'] ?? null;
            $payload = is_array($payload) ? $payload : (is_string($payload) && $payload !== '' ? ['original_payload' => $payload] : []);
            $payload = array_merge($payload, ['imported' => true, 'restored_from_backup' => true, 'imported_at' => $now]);
            if ($wamid !== '') {
                $payload['original_wamid'] = $wamid;
            }
            $status = (string) ($row['status'] ?? '');

            $insert = [
                'contact_id'          => $contactId,
                'conversation_id'     => $contacts[$ref]['conversation_id'] ?: null,
                'direction'           => $direction,
                'message_type'        => $type,
                'external_message_id' => ChatTransferService::IMPORT_PREFIX . sha1('backup|' . $contactId . '|' . $sig . '|' . $wamid),
                'content'             => $content,
                'media_url'           => $mediaUrl !== '' ? mb_substr($mediaUrl, 0, 500) : null,
                'payload'             => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                'status'              => in_array($status, self::MESSAGE_STATUSES, true) ? $status : ($direction === 'inbound' ? 'received' : 'sent'),
                'error_code'          => ($row['error_code'] ?? '') !== '' ? mb_substr((string) $row['error_code'], 0, 50) : null,
                'error_message'       => ($row['error_message'] ?? '') !== '' ? (string) $row['error_message'] : null,
                'is_read'             => (int) (bool) ($row['is_read'] ?? 1),
                'created_at'          => $at,
                'updated_at'          => $now,
            ];
            if ($hasChannel) {
                $insert['channel'] = in_array($row['channel'] ?? '', self::CHANNELS, true) ? $row['channel'] : 'whatsapp';
            }
            $buffer[]            = $insert;
            $touched[$contactId] = (int) $contacts[$ref]['conversation_id'];
            if (count($buffer) >= 500) {
                $flush();
            }
        });
        $flush();

        foreach ($touched as $contactId => $conversationId) {
            if ($conversationId > 0) {
                $this->transfer->refreshLastMessage((int) $contactId, $conversationId);
            }
        }
    }

    /**
     * @param array<int, array{id: int, new: bool}> $contacts
     * @param array<int, int>                      $users
     */
    protected function restoreNotes(ZipArchive $zip, array $contacts, array $users, int $actingUserId): void
    {
        $db       = db_connect();
        $existing = [];

        $this->eachLine($zip, 'notes.jsonl', function (array $row) use ($db, $contacts, $users, $actingUserId, &$existing): void {
            $ref  = (int) ($row['contact_ref'] ?? 0);
            $note = trim((string) ($row['note'] ?? ''));
            if (! isset($contacts[$ref]) || $note === '') {
                return;
            }
            $userId = $users[(int) ($row['user_id'] ?? 0)] ?? $actingUserId;
            if ($userId <= 0) {
                $this->error('An internal note was skipped: its author does not exist here.');

                return;
            }
            $contactId = $contacts[$ref]['id'];
            $created   = $this->validDateTime((string) ($row['created_at'] ?? '')) ?? date('Y-m-d H:i:s');
            if (! isset($existing[$contactId])) {
                $existing[$contactId] = [];
                foreach ($db->table('internal_notes')->select('note, created_at')->where('contact_id', $contactId)->get()->getResultArray() as $n) {
                    $existing[$contactId][sha1(trim((string) $n['note']) . '|' . $n['created_at'])] = true;
                }
            }
            $key = sha1($note . '|' . $created);
            if (isset($existing[$contactId][$key])) {
                return;
            }
            $existing[$contactId][$key] = true;
            $db->table('internal_notes')->insert([
                'contact_id'  => $contactId,
                'user_id'     => $userId,
                'note'        => $note,
                'is_internal' => (int) ($row['is_internal'] ?? 1),
                'created_at'  => $created,
                'updated_at'  => $created,
            ]);
            $this->result['notes_restored']++;
        });
    }

    /**
     * Copy one media file out of the backup into uploads/media (never overwriting a different file).
     */
    protected function restoreMedia(ZipArchive $zip, string $name): ?string
    {
        if (array_key_exists($name, $this->mediaMap)) {
            return $this->mediaMap[$name] === false ? null : $this->mediaMap[$name];
        }
        $this->mediaMap[$name] = false;
        $entry = 'media/' . $name;
        $stat  = $this->safeMediaName($name) ? $zip->statName($entry) : false;
        if ($stat === false) {
            $this->result['media_missing']++;

            return null;
        }
        $size = (int) $stat['size'];
        if ($size > self::MAX_MEDIA_BYTES || $this->mediaBytes + $size > self::MAX_RESTORE_MEDIA) {
            $this->error('Media file ' . $name . ' skipped: too large.');
            $this->result['media_missing']++;

            return null;
        }
        $dir = WRITEPATH . 'uploads/media/';
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException('Unable to create media directory.');
        }
        $free = @disk_free_space($dir);
        if ($free !== false && $free < $size + 52428800) {
            throw new RuntimeException('Not enough disk space to restore media files.');
        }

        $final = $name;
        if (is_file($dir . $final)) {
            if (filesize($dir . $final) === $size && hash_file('crc32b', $dir . $final) === sprintf('%08x', ((int) $stat['crc']) & 0xFFFFFFFF)) {
                $this->mediaMap[$name] = $final;

                return $final;
            }
            $final = 'rs_' . bin2hex(random_bytes(8)) . '.' . strtolower(pathinfo($name, PATHINFO_EXTENSION));
        }

        $in  = $zip->getStream($entry);
        $tmp = $dir . $final . '.part';
        $out = fopen($tmp, 'wb');
        if ($in === false || $out === false) {
            $this->result['media_missing']++;

            return null;
        }
        $copied = stream_copy_to_stream($in, $out, self::MAX_MEDIA_BYTES + 1);
        fclose($in);
        fclose($out);
        if ($copied !== $size || ! rename($tmp, $dir . $final)) {
            @unlink($tmp);
            $this->error('Media file ' . $name . ' is damaged in the backup.');
            $this->result['media_missing']++;

            return null;
        }
        $this->mediaBytes += $size;
        $this->result['media_restored']++;
        $this->mediaMap[$name] = $final;

        return $final;
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @return array<string, true> signatures of messages already stored for this contact
     */
    protected function existingSignatures(int $contactId): array
    {
        $seen = [];
        $rows = db_connect()->table('messages')->select('direction, message_type, created_at, content, wamid, wa_message_id')
            ->where('contact_id', $contactId)->get()->getResultArray();
        foreach ($rows as $m) {
            $seen[$this->signature((string) $m['direction'], (string) ($m['message_type'] ?? 'text'), (string) $m['created_at'], $m['content'])] = true;
            foreach (['wamid', 'wa_message_id'] as $field) {
                if (! empty($m[$field])) {
                    $seen['w:' . $m[$field]] = true;
                }
            }
        }

        return $seen;
    }

    protected function signature(string $direction, string $type, string $at, mixed $content): string
    {
        return sha1($direction . '|' . $type . '|' . $at . '|' . trim((string) $content));
    }

    /**
     * @param callable(array<string, mixed>): void $fn
     */
    protected function eachLine(ZipArchive $zip, string $name, callable $fn): void
    {
        $stream = $zip->getStream($name);
        if ($stream === false) {
            return;
        }
        $lineNo = 0;
        while (($line = fgets($stream)) !== false) {
            $lineNo++;
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (! is_array($row)) {
                $this->error($name . ' line ' . $lineNo . ' is damaged and was skipped.');
                continue;
            }
            $fn($row);
        }
        fclose($stream);
    }

    protected function openZip(string $path): ZipArchive
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('This file is not a valid backup ZIP.');
        }

        return $zip;
    }

    /**
     * @return array<string, mixed>
     */
    protected function readManifest(ZipArchive $zip): array
    {
        $stat     = $zip->statName('manifest.json');
        $manifest = $stat !== false && $stat['size'] < 1048576 ? json_decode((string) $zip->getFromName('manifest.json'), true) : null;
        if (! is_array($manifest) || ($manifest['format'] ?? '') !== self::FORMAT) {
            $zip->close();
            throw new RuntimeException('This is not an inbox backup file. Use "Download backup" to create one.');
        }
        if ((int) ($manifest['version'] ?? 0) < 1 || (int) $manifest['version'] > self::VERSION) {
            $zip->close();
            throw new RuntimeException('This backup was made by a newer version of the app. Update the app first.');
        }

        return $manifest;
    }

    protected function stagedPath(string $token): string
    {
        if (preg_match('/^[a-f0-9]{32}\.zip$/', $token) !== 1) {
            throw new RuntimeException('Invalid restore session. Upload the backup again.');
        }
        $path = $this->transfer->stagingDir() . DIRECTORY_SEPARATOR . $token;
        if (! is_file($path)) {
            throw new RuntimeException('Backup upload expired. Upload the backup again.');
        }

        return $path;
    }

    protected function localMediaPath(string $name): ?string
    {
        if (! $this->safeMediaName($name)) {
            return null;
        }
        $path = WRITEPATH . 'uploads' . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . $name;
        if (is_file($path)) {
            return $path;
        }
        $row = model(\App\Models\MediaModel::class)->where('filename', $name)->first();
        $rel = is_array($row) ? str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) ($row['path'] ?? '')) : '';
        if ($rel === '' || str_contains($rel, '..')) {
            return null;
        }
        $path = WRITEPATH . ltrim($rel, DIRECTORY_SEPARATOR);

        return is_file($path) ? $path : null;
    }

    public function safeMediaName(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,180}$/', $name) === 1
            && in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::MEDIA_EXTENSIONS, true);
    }

    protected function validDateTime(string $value): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})/', trim($value), $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6]);
    }

    protected function validDate(string $value): ?string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? trim($value) : null;
    }

    protected function error(string $message): void
    {
        if (count($this->result['errors'] ?? []) < 20) {
            $this->result['errors'][] = $message;
        }
    }

    /**
     * @param resource $fh
     * @param array<string, mixed> $row
     */
    protected function writeLine($fh, array $row): void
    {
        fwrite($fh, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
    }

    /**
     * @return resource
     */
    protected function openWork(string $dir, string $name)
    {
        $fh = fopen($dir . DIRECTORY_SEPARATOR . $name, 'wb');
        if ($fh === false) {
            throw new RuntimeException('Unable to write backup file.');
        }

        return $fh;
    }

    protected function purgeStale(string $dir): void
    {
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < time() - 86400) {
                @unlink($file);
            }
        }
    }

    protected function removeDir(string $dir): void
    {
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}
