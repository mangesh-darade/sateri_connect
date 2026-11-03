<?php

/**
 * Pure-logic checks for AdsService: max-spend estimate, Meta targeting and ad set payloads.
 * No database and no network (the launch flow itself needs a tenant DB).
 *
 * Run: php tests/AdsServiceUnitTest.php
 */

define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Libraries\AdsService;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok): void
{
    global $pass, $fail;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
}

check('no end date means no total estimate', AdsService::estimateMaxTotal(['starts_at' => '2026-11-01 00:00:00', 'daily_budget_minor' => 50000]) === null);
check('end before start means no estimate', AdsService::estimateMaxTotal(['starts_at' => '2026-11-05 00:00:00', 'ends_at' => '2026-11-01 00:00:00', 'daily_budget_minor' => 50000]) === null);
check('7 days x daily budget', AdsService::estimateMaxTotal(['starts_at' => '2026-11-01 00:00:00', 'ends_at' => '2026-11-08 00:00:00', 'daily_budget_minor' => 50000]) === 350000);
check('part day counts as a full day', AdsService::estimateMaxTotal(['starts_at' => '2026-11-01 00:00:00', 'ends_at' => '2026-11-01 06:00:00', 'daily_budget_minor' => 10000]) === 10000);

$t = AdsService::buildTargeting(['age_min' => 5, 'age_max' => 99, 'gender' => 'female'], ['IN', 'IN', 'US']);
check('targeting countries de-duplicated', $t['geo_locations']['countries'] === ['IN', 'US']);
check('targeting age clamped', $t['age_min'] === 18 && $t['age_max'] === 65);
check('female maps to Meta gender 2', $t['genders'] === [2]);
check('male maps to Meta gender 1', AdsService::buildTargeting(['gender' => 'male'], ['IN'])['genders'] === [1]);
check('all genders sends no gender filter', ! isset(AdsService::buildTargeting(['gender' => 'all'], ['IN'])['genders']));

$p = AdsService::buildAdSetParams(
    ['name' => 'Diwali', 'daily_budget_minor' => 50000, 'starts_at' => '2026-11-01 00:00:00', 'ends_at' => null],
    '123',
    $t,
    'PAGE1',
    '919999999999'
);
check('ad set is created PAUSED', $p['status'] === 'PAUSED');
check('ad set is a WhatsApp conversations ad', $p['destination_type'] === 'WHATSAPP' && $p['optimization_goal'] === 'CONVERSATIONS');
check('ad set daily budget is in minor units', $p['daily_budget'] === 50000);
check('promoted object carries page and number', json_decode($p['promoted_object'], true) === ['page_id' => 'PAGE1', 'whatsapp_phone_number' => '919999999999']);
check('start time set, no end time without end date', isset($p['start_time']) && ! isset($p['end_time']));

echo PHP_EOL . "$pass passed, $fail failed" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
