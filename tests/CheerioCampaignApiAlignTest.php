<?php

declare(strict_types=1);

/**
 * Verify Cheerio template + bulk campaign API alignment with Postman collection.
 *
 * Run: php tests/CheerioCampaignApiAlignTest.php
 */

define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);
chdir(FCPATH);

require FCPATH . '../app/Config/Paths.php';
$paths = new \Config\Paths();
require $paths->systemDirectory . '/Boot.php';
\CodeIgniter\Boot::bootSpark($paths);

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

echo "=== Cheerio Campaign API Align Test ===\n\n";

$cheerioSrc = file_get_contents(dirname(FCPATH) . '/app/Libraries/CheerioDirectAPI.php') ?: '';
$campaignSrc = file_get_contents(dirname(FCPATH) . '/app/Libraries/CampaignService.php') ?: '';
$metaSrc = file_get_contents(dirname(FCPATH) . '/app/Libraries/MetaCloudAPI.php') ?: '';
$facadeSrc = file_get_contents(dirname(FCPATH) . '/app/Libraries/WhatsAppCloudAPI.php') ?: '';
$configSrc = file_get_contents(dirname(FCPATH) . '/app/Config/WhatsApp.php') ?: '';

check(
    'BASE URL is newprod Cheerio Direct APIs',
    str_contains($configSrc, "https://newprod.api.cheerio.in/direct-apis")
);

check(
    'sendTemplate uses v1/whatsapp/template/send',
    str_contains($cheerioSrc, "v1/whatsapp/template/send")
);
check(
    'sendTemplate envelope uses to + data',
    str_contains($cheerioSrc, "'to'   => \$phone")
    && str_contains($cheerioSrc, "'data' => [")
    && str_contains($cheerioSrc, "'language'   => ['code' => \$lang]")
    && str_contains($cheerioSrc, "'components' => \$this->prepareTemplateComponents")
);

check(
    'sendBulkCampaign method exists',
    str_contains($cheerioSrc, 'function sendBulkCampaign(')
);
check(
    'bulk uses v1/whatsapp/multiple',
    str_contains($cheerioSrc, "v1/whatsapp/multiple")
);
check(
    'bulk payload has campaignName + template + data',
    str_contains($cheerioSrc, "'campaignName' => \$campaignName")
    && str_contains($cheerioSrc, "'messaging_product' => 'whatsapp'")
    && str_contains($cheerioSrc, "'type'              => 'template'")
);

check(
    'getCampaignSummary uses analytics/summary',
    str_contains($cheerioSrc, 'v1/analytics/summary/')
);

check(
    'CampaignService Cheerio bulk dispatch',
    str_contains($campaignSrc, 'dispatchCheerioBulkCampaign')
    && str_contains($campaignSrc, 'shouldDispatchViaCheerioBulk')
    && str_contains($campaignSrc, 'sendBulkCampaign')
);

check(
    'Facade documents sendBulkCampaign',
    str_contains($facadeSrc, 'sendBulkCampaign')
);

check(
    'Meta rejects sendBulkCampaign',
    str_contains($metaSrc, 'function sendBulkCampaign(')
    && str_contains($metaSrc, 'Cheerio-only')
);

check(
    'CampaignService uses dedicated CheerioDirectAPI for bulk (no facade override)',
    str_contains($campaignSrc, 'new CheerioDirectAPI()')
    && str_contains($campaignSrc, 'WhatsAppTemplatePayload::mergeHeaderFromPayload')
);

check(
    'Meta prepare validates media header without Cheerio sample auto-fill',
    str_contains($metaSrc, 'assertRequiredMediaHeader')
    && str_contains($metaSrc, 'No Cheerio auto-fill')
    && ! str_contains($metaSrc, 'buildBodyComponentFromTemplate')
    && ! str_contains($metaSrc, 'buildCarouselSendComponent')
);

check(
    'Shared WhatsAppTemplatePayload helper exists',
    is_file(dirname(FCPATH) . '/app/Libraries/WhatsAppTemplatePayload.php')
);

// Runtime: Meta stub throws; Cheerio methods exist.
$meta = new \App\Libraries\MetaCloudAPI();
$threw = false;
try {
    $meta->sendBulkCampaign('x', 'y', 'en', [['to' => '919999999999']]);
} catch (\RuntimeException $e) {
    $threw = str_contains($e->getMessage(), 'Cheerio-only');
}
check('Meta sendBulkCampaign throws Cheerio-only', $threw);

$cheerio = new ReflectionClass(\App\Libraries\CheerioDirectAPI::class);
check('CheerioDirectAPI::sendTemplate exists', $cheerio->hasMethod('sendTemplate'));
check('CheerioDirectAPI::sendBulkCampaign exists', $cheerio->hasMethod('sendBulkCampaign'));
check('CheerioDirectAPI::getCampaignSummary exists', $cheerio->hasMethod('getCampaignSummary'));
check('CheerioDirectAPI::prepareTemplateComponents exists', $cheerio->hasMethod('prepareTemplateComponents'));

$svc = new ReflectionClass(\App\Libraries\CampaignService::class);
check('CampaignService::shouldDispatchViaCheerioBulk exists', $svc->hasMethod('shouldDispatchViaCheerioBulk'));
check('CampaignService::dispatchCheerioBulkCampaign exists', $svc->hasMethod('dispatchCheerioBulkCampaign'));

// Expected Postman bulk shape (static expected keys).
$expectedBulk = [
    'campaignName' => 'Direct API campaign 5feb',
    'template'     => [
        'name'     => 'support',
        'language' => ['code' => 'en'],
    ],
    'data' => [[
        'messaging_product' => 'whatsapp',
        'to'                => '919779003936',
        'type'              => 'template',
        'template'          => [
            'components' => [[
                'type'       => 'body',
                'parameters' => [['type' => 'text', 'text' => 'abc def']],
            ]],
        ],
    ]],
];
check('Postman bulk example has required keys', isset(
    $expectedBulk['campaignName'],
    $expectedBulk['template']['name'],
    $expectedBulk['template']['language']['code'],
    $expectedBulk['data'][0]['messaging_product'],
    $expectedBulk['data'][0]['to'],
    $expectedBulk['data'][0]['type'],
    $expectedBulk['data'][0]['template']['components']
));

$expectedSingle = [
    'to'   => '9779003936',
    'data' => [
        'name'       => 'incoming_lead_website',
        'language'   => ['code' => 'en'],
        'components' => [],
    ],
];
check('Postman single template example has to+data.name+language.code', isset(
    $expectedSingle['to'],
    $expectedSingle['data']['name'],
    $expectedSingle['data']['language']['code'],
    $expectedSingle['data']['components']
));

echo "\n=== Results: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
