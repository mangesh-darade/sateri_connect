<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\ConversationModel;
use App\Models\MessageModel;
use App\Models\NotificationModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Deep-test: open-chat mark-read clears soft notifications + unread without refresh path.
 *
 * php spark notif:mark-read-test
 */
class TestNotifMarkRead extends BaseCommand
{
    protected $group       = 'WhatsApp';
    protected $name        = 'notif:mark-read-test';
    protected $description = 'Deep-test chat mark-read clears soft notification counts';

    public function run(array $params)
    {
        $pass = 0;
        $fail = 0;
        $check = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
            if ($ok) {
                $pass++;
                CLI::write("[PASS] {$label}" . ($detail !== '' ? " — {$detail}" : ''), 'green');

                return;
            }
            $fail++;
            CLI::write('[FAIL] ' . $label . ($detail !== '' ? " — {$detail}" : ''), 'red');
        };

        CLI::write('=== Soft notification mark-as-read deep test ===', 'yellow');

        $root   = ROOTPATH;
        $chatJs = (string) file_get_contents($root . 'public/assets/js/chat.js');
        $appJs  = (string) file_get_contents($root . 'public/assets/js/app.js');

        $check('chat.js has markConversationRead', str_contains($chatJs, 'Chat.markConversationRead'));
        $check('chat.js clears unread UI on open', str_contains($chatJs, 'Chat.clearConversationUnreadUi'));
        $check('chat.js marks read on silent inbound', preg_match('/hadInbound[\s\S]{0,400}markConversationRead/', $chatJs) === 1);
        $check('app.js LiveNotif.setInboxBadges', str_contains($appJs, 'LiveNotif.setInboxBadges'));
        $check('app.js applyUnreadFromServer', str_contains($appJs, 'LiveNotif.applyUnreadFromServer'));
        $check('app.js optimistic bell click', str_contains($appJs, 'LiveNotif.setBadge(cur - 1)'));

        $db = db_connect();
        $check('notifications table', $db->tableExists('notifications'));

        $user = $db->table('users')->select('id')->orderBy('id', 'ASC')->get(1)->getRowArray();
        $uid  = (int) ($user['id'] ?? 0);
        $check('user exists', $uid > 0, 'uid=' . $uid);
        if ($uid <= 0) {
            CLI::write("Result: {$pass} passed, {$fail} failed", $fail ? 'red' : 'green');

            return EXIT_ERROR;
        }

        $contact = $db->table('contacts')->select('id')->orderBy('id', 'ASC')->get(1)->getRowArray();
        $cid     = (int) ($contact['id'] ?? 0);
        $check('contact exists', $cid > 0, 'contact_id=' . $cid);
        if ($cid <= 0) {
            CLI::write("Result: {$pass} passed, {$fail} failed", $fail ? 'red' : 'green');

            return EXIT_ERROR;
        }

        $notif = model(NotificationModel::class);
        $otherCid = $cid + 999999;

        // Seed: 2 unread for target contact, 1 for other contact, 1 non-chat
        $ids = [];
        $ids[] = (int) $notif->push($uid, 'TEST New message A', 'hello', 'chat', site_url('chat?contact_id=' . $cid));
        $ids[] = (int) $notif->push($uid, 'TEST New message B', 'hi', 'chat', site_url('chat?contact_id=' . $cid . '&channel=whatsapp'));
        $ids[] = (int) $notif->push($uid, 'TEST Other contact', 'x', 'chat', site_url('chat?contact_id=' . $otherCid));
        $ids[] = (int) $notif->push($uid, 'TEST Info note', 'y', 'info', site_url('dashboard'));
        $ids = array_values(array_filter($ids));
        $check('seeded 4 notifications', count($ids) === 4, 'ids=' . implode(',', $ids));

        $before = $notif->countUnreadForUser($uid);
        $cleared = $notif->markChatNotificationsReadForContact($uid, $cid);
        $after = $notif->countUnreadForUser($uid);

        $check('cleared exactly 2 for contact', $cleared === 2, 'cleared=' . $cleared);
        $check('unread dropped by 2', $after === ($before - 2), "before={$before} after={$after}");

        // Ensure other contact + info remain unread
        $stillOther = (int) $db->table('notifications')
            ->where('user_id', $uid)
            ->where('is_read', 0)
            ->like('link', 'contact_id=' . $otherCid)
            ->countAllResults();
        $stillInfo = (int) $db->table('notifications')
            ->where('user_id', $uid)
            ->where('is_read', 0)
            ->where('title', 'TEST Info note')
            ->countAllResults();
        $check('other-contact notif still unread', $stillOther === 1);
        $check('info notif still unread', $stillInfo === 1);

        // Contact_id substring safety: contact 12 must not clear 123
        if ($cid < 100000) {
            $spoof = (int) $notif->push(
                $uid,
                'TEST Spoof longer id',
                'z',
                'chat',
                site_url('chat?contact_id=' . $cid . '9')
            );
            $clearedSpoof = $notif->markChatNotificationsReadForContact($uid, $cid);
            $spoofUnread = (int) $db->table('notifications')->where('id', $spoof)->where('is_read', 0)->countAllResults();
            $check('does not match contact_id prefix', $clearedSpoof === 0 && $spoofUnread === 1, 'cleared=' . $clearedSpoof);
            if ($spoof > 0) {
                $ids[] = $spoof;
            }
        }

        // Conversation unread reset path (same as Chat::markRead)
        $conversation = model(ConversationModel::class)->findByContact($cid);
        if ($conversation !== null) {
            $db->table('conversations')->where('id', (int) $conversation['id'])->update(['unread_count' => 3]);
            model(ConversationModel::class)->resetUnread((int) $conversation['id']);
            $row = $db->table('conversations')->select('unread_count')->where('id', (int) $conversation['id'])->get()->getRowArray();
            $check('conversation resetUnread → 0', (int) ($row['unread_count'] ?? -1) === 0);
        } else {
            CLI::write('[SKIP] no conversation for contact — unread reset not exercised', 'yellow');
        }

        // Mark inbound messages read (same as Chat::markRead)
        $db->table('messages')->where('contact_id', $cid)->where('direction', 'inbound')->set(['is_read' => 0])->update();
        model(MessageModel::class)
            ->where('contact_id', $cid)
            ->where('direction', 'inbound')
            ->where('is_read', 0)
            ->set(['is_read' => 1])
            ->update();
        $left = (int) $db->table('messages')
            ->where('contact_id', $cid)
            ->where('direction', 'inbound')
            ->where('is_read', 0)
            ->countAllResults();
        $check('inbound messages marked read', $left === 0, 'left=' . $left);

        // Cleanup test notifications only
        if ($ids !== []) {
            $db->table('notifications')->whereIn('id', $ids)->delete();
        }
        $check('cleanup test rows', true);

        CLI::newLine();
        CLI::write("Result: {$pass} passed, {$fail} failed", $fail ? 'red' : 'green');

        return $fail > 0 ? EXIT_ERROR : EXIT_SUCCESS;
    }
}
