<?php

/**
 * Pure-logic checks for the social scheduler: slot planning, Drive folder links, WhatsApp broadcast options,
 * LinkedIn text escaping and media readiness tips. No database and no network.
 *
 * Run: php tests/SocialSchedulerUnitTest.php
 */

define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Libraries\Social\LinkedInPublisher;
use App\Libraries\Social\WhatsAppBroadcastPublisher;
use App\Libraries\SocialLibraryService;
use App\Libraries\SocialScheduleService;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok): void
{
    global $pass, $fail;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
}

/** Calls a private method on an instance built without its constructor (no database needed). */
function callPrivate(string $class, string $method, array $args): mixed
{
    $ref = new ReflectionClass($class);
    $obj = $ref->newInstanceWithoutConstructor();
    $m   = $ref->getMethod($method);
    $m->setAccessible(true);

    return $m->invoke($obj, ...$args);
}

echo "=== Social scheduler unit test ===\n\n";

// ---- slot planning: one file per time slot, next day after the last slot
$start = new DateTimeImmutable('2030-01-01');
check('one slot a day: file 0 is day 1', SocialScheduleService::slotTime($start, ['10:00'], 0) === '2030-01-01 10:00:00');
check('one slot a day: file 19 is day 20', SocialScheduleService::slotTime($start, ['10:00'], 19) === '2030-01-20 10:00:00');
check('two slots: file 1 is the evening of day 1', SocialScheduleService::slotTime($start, ['10:00', '18:00'], 1) === '2030-01-01 18:00:00');
check('two slots: file 2 rolls to day 2 morning', SocialScheduleService::slotTime($start, ['10:00', '18:00'], 2) === '2030-01-02 10:00:00');
check('month rollover', SocialScheduleService::slotTime(new DateTimeImmutable('2030-01-31'), ['09:00'], 1) === '2030-02-01 09:00:00');

// ---- Google Drive folder links
$id = '1AbCdEfGhIjKlMnOpQrStUvWxYz';
check('folder id from a /folders/ link', SocialLibraryService::parseFolderId("https://drive.google.com/drive/folders/{$id}?usp=sharing") === $id);
check('folder id from a /u/0/ link', SocialLibraryService::parseFolderId("https://drive.google.com/drive/u/0/folders/{$id}") === $id);
check('folder id from an open?id= link', SocialLibraryService::parseFolderId("https://drive.google.com/open?id={$id}") === $id);
check('bare folder id is accepted', SocialLibraryService::parseFolderId("  {$id}  ") === $id);
check('garbage is rejected', SocialLibraryService::parseFolderId('hello world!') === '');
check('too-short id is rejected', SocialLibraryService::parseFolderId('abc') === '');

// ---- WhatsApp broadcast options
check('template variable has no new lines', WhatsAppBroadcastPublisher::sanitizeVariable("Hi\n\nthere\t  friend") === 'Hi there friend');
check('long caption is cut under the limit', mb_strlen(WhatsAppBroadcastPublisher::sanitizeVariable(str_repeat('a', 2000))) <= WhatsAppBroadcastPublisher::CAPTION_MAX);
check('short caption is unchanged', WhatsAppBroadcastPublisher::sanitizeVariable('Hello') === 'Hello');
check('broadcast needs a template', ! WhatsAppBroadcastPublisher::validateOptions(['tag_ids' => [1]])['ok']);
check('broadcast needs an audience', ! WhatsAppBroadcastPublisher::validateOptions(['template_id' => 5])['ok']);
check('template + tag is valid', WhatsAppBroadcastPublisher::validateOptions(['template_id' => 5, 'tag_ids' => ['2']])['ok']);
check('template + all contacts is valid', WhatsAppBroadcastPublisher::validateOptions(['template_id' => 5, 'all' => true])['ok']);
check('tag id 0 does not count as an audience', ! WhatsAppBroadcastPublisher::validateOptions(['template_id' => 5, 'tag_ids' => [0]])['ok']);

// ---- LinkedIn caption escaping keeps hashtags, escapes markup characters
$esc = static fn (string $t): string => (string) callPrivate(LinkedInPublisher::class, 'escape', [$t]);
check('LinkedIn keeps #hashtags', $esc('#launch day') === '#launch day');
check('LinkedIn escapes parentheses and underscores', $esc('Offer (today)_only') === 'Offer \(today\)\_only');
check('LinkedIn escapes backslashes', $esc('a\\b') === 'a\\\\b');

// ---- media readiness tips (advisory)
$check = static fn (array $f): array => callPrivate(SocialLibraryService::class, 'check', [$f]);
$video = ['mime' => 'video/mp4', 'size' => 5_000_000, 'width' => 1080, 'height' => 1920, 'duration_sec' => 30.0];
check('vertical 30s mp4 is Reel ready', $check($video)['reel_ready'] === true);
check('landscape video gets a tip', $check(['width' => 1920, 'height' => 1080] + $video)['reel_ready'] === false);
check('video over 90s gets a tip', $check(['duration_sec' => 120.0] + $video)['reel_ready'] === false);
check('video under 3s gets a tip', $check(['duration_sec' => 2.0] + $video)['reel_ready'] === false);
check('unusual format gets a tip', $check(['mime' => 'video/webm'] + $video)['reel_ready'] === false);
$img = ['mime' => 'image/jpeg', 'size' => 200_000, 'width' => 1080, 'height' => 1080, 'duration_sec' => null];
check('image is never "Reel ready" but has no tips', $check($img)['reel_ready'] === false && $check($img)['issues'] === []);
check('file over 1 GB gets a tip', $check(['size' => 2_000_000_000] + $img)['issues'] !== []);

echo "\nPassed: {$pass}  Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
