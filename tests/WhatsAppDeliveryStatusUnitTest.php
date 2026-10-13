<?php

/**
 * WhatsApp delivery status only moves forward: sent → delivered → read.
 * Failed does not replace a message that already reached the phone.
 */

define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);

require dirname(__DIR__) . '/vendor/autoload.php';

$pass = 0;
$fail = 0;

function check(string $label, bool $ok): void
{
    global $pass, $fail;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
}

echo "=== WhatsApp Delivery Status ===\n\n";

use App\Libraries\WhatsAppDeliveryStatus as Status;

check('sent advances to delivered', Status::shouldAdvance('sent', 'delivered'));
check('delivered advances to read', Status::shouldAdvance('delivered', 'read'));
check('delivered does not go back to sent', ! Status::shouldAdvance('delivered', 'sent'));
check('read does not go back to delivered', ! Status::shouldAdvance('read', 'delivered'));
check('sent can fail', Status::shouldAdvance('sent', 'failed'));
check('delivered is not replaced by failed', ! Status::shouldAdvance('delivered', 'failed'));
check('failed stays failed', ! Status::shouldAdvance('failed', 'delivered'));

check('message status wins when it is further', Status::resolve('sent', 'delivered') === 'delivered');
check('read timestamp shows read', Status::resolve('sent', null, null, '2026-10-13 19:00:00') === 'read');
check('delivered timestamp shows delivered', Status::resolve('sent', null, '2026-10-13 19:00:00', null) === 'delivered');
check('failed recipient stays failed', Status::resolve('failed', 'delivered') === 'failed');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
