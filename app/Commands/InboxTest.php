<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\InboxStatus;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * php spark inbox:test
 */
class InboxTest extends BaseCommand
{
    protected $group       = 'WhatsApp';
    protected $name        = 'inbox:test';
    protected $description = 'Test Team Inbox 2.0 statuses + seed dummy conversations';

    public function run(array $params)
    {
        $pass = 0;
        $fail = 0;
        $check = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
            if ($ok) {
                $pass++;
                CLI::write("[PASS] {$label}", 'green');

                return;
            }
            $fail++;
            CLI::write('[FAIL] ' . $label . ($detail !== '' ? " — {$detail}" : ''), 'red');
        };

        CLI::write('=== Inbox Status Feature Test ===', 'yellow');

        $root = ROOTPATH;
        $css = file_get_contents($root . 'public/assets/css/app.css') ?: '';
        $sidebarCss = file_get_contents($root . 'public/assets/css/sidebar.css') ?: '';
        $layout = file_get_contents($root . 'app/Views/layouts/main.php') ?: '';
        $chatView = file_get_contents($root . 'app/Views/chat/index.php') ?: '';
        $chatJs = file_get_contents($root . 'public/assets/js/chat.js') ?: '';

        $check('brand purple token', str_contains($css, '--brand-500: #8e53f7'));
        $check('Onest font wired', str_contains($css, 'Onest') && str_contains($layout, 'Onest'));
        $check('sidebar purple', str_contains($sidebarCss, '--elint-primary: #8e53f7'));
        $check('nav Inbox group', str_contains($layout, "'title' => 'Inbox'"));
        $check('nav Analytics group', str_contains($layout, "'title' => 'Analytics'"));
        $check('chat CTWA filter UI', str_contains($chatView, 'data-scope="ctwa"'));
        $check('JS resolve status', str_contains($chatJs, "setStatus('resolved')"));
        $check('normalize closed→resolved', InboxStatus::normalize('closed') === 'resolved');
        $check('chatbot writable', InboxStatus::isWritable('chatbot'));

        $db = db_connect();
        $check('frt_due_at', $db->fieldExists('frt_due_at', 'conversations'));
        $check('ctwa_referral', $db->fieldExists('ctwa_referral', 'conversations'));

        $seeder = \Config\Database::seeder();
        $seeder->call('ConversationSeeder');

        $demoCount = (int) $db->table('contacts')->like('mobile', '91999900', 'after')->countAllResults();
        $check('dummy contacts >= 7', $demoCount >= 7, 'count=' . $demoCount);

        $statuses = ['open', 'pending', 'chatbot', 'intervened', 'resolved'];
        foreach ($statuses as $st) {
            $c = (int) $db->table('conversations cv')
                ->join('contacts c', 'c.id = cv.contact_id')
                ->like('c.mobile', '91999900', 'after')
                ->where('cv.status', $st)
                ->countAllResults();
            $check("status {$st} seeded", $c >= 1, 'count=' . $c);
        }

        $builder = $db->table('conversations cv')->join('contacts c', 'c.id = cv.contact_id');
        InboxStatus::applyCompositeFilter($builder, 'ctwa', true, true);
        $ctwa = $builder->like('c.mobile', '91999900', 'after')->countAllResults();
        $check('filter ctwa', $ctwa >= 1);

        $builder2 = $db->table('conversations cv')->join('contacts c', 'c.id = cv.contact_id');
        InboxStatus::applyCompositeFilter($builder2, 'frt_exceeded', true, true);
        $frt = $builder2->like('c.mobile', '91999900', 'after')->countAllResults();
        $check('filter frt_exceeded', $frt >= 1);

        $builder3 = $db->table('conversations cv')->join('contacts c', 'c.id = cv.contact_id');
        InboxStatus::applyStatusFilter($builder3, 'resolved');
        $resolved = $builder3->like('c.mobile', '91999900', 'after')->countAllResults();
        $check('filter resolved', $resolved >= 1);

        CLI::newLine();
        CLI::write("Result: {$pass} passed, {$fail} failed", $fail > 0 ? 'red' : 'green');

        return $fail > 0 ? EXIT_ERROR : EXIT_SUCCESS;
    }
}
