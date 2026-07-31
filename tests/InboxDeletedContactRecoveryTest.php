<?php

declare(strict_types=1);

/**
 * Verify inbound WhatsApp webhooks auto-create unknown contacts and revive
 * contacts that were deleted from the tool, so their thread returns to the inbox.
 *
 * Run: php tests/InboxDeletedContactRecoveryTest.php
 */

define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);
chdir(FCPATH);

require FCPATH . '../app/Config/Paths.php';
$paths = new \Config\Paths();
require $paths->systemDirectory . '/Boot.php';
\CodeIgniter\Boot::bootSpark($paths);

use App\Controllers\Webhooks;
use App\Models\ContactModel;

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

echo "=== Inbox Deleted Contact Recovery Test ===\n\n";

$db     = db_connect();
$model  = model(ContactModel::class);
$mobile = '919000000777';

$cleanup = static function () use ($db, $mobile): void {
    $ids = array_column(
        $db->table('contacts')->select('id')->where('mobile', $mobile)->get()->getResultArray(),
        'id'
    );
    if ($ids !== []) {
        $db->table('messages')->whereIn('contact_id', $ids)->delete();
        $db->table('conversations')->whereIn('contact_id', $ids)->delete();
        $db->table('contacts')->whereIn('id', $ids)->delete();
    }
    $db->table('notifications')->like('message', 'hello wamid.recovery.', 'after')->delete();
};

$countThreads = static fn (): int => $db->table('conversations cv')
    ->join('contacts c', 'c.id = cv.contact_id')
    ->where('c.mobile', $mobile)
    ->where('c.deleted_at', null)
    ->countAllResults();

$countMessages = static fn (): int => $db->table('messages m')
    ->join('contacts c', 'c.id = m.contact_id')
    ->where('c.mobile', $mobile)
    ->countAllResults();

$controller = new Webhooks();
$handle     = new ReflectionMethod(Webhooks::class, 'handleInboundMessage');
$handle->setAccessible(true);

$inbound = static function (string $waMessageId) use ($handle, $controller, $mobile): void {
    $handle->invoke($controller, [
        'id'        => $waMessageId,
        'from'      => $mobile,
        'type'      => 'text',
        'text'      => ['body' => 'hello ' . $waMessageId],
        'timestamp' => (string) time(),
    ], [
        ['wa_id' => $mobile, 'profile' => ['name' => 'Webhook Lead']],
    ], [
        'phone_number_id'      => '000',
        'display_phone_number' => '000',
    ]);
};

$cleanup();

echo "-- unknown number sends first message --\n";
$inbound('wamid.recovery.1');
$contact = $model->findByMobile($mobile);
check('unknown number auto-creates contact', $contact !== null);
check('thread visible in inbox query', $countThreads() === 1);
check('inbound message stored', $countMessages() === 1);

echo "\n-- contact deleted from tool, then messages again --\n";
$model->delete((int) $contact['id']);
check('thread hidden while contact is deleted', $countThreads() === 0);

$inbound('wamid.recovery.2');
check('thread returns to inbox after new message', $countThreads() === 1);
check('second inbound message stored', $countMessages() === 2);
check('contact revived instead of duplicated', $db->table('contacts')->where('mobile', $mobile)->countAllResults() === 1);

echo "\n-- model level --\n";
$model->delete((int) $contact['id']);
$revived = $model->findOrCreateForChannel('whatsapp', $mobile, ['mobile' => $mobile], $wasCreated);
check('findOrCreateForChannel revives soft-deleted row', (int) $revived['id'] === (int) $contact['id']);
check('revived contact is not reported as newly created', $wasCreated === false);
check('revived contact status is active', ($revived['status'] ?? '') === 'active');

$cleanup();

echo "\nPassed: {$pass}  Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
