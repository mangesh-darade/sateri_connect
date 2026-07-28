<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * php spark keywords:test-reply hey [--contact 1]
 */
class TestKeywordReply extends BaseCommand
{
    protected $group       = 'WhatsApp';
    protected $name        = 'keywords:test-reply';
    protected $description = 'Test KeywordBot match+send for a message text.';
    protected $usage       = 'keywords:test-reply <text> [--contact 1]';

    public function run(array $params)
    {
        $text = (string) ($params[0] ?? CLI::getOption('text') ?? 'hey');
        $contactId = (int) (CLI::getOption('contact') ?: 1);

        CLI::write("Testing KeywordBot for contact={$contactId} text=" . json_encode($text), 'yellow');

        $bot = service('keywordBot');
        $match = $bot->findMatch($text);
        if ($match === null) {
            CLI::error('No keyword matched.');
            CLI::write('Tip: match_type=exact needs the full message to equal the keyword. Use contains for partial matches.', 'white');

            return;
        }

        CLI::write("Matched #{$match['id']} keyword={$match['keyword']} type={$match['match_type']} response_type={$match['response_type']}", 'green');

        try {
            $res = $bot->matchAndReply($contactId, $text);
            CLI::write('Send result: ' . json_encode($res['matched']), 'green');
            if (! empty($res['response']['messages'][0]['id'])) {
                CLI::write('wamid=' . $res['response']['messages'][0]['id'], 'cyan');
            } else {
                CLI::write('response keys: ' . implode(',', array_keys($res['response'] ?? [])), 'white');
            }
        } catch (Throwable $e) {
            CLI::error('Send failed: ' . $e->getMessage());
        }
    }
}
