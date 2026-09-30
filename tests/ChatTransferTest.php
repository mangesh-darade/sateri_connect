<?php

declare(strict_types=1);

/**
 * Team Inbox chat export / import: column detection, date & direction parsing, import into contacts +
 * conversations, duplicate-safe re-import, export round trip, and WhatsApp policy safety
 * (imports never open the 24h window, never change opt-in, never queue a message).
 * Uses real DB rows with a unique prefix; everything is removed at the end. Nothing is sent to WhatsApp.
 *
 * Run: php tests/ChatTransferTest.php
 */

define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);
chdir(FCPATH);

require FCPATH . '../app/Config/Paths.php';
$paths = new \Config\Paths();
require $paths->systemDirectory . '/Boot.php';
\CodeIgniter\Boot::bootSpark($paths);

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

$db       = db_connect();
$svc      = new ChatTransferService();
$contacts = model(ContactModel::class);
$mA       = '919990' . random_int(100000, 999999);   // existing contact
$mB       = '919991' . random_int(100000, 999999);   // created by import
$files    = [];

$cleanup = static function () use ($db, $mA, $mB, &$files): void {
    $ids = array_column($db->table('contacts')->select('id')->whereIn('mobile', [$mA, $mB])->get()->getResultArray(), 'id');
    if ($ids !== []) {
        $db->table('messages')->whereIn('contact_id', $ids)->delete();
        $db->table('message_queue')->whereIn('contact_id', $ids)->delete();
        $db->table('conversations')->whereIn('contact_id', $ids)->delete();
        $db->table('contacts')->whereIn('id', $ids)->delete();
    }
    foreach ($files as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
};

try {
    // ---------------- 1. Column detection (Cheerio-style headers) ----------------
    $map = $svc->suggestMapping(['Phone Number', 'Name', 'Message', 'Sent By', 'Date', 'Time', 'Status', 'Agent Notes']);
    check('M1 Cheerio headers auto-mapped', $map['Phone Number'] === 'mobile' && $map['Message'] === 'message' && $map['Sent By'] === 'direction'
        && $map['Date'] === 'date' && $map['Time'] === 'time' && $map['Status'] === 'status' && $map['Agent Notes'] === 'skip', json_encode($map));
    $map2 = $svc->suggestMapping(ChatTransferService::EXPORT_COLUMNS);
    check('M2 own export format auto-mapped', $map2['timestamp'] === 'timestamp' && $map2['mobile'] === 'mobile' && $map2['direction'] === 'direction' && $map2['media_url'] === 'media_url');

    // ---------------- 2. Dates & direction ----------------
    $p = static fn (string $v) => $svc->parseTimestamp($v);
    check('D1 Indian d/m/Y date', $p('01/09/2026 10:15') === '2026-09-01 10:15:00' && $p('01/09/2026, 10:15 PM') === '2026-09-01 22:15:00');
    check('D2 ISO with Z converted to app time', $p('2026-09-01T04:45:00Z') === date('Y-m-d H:i:s', strtotime('2026-09-01T04:45:00Z')));
    check('D3 epoch seconds / milliseconds', $p('1756700100') === date('Y-m-d H:i:s', 1756700100) && $p('1756700100000') === date('Y-m-d H:i:s', 1756700100));
    check('D4 future / garbage dates rejected', $p(date('Y-m-d H:i:s', time() + 86400 * 3)) === null && $p('yesterday-ish') === null);
    check('D5 direction words', $svc->normalizeDirection('Customer') === 'inbound' && $svc->normalizeDirection('Agent') === 'outbound'
        && $svc->normalizeDirection('BOT') === 'outbound' && $svc->normalizeDirection('incoming') === 'inbound' && $svc->normalizeDirection('???') === null);
    check('D6 sender = customer phone → inbound', $svc->normalizeDirection('+' . $mA, $mA) === 'inbound');

    // ---------------- 3. Import ----------------
    $aId = (int) $contacts->insert(['channel' => 'whatsapp', 'name' => 'ZQA Existing', 'mobile' => $mA, 'status' => 'active']);
    $recentReal = date('Y-m-d H:i:s', time() - 7200);
    $db->table('messages')->insert(['contact_id' => $aId, 'direction' => 'inbound', 'message_type' => 'text', 'content' => 'live hi', 'status' => 'received', 'created_at' => $recentReal, 'updated_at' => $recentReal]);
    $contacts->update($aId, ['last_reply_at' => null]);

    $oneHourAgo = date('d/m/Y H:i', time() - 3600);
    $csv = "Phone Number,Name,Message,Sent By,Date & Time,Status\n"
        . "{$mA},ZQA Existing,Old question,Customer,05/01/2025 09:00,\n"
        . "{$mA},ZQA Existing,Old answer,Agent,05/01/2025 09:05,read\n"
        . "{$mB},ZQA New,Hello from import,Customer,{$oneHourAgo},\n"
        . "{$mB},ZQA New,=HYPERLINK(\"x\"),Agent,{$oneHourAgo},delivered\n"
        . "{$mB},ZQA New,Hello from import,Customer,{$oneHourAgo},\n"     // exact duplicate row
        . "12,Bad,No phone,Customer,05/01/2025 09:00,\n"                 // invalid phone
        . "{$mB},ZQA New,Who?,Robot?,05/01/2025 09:00,\n";              // unknown direction
    $tmp = WRITEPATH . 'uploads/zqa_chat_import.csv';
    file_put_contents($tmp, $csv);
    $files[] = $tmp;

    $queueBefore = (int) $db->table('message_queue')->countAllResults();
    $preview = $svc->preview($tmp, 'cheerio_export.csv');
    $files[] = WRITEPATH . 'uploads/chat-imports/' . $preview['token'];
    check('I1 preview detects mapping + counts', $preview['mapping']['Date & Time'] === 'timestamp' && $preview['stats']['ready'] === true
        && $preview['stats']['valid'] === 5 && $preview['stats']['invalid'] === 2 && $preview['stats']['contacts'] === 2, json_encode($preview['stats']));
    $bad = $svc->analyseStaged($preview['token'], array_merge($preview['mapping'], ['Sent By' => 'skip']));
    check('I2 re-check flags a missing required column', $bad['ready'] === false && in_array('Direction / sender', $bad['missing'], true));

    $res = $svc->import($preview['token'], $preview['mapping'], true);
    check('I3 import result', $res['imported'] === 4 && $res['duplicates'] === 1 && $res['skipped'] === 2 && $res['contacts_created'] === 1 && $res['contacts_matched'] === 1, json_encode($res));

    $b    = $contacts->findByMobile($mB);
    $msgs = $db->table('messages')->where('contact_id', (int) $b['id'])->orderBy('created_at')->get()->getResultArray();
    check('I4 new contact created with name, no opt-in', $b !== null && $b['name'] === 'ZQA New' && (int) ($b['wa_opt_in'] ?? 0) === 0);
    check('I5 messages keep original time, read, tagged as import, no wamid', count($msgs) === 2
        && str_starts_with((string) $msgs[0]['created_at'], date('Y-m-d H:i', time() - 3600))
        && (int) $msgs[0]['is_read'] === 1 && str_starts_with((string) $msgs[0]['external_message_id'], 'import:') && empty($msgs[0]['wamid']));
    check('I6 outbound status kept, inbound = received', in_array('delivered', array_column($msgs, 'status'), true) && in_array('received', array_column($msgs, 'status'), true));
    $conv = $db->table('conversations')->where('contact_id', (int) $b['id'])->get()->getRowArray();
    check('I7 conversation points at newest imported message', is_array($conv) && (int) $conv['last_message_id'] > 0 && (string) $conv['last_message_at'] === (string) $msgs[1]['created_at']);

    // ---------------- 4. WhatsApp policy safety ----------------
    $b = $contacts->find((int) $b['id']);
    check('P1 imported inbound 1h ago does NOT open the 24h window', contact_within_24h_window($b, false) === false && empty($b['last_reply_at']));
    $a = $contacts->find($aId);
    check('P2 older imported history does not hide a real recent message', contact_within_24h_window($a, false) === true);
    check('P3 import queued nothing', (int) $db->table('message_queue')->countAllResults() === $queueBefore);

    // ---------------- 5. Re-import is duplicate-safe ----------------
    $preview2 = $svc->preview($tmp, 'cheerio_export.csv');
    $files[]  = WRITEPATH . 'uploads/chat-imports/' . $preview2['token'];
    $res2     = $svc->import($preview2['token'], $preview2['mapping'], true);
    check('R1 same file again → 0 imported, all duplicates', $res2['imported'] === 0 && $res2['duplicates'] === 5 && $res2['contacts_created'] === 0, json_encode($res2));

    // ---------------- 6. Export ----------------
    $out = $svc->exportToFile(['scope' => 'numbers', 'numbers' => "+{$mA}\n{$mB}"]);
    $files[] = $out['path'];
    $lines = array_map(static fn ($l) => str_getcsv($l, ',', '"', ''), array_filter(explode("\n", (string) preg_replace('/^\xEF\xBB\xBF/', '', (string) file_get_contents($out['path'])))));
    check('E1 export by numbers: header + 5 messages', $out['rows'] === 5 && $lines[0] === ChatTransferService::EXPORT_COLUMNS, 'rows=' . $out['rows']);
    $flat = implode("\n", array_map(static fn ($l) => implode('|', $l), $lines));
    check('E2 export blocks spreadsheet formulas', str_contains($flat, "'=HYPERLINK") && ! str_contains($flat, '|=HYPERLINK'));
    $one = $svc->exportToFile(['scope' => 'contact', 'contact_id' => $aId, 'from' => '2025-01-05', 'to' => '2025-01-05']);
    $files[] = $one['path'];
    check('E3 export one chat + date range', $one['rows'] === 2);
    try {
        $svc->exportToFile(['scope' => 'numbers', 'numbers' => '']);
        check('E4 empty number list rejected', false);
    } catch (\RuntimeException $e) {
        check('E4 empty number list rejected', true);
    }
    $preview3 = $svc->preview($out['path'], 'export.csv');
    $files[]  = WRITEPATH . 'uploads/chat-imports/' . $preview3['token'];
    $res3     = $svc->import($preview3['token'], $preview3['mapping'], true);
    check('E5 export → import round trip adds nothing new for imported rows', $res3['skipped'] === 0 && $res3['imported'] === 1 && $res3['duplicates'] === 4, json_encode($res3));

    // ---------------- 7. Inbox thread order ----------------
    $thread = model(\App\Models\MessageModel::class)->where('contact_id', $aId)->orderBy('created_at', 'DESC')->orderBy('id', 'DESC')->findAll(50);
    check('O1 thread newest-first by time: live message before 2025 history', ($thread[0]['content'] ?? '') === 'live hi' || str_starts_with((string) ($thread[0]['created_at'] ?? ''), substr($recentReal, 0, 13)));
} catch (\Throwable $e) {
    check('Unexpected exception', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    $cleanup();
}

echo "\n=== Result: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
