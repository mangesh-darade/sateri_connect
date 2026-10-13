<?php

/**
 * Smart form fields always include a mobile number and do not repeat keys.
 */

define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Libraries\SmartFormService;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok): void
{
    global $pass, $fail;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
}

$fields = SmartFormService::normalizeFields([
    ['label' => 'City', 'type' => 'text', 'key' => 'City Name', 'required' => true],
    ['label' => 'Again', 'type' => 'text', 'key' => 'city_name'],
]);

$keys = array_column($fields, 'key');
check('mobile is added when missing', in_array('mobile', $keys, true));
check('text key is slug', in_array('city_name', $keys, true));
check('duplicate key is dropped', count(array_keys($keys, 'city_name', true)) === 1);

$typed = SmartFormService::normalizeFields([
    ['label' => 'Person', 'type' => 'name', 'key' => 'ignored'],
    ['label' => 'Phone', 'type' => 'mobile', 'key' => 'ignored'],
]);
check('name type uses name key', $typed[0]['key'] === 'name' || $typed[1]['key'] === 'name');
check('mobile type stays required', (static function (array $rows): bool {
    foreach ($rows as $row) {
        if ($row['type'] === 'mobile') {
            return $row['required'] === true;
        }
    }

    return false;
})($typed));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
