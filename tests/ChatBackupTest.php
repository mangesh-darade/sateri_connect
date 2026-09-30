<?php

declare(strict_types=1);

/**
 * Team Inbox full backup (ZIP) → restore round trip: contacts, groups, custom fields, consent, conversation status,
 * internal notes, messages and media files. Also: chunked upload, duplicate-safe re-restore, consent never loosened,
 * live chat status kept, 24h window untouched, nothing queued, hostile ZIPs rejected.
 * Uses real DB rows with a unique prefix; everything is removed at the end. Nothing is sent to WhatsApp.
 *
 * Run: php tests/ChatBackupTest.php
 */

define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);
chdir(FCPATH);

require FCPATH . '../app/Config/Paths.php';
$paths = new \Config\Paths();
require $paths->systemDirectory . '/Boot.php';
\CodeIgniter\Boot::bootSpark($paths);

use App\Libraries\ChatBackupService;
use App\Libraries\ChatTransferService;
use App\Models\ContactModel;

helper('whatsapp');

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "[PASS] {$label}\n";
    } else {
        $fail++;
        echo "[FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

/** Upload a file through receiveChunk in small slices, like the browser does. */
function stageInSlices(ChatBackupService $svc, string $path, int $slice): array
{
    $data     = (string) file_get_contents($path);
    $uploadId = '';
    $offset   = 0;
    $res      = [];
    $tmp      = WRITEPATH . 'uploads/zqa_slice.bin';
    while ($offset < strlen($data)) {
        $part = substr($data, $offset, $slice);
        file_put_contents($tmp, $part);
        $last     = $offset + strlen($part) >= strlen($data);
        $res      = $svc->receiveChunk($uploadId, $offset, $tmp, $last);
        $uploadId = $res['upload_id'];
        $offset  += strlen($part);
    }
    @unlink($tmp);

    return $res;
}

$db       = db_connect();
$svc      = new ChatBackupService();
$contacts = model(ContactModel::class);
$rand     = (string) random_int(100000, 999999);
$mA       = '919992' . $rand;
$mB       = '919993' . $rand;
$tagName  = 'ZQA Backup ' . $rand;
$mediaDir = WRITEPATH . 'uploads/media/';
$mediaFn  = 'zqa_' . $rand . '.jpg';
$mediaRaw = "\xFF\xD8\xFF" . random_bytes(2048);
$files    = [$mediaDir . $mediaFn];
$user     = $db->table('users')->select('id, email')->orderBy('id')->get()->getRowArray();
$userId   = (int) ($user['id'] ?? 0);

$wipe = static function () use ($db, $mA, $mB, $tagName): void {
    $ids = array_column($db->table('contacts')->select('id')->whereIn('mobile', [$mA, $mB])->get()->getResultArray(), 'id');
    if ($ids !== []) {
        foreach (['messages', 'message_queue', 'conversations', 'internal_notes', 'contact_tags'] as $t) {
            $db->table($t)->whereIn('contact_id', $ids)->delete();
        }
        $db->table('contacts')->whereIn('id', $ids)->delete();
    }
    $db->table('tags')->where('name', $tagName)->delete();
};

try {
    // ---------------- Seed a small inbox ----------------
    if (! is_dir($mediaDir)) {
        mkdir($mediaDir, 0755, true);
    }
    file_put_contents($mediaDir . $mediaFn, $mediaRaw);
    $now   = time();
    $optIn = date('Y-m-d H:i:s', $now - 86400 * 30);
    $aId   = (int) $contacts->insert([
        'channel' => 'whatsapp', 'name' => 'ZQA Backup A', 'mobile' => $mA, 'status' => 'active', 'email' => 'zqa.a@example.com',
        'assigned_to' => $userId ?: null, 'custom_fields' => ['city' => 'Pune', 'plan' => 'Gold'],
        'wa_opt_in' => 1, 'wa_opt_in_at' => $optIn, 'wa_opt_in_source' => 'website_form',
    ]);
    $outAt = date('Y-m-d H:i:s', $now - 86400 * 10);
    $bId   = (int) $contacts->insert([
        'channel' => 'whatsapp', 'name' => 'ZQA Backup B', 'mobile' => $mB, 'status' => 'active',
        'wa_opt_in' => 0, 'wa_opt_in_at' => date('Y-m-d H:i:s', $now - 86400 * 40), 'wa_opted_out_at' => $outAt,
    ]);
    $db->table('tags')->insert(['name' => $tagName, 'color' => '#FF5500', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
    $tagId = (int) $db->insertID();
    $db->table('contact_tags')->insert(['contact_id' => $aId, 'tag_id' => $tagId]);
    $convA = model(\App\Models\ConversationModel::class)->findOrCreateForContact($aId);
    $db->table('conversations')->where('id', $convA['id'])->update(['status' => 'resolved', 'assigned_to' => $userId ?: null]);
    $convB = model(\App\Models\ConversationModel::class)->findOrCreateForContact($bId);
    if ($userId > 0) {
        $db->table('internal_notes')->insert(['contact_id' => $aId, 'user_id' => $userId, 'note' => 'VIP — call before 6pm', 'is_internal' => 1, 'created_at' => '2025-02-01 10:00:00']);
    }
    $msg = static function (int $contactId, int $convId, string $dir, string $type, ?string $content, string $at, ?string $media = null, ?string $payload = null) use ($db): void {
        $db->table('messages')->insert([
            'contact_id' => $contactId, 'conversation_id' => $convId, 'direction' => $dir, 'message_type' => $type, 'content' => $content,
            'media_url' => $media, 'payload' => $payload, 'status' => $dir === 'inbound' ? 'received' : 'read', 'is_read' => 1,
            'wamid' => 'wamid.ZQA' . bin2hex(random_bytes(6)), 'created_at' => $at, 'updated_at' => $at,
        ]);
    };
    $inAt = date('Y-m-d H:i:s', $now - 3600);
    $msg($aId, (int) $convA['id'], 'inbound', 'text', 'Hi, send catalogue', '2025-01-05 09:00:00');
    $msg($aId, (int) $convA['id'], 'outbound', 'image', 'Catalogue', date('Y-m-d H:i:s', $now - 3000), site_url('media/serve/' . $mediaFn), '{"button":"Order now"}');
    $msg($aId, (int) $convA['id'], 'inbound', 'text', 'Thanks!', $inAt);
    $msg($bId, (int) $convB['id'], 'inbound', 'text', 'STOP', $outAt);
    $db->table('contacts')->where('id', $aId)->update(['last_reply_at' => null]);

    // ---------------- Backup ----------------
    $backup  = $svc->createBackup(['scope' => 'numbers', 'numbers' => "{$mA}, {$mB}"]);
    $files[] = $backup['path'];
    $zip     = new ZipArchive();
    $zip->open($backup['path']);
    $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
    check('B1 backup ZIP has all parts', $zip->statName('contacts.jsonl') !== false && $zip->statName('messages.jsonl') !== false
        && $zip->statName('conversations.jsonl') !== false && $zip->statName('notes.jsonl') !== false && $zip->statName('tags.json') !== false
        && $zip->statName('chats.csv') !== false && $zip->getFromName('media/' . $mediaFn) === $mediaRaw);
    check('B2 counts', $backup['counts']['contacts'] === 2 && $backup['counts']['messages'] === 4 && $backup['counts']['media'] === 1
        && $backup['counts']['tags'] === 1 && $backup['counts']['conversations'] === 2, json_encode($backup['counts']));
    check('B3 manifest format + agents by email', ($manifest['format'] ?? '') === ChatBackupService::FORMAT
        && ($userId === 0 || ($manifest['users'][(string) $userId] ?? '') === strtolower((string) $user['email'])));
    $zip->close();

    // ---------------- "New server": remove everything, then restore ----------------
    $wipe();
    @unlink($mediaDir . $mediaFn);
    $queueBefore = (int) $db->table('message_queue')->countAllResults();

    $staged = stageInSlices($svc, $backup['path'], 700);
    check('U1 sliced upload assembles and reads the backup', ($staged['done'] ?? false) === true && ($staged['summary']['counts']['messages'] ?? 0) === 4);
    try {
        $svc->receiveChunk($staged['upload_id'], 999, $backup['path'], false);
        check('U2 out-of-order slice rejected', false);
    } catch (RuntimeException $e) {
        check('U2 out-of-order slice rejected', true);
    }

    $r = $svc->restore($staged['token'], 0);
    check('R1 restore result', $r['contacts_created'] === 2 && $r['messages_restored'] === 4 && $r['media_restored'] === 1
        && $r['tags_created'] === 1 && $r['conversations_restored'] === 2 && $r['notes_restored'] === ($userId > 0 ? 1 : 0), json_encode($r));

    $a = $contacts->findByMobile($mA);
    $b = $contacts->findByMobile($mB);
    $aTags = array_column($db->table('contact_tags ct')->select('t.name, t.color')->join('tags t', 't.id = ct.tag_id')->where('ct.contact_id', (int) $a['id'])->get()->getResultArray(), 'color', 'name');
    check('R2 contact A: fields, custom fields, group', $a['name'] === 'ZQA Backup A' && $a['email'] === 'zqa.a@example.com'
        && ($a['custom_fields']['plan'] ?? '') === 'Gold' && ($aTags[$tagName] ?? '') === '#FF5500');
    check('R3 contact A: opt-in record kept, agent re-linked, 24h not opened', (int) $a['wa_opt_in'] === 1 && $a['wa_opt_in_at'] === $optIn
        && $a['wa_opt_in_source'] === 'website_form' && (int) ($a['assigned_to'] ?? 0) === $userId && empty($a['last_reply_at']));
    check('R4 contact B: opt-out kept', (int) $b['wa_opt_in'] === 0 && $b['wa_opted_out_at'] === $outAt);
    $conv = $db->table('conversations')->where('contact_id', (int) $a['id'])->get()->getRowArray();
    check('R5 chat status + assignment restored', $conv['status'] === 'resolved' && (int) ($conv['assigned_to'] ?? 0) === $userId);

    $msgs = $db->table('messages')->where('contact_id', (int) $a['id'])->orderBy('created_at')->get()->getResultArray();
    $img  = array_values(array_filter($msgs, static fn ($m) => $m['message_type'] === 'image'))[0] ?? [];
    $pl   = json_decode((string) ($img['payload'] ?? ''), true);
    check('R6 messages keep time/status, tagged import, no wamid column', count($msgs) === 3 && $msgs[0]['created_at'] === '2025-01-05 09:00:00'
        && $img['status'] === 'read' && str_starts_with((string) $img['external_message_id'], ChatTransferService::IMPORT_PREFIX) && empty($img['wamid'])
        && ($pl['button'] ?? '') === 'Order now' && ($pl['restored_from_backup'] ?? false) === true && str_starts_with((string) ($pl['original_wamid'] ?? ''), 'wamid.ZQA'),
        json_encode(array_map(static fn ($m) => [$m['created_at'], $m['status'], $m['message_type'], $m['media_url'], $m['wamid'], $m['payload']], $msgs)));
    check('R7 media file restored and linked', is_file($mediaDir . $mediaFn) && file_get_contents($mediaDir . $mediaFn) === $mediaRaw
        && $img['media_url'] === site_url('media/serve/' . $mediaFn));
    check('R8 restored inbound 1h ago does NOT open the 24h window', contact_within_24h_window($contacts->find((int) $a['id']), false) === false);
    check('R9 restore queued nothing', (int) $db->table('message_queue')->countAllResults() === $queueBefore);
    check('R10 inbox list points at newest message', (string) $conv['last_message_at'] === $img['created_at'], (string) $conv['last_message_at'] . ' vs ' . $img['created_at'] . ' ' . json_encode(array_map(static fn ($m) => [$m['id'], $m['created_at'], $m['message_type']], $msgs)));
    if ($userId > 0) {
        $note = $db->table('internal_notes')->where('contact_id', (int) $a['id'])->get()->getRowArray();
        check('R11 internal note restored with author + date', ($note['note'] ?? '') === 'VIP — call before 6pm' && (int) $note['user_id'] === $userId && $note['created_at'] === '2025-02-01 10:00:00');
    }

    // ---------------- Restore again over live data ----------------
    $recentOut = date('Y-m-d H:i:s', $now - 60);
    $contacts->update((int) $a['id'], ['wa_opt_in' => 0, 'wa_opted_out_at' => $recentOut]);                     // customer said STOP today
    $contacts->update((int) $b['id'], ['wa_opt_in' => 1, 'wa_opt_in_at' => date('Y-m-d H:i:s', $now - 86400 * 20), 'wa_opted_out_at' => null]); // older than backup opt-out
    $db->table('conversations')->where('contact_id', (int) $a['id'])->update(['status' => 'open']);
    $staged2 = stageInSlices($svc, $backup['path'], 1024 * 1024);
    $r2      = $svc->restore($staged2['token'], 0);
    check('D1 second restore adds nothing', $r2['messages_restored'] === 0 && $r2['messages_duplicate'] === 4 && $r2['contacts_created'] === 0
        && $r2['media_restored'] === 0 && $r2['notes_restored'] === 0 && $r2['tags_created'] === 0 && $r2['conversations_restored'] === 0, json_encode($r2));
    $a2 = $contacts->find((int) $a['id']);
    $b2 = $contacts->find((int) $b['id']);
    check('D2 newer local opt-out is never replaced by backup opt-in', (int) $a2['wa_opt_in'] === 0 && $a2['wa_opted_out_at'] === $recentOut);
    check('D3 backup opt-out newer than local opt-in is applied', (int) $b2['wa_opt_in'] === 0 && $b2['wa_opted_out_at'] === $outAt);
    check('D4 live chat status not overwritten', $db->table('conversations')->where('contact_id', (int) $a['id'])->get()->getRowArray()['status'] === 'open');
    check('D5 media not duplicated', count(glob($mediaDir . 'rs_*') ?: []) === count(array_filter(glob($mediaDir . 'rs_*') ?: [], static fn ($f) => filemtime($f) < $now)));

    // ---------------- Hostile / wrong files ----------------
    $evil = WRITEPATH . 'uploads/zqa_evil.zip';
    $files[] = $evil;
    $z = new ZipArchive();
    $z->open($evil, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $z->addFromString('manifest.json', json_encode(['format' => ChatBackupService::FORMAT, 'version' => 1, 'users' => []]));
    $z->addFromString('contacts.jsonl', json_encode(['ref' => 1, 'channel' => 'whatsapp', 'mobile' => $mA, 'name' => 'x']) . "\n");
    $z->addFromString('messages.jsonl', json_encode(['contact_ref' => 1, 'direction' => 'inbound', 'message_type' => 'image', 'content' => 'evil ' . $rand,
        'media_file' => 'shell.php', 'media_url' => 'http://x/media/serve/shell.php', 'created_at' => '2025-03-03 10:00:00']) . "\nnot-json\n");
    $z->addFromString('media/shell.php', '<?php echo 1;');
    $z->addFromString('media/../../escape.jpg', 'x');
    $z->close();
    $s3 = stageInSlices($svc, $evil, 1024 * 1024);
    $r3 = $svc->restore($s3['token'], 0);
    check('S1 .php media never written, damaged line reported', ! is_file($mediaDir . 'shell.php') && ! is_file(WRITEPATH . 'escape.jpg')
        && $r3['media_restored'] === 0 && $r3['messages_restored'] === 1 && count($r3['errors']) >= 1, json_encode($r3));
    $plain = WRITEPATH . 'uploads/zqa_plain.zip';
    $files[] = $plain;
    $z = new ZipArchive();
    $z->open($plain, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $z->addFromString('readme.txt', 'hello');
    $z->close();
    try {
        stageInSlices($svc, $plain, 1024 * 1024);
        check('S2 ZIP without backup manifest rejected', false);
    } catch (RuntimeException $e) {
        check('S2 ZIP without backup manifest rejected', str_contains($e->getMessage(), 'not an inbox backup'));
    }
    try {
        $svc->restore('../../app/Config/App.zip');
        check('S3 path-traversal token rejected', false);
    } catch (RuntimeException $e) {
        check('S3 path-traversal token rejected', true);
    }
    check('S4 safe media names only', $svc->safeMediaName('in_ab12.jpg') && ! $svc->safeMediaName('x.php') && ! $svc->safeMediaName('.htaccess') && ! $svc->safeMediaName('a/b.jpg'));
} catch (\Throwable $e) {
    check('Unexpected exception', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    $wipe();
    foreach ($files as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
}

echo "\n=== Result: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
