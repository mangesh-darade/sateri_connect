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
    protected $description = 'Pin WABA webhook + ensure Meta App messages field is subscribed.';

    public function run(array $params)
    {
        $settings = new SettingsService();
        $meta     = $settings->getMetaConfig();
        $api      = new MetaCloudAPI($settings);
        $waba     = (string) ($meta['waba_id'] ?? '');
        $base     = rtrim((string) $settings->get('webhook_public_base', ''), '/');
        $localPath = parse_url(site_url('webhooks'), PHP_URL_PATH) ?: '/webhooks';
        $callback = trim((string) ($params[0] ?? ''));
        if ($callback === '') {
            $callback = $base . $localPath;
        }
        $verify = trim((string) ($params[1] ?? ''));
        if ($verify === '') {
            $verify = (string) ($meta['verify_token'] ?? '');
        }

        if ($waba === '' || $callback === '' || $verify === '') {
            CLI::error('Need waba_id, callback URL (or webhook_public_base), and meta verify token.');
            CLI::write('Usage: php spark whatsapp:webhook-fix [callbackUrl] [verifyToken]');

            return;
        }

        CLI::write('callback → ' . $callback);
        CLI::write('app_secret set=' . (trim((string) ($meta['app_secret'] ?? '')) !== '' ? 'yes' : 'NO'));
        CLI::write('app_id=' . (string) ($meta['app_id'] ?? '(empty)'));

        try {
            $result = $api->subscribeWabaWebhook($callback, $verify);
            CLI::write(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $fields = $result['webhook_fields'] ?? null;
            if (is_array($fields) && empty($fields['messages_subscribed'])) {
                CLI::error('messages field NOT subscribed: ' . (string) ($fields['error'] ?? $fields['detail'] ?? ''));
            } elseif (is_array($fields) && ! empty($fields['auto_fixed'])) {
                CLI::write('Auto-fixed: subscribed messages field.');
            }
        } catch (\Throwable $e) {
            CLI::error($e->getMessage());
        }
    }
}
