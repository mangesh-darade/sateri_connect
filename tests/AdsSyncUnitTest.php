<?php

/**
 * Pure-logic checks for AdsSyncService: Meta insights parsing and status mapping.
 * No database and no network.
 *
 * Run: php tests/AdsSyncUnitTest.php
 */

define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Libraries\AdsSyncService;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok): void
{
    global $pass, $fail;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
}

$m = AdsSyncService::parseInsightsRow([
    'date_start'  => '2026-11-02',
    'spend'       => '123.45',
    'impressions' => '9000',
    'reach'       => '7000',
    'clicks'      => '210',
    'actions'     => [
        ['action_type' => 'link_click', 'value' => '200'],
        ['action_type' => 'onsite_conversion.messaging_conversation_started_7d', 'value' => '5'],
    ],
]);
check('spend is converted to minor units', $m['spend_minor'] === 12345);
check('only message conversations are counted', $m['conversations'] === 5);
check('cost per conversation = spend / chats', $m['cost_per_conversation_minor'] === 2469);
check('impressions, reach, clicks read', $m['impressions'] === 9000 && $m['reach'] === 7000 && $m['clicks'] === 210);
check('date kept', $m['metric_date'] === '2026-11-02');

$m = AdsSyncService::parseInsightsRow(['date_start' => '2026-11-03', 'spend' => '50.00']);
check('no actions means no chats and no cost per chat', $m['conversations'] === 0 && $m['cost_per_conversation_minor'] === 0);
check('missing numbers become zero', $m['impressions'] === 0 && $m['reach'] === 0);
check('negative spend is clamped to zero', AdsSyncService::parseInsightsRow(['date_start' => '2026-11-03', 'spend' => '-5'])['spend_minor'] === 0);
check('row without a date is skipped', AdsSyncService::parseInsightsRow(['spend' => '10']) === null);
check('bad date is skipped', AdsSyncService::parseInsightsRow(['date_start' => 'tomorrow', 'spend' => '10']) === null);

check('ACTIVE maps to active', AdsSyncService::mapEffectiveStatus('ACTIVE') === 'active');
check('paused variants map to paused', AdsSyncService::mapEffectiveStatus('CAMPAIGN_PAUSED') === 'paused' && AdsSyncService::mapEffectiveStatus('adset_paused') === 'paused');
check('disapproved maps to rejected', AdsSyncService::mapEffectiveStatus('DISAPPROVED') === 'rejected' && AdsSyncService::mapEffectiveStatus('WITH_ISSUES') === 'rejected');
check('archived maps to ended', AdsSyncService::mapEffectiveStatus('ARCHIVED') === 'ended');
check('in review leaves the status alone', AdsSyncService::mapEffectiveStatus('PENDING_REVIEW') === null && AdsSyncService::mapEffectiveStatus('') === null);

echo PHP_EOL . "$pass passed, $fail failed" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
