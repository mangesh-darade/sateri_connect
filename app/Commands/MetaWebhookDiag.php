<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\MetaCloudAPI;
use App\Libraries\SettingsService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class MetaWebhookDiag extends BaseCommand
{
    protected $group       = 'WhatsApp';
    protected $name        = 'whatsapp:webhook-fix';
    protected $description = 'Subscribe WABA to app webhook with override callback URI.';

    public function run(array $params)
    {
        $settings = new SettingsService();
        $meta     = $settings->getMetaConfig();
        $api      = new MetaCloudAPI($settings);
        $waba     = (string) ($meta['waba_id'] ?? '');
        $base     = rtrim((string) $settings->get('webhook_public_base', ''), '/');
        $callback = $base . '/whstapp/public/webhooks';
        $verify   = (string) ($meta['verify_token'] ?? '');

        if ($waba === '' || $base === '' || $verify === '') {
            CLI::error('Need waba_id, webhook_public_base, meta verify token.');

            return;
        }

        CLI::write('app callback override → ' . $callback);
        CLI::write('verify_token set len=' . strlen($verify));

        // Meta: pin WABA webhooks to our public HTTPS URL (required for Cloud API inbound).
        $result = $api->request('POST', $waba . '/subscribed_apps', [
            'override_callback_uri' => $callback,
            'verify_token'          => $verify,
        ]);
        CLI::write(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $subs = $api->request('GET', $waba . '/subscribed_apps');
        CLI::write('--- subscribed_apps ---');
        CLI::write(json_encode($subs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
