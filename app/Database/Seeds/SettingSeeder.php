<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        $settings = [
            [
                'key'          => 'whatsapp_provider',
                'value'        => 'cheerio',
                'group'        => 'whatsapp',
                'is_encrypted' => 0,
            ],
            [
                'key'          => 'cheerio_api_key',
                'value'        => '',
                'group'        => 'cheerio',
                'is_encrypted' => 1,
            ],
            [
                'key'          => 'cheerio_webhook_verify_token',
                'value'        => '',
                'group'        => 'cheerio',
                'is_encrypted' => 0,
            ],
            [
                'key'          => 'cheerio_webhook_secret',
                'value'        => '',
                'group'        => 'cheerio',
                'is_encrypted' => 1,
            ],
            [
                'key'          => 'meta_access_token',
                'value'        => '',
                'group'        => 'meta',
                'is_encrypted' => 1,
            ],
            [
                'key'          => 'meta_phone_number_id',
                'value'        => '',
                'group'        => 'meta',
                'is_encrypted' => 0,
            ],
            [
                'key'          => 'meta_waba_id',
                'value'        => '',
                'group'        => 'meta',
                'is_encrypted' => 0,
            ],
            [
                'key'          => 'meta_api_version',
                'value'        => 'v21.0',
                'group'        => 'meta',
                'is_encrypted' => 0,
            ],
            [
                'key'          => 'meta_webhook_verify_token',
                'value'        => '',
                'group'        => 'meta',
                'is_encrypted' => 0,
            ],
            [
                'key'          => 'meta_webhook_secret',
                'value'        => '',
                'group'        => 'meta',
                'is_encrypted' => 1,
            ],
            [
                'key'          => 'app_name',
                'value'        => 'WhatsApp Automation Platform',
                'group'        => 'general',
                'is_encrypted' => 0,
            ],
            [
                'key'          => 'app_tagline',
                'value'        => 'Automation console',
                'group'        => 'general',
                'is_encrypted' => 0,
            ],
            [
                'key'          => 'site_logo',
                'value'        => '',
                'group'        => 'general',
                'is_encrypted' => 0,
            ],
            [
                'key'          => 'site_favicon',
                'value'        => '',
                'group'        => 'general',
                'is_encrypted' => 0,
            ],
            [
                'key'          => 'timezone',
                'value'        => 'UTC',
                'group'        => 'general',
                'is_encrypted' => 0,
            ],
            [
                'key'          => 'smtp_host',
                'value'        => '',
                'group'        => 'smtp',
                'is_encrypted' => 0,
            ],
            [
                'key'          => 'smtp_port',
                'value'        => '587',
                'group'        => 'smtp',
                'is_encrypted' => 0,
            ],
            [
                'key'          => 'smtp_user',
                'value'        => '',
                'group'        => 'smtp',
                'is_encrypted' => 0,
            ],
            [
                'key'          => 'smtp_pass',
                'value'        => '',
                'group'        => 'smtp',
                'is_encrypted' => 1,
            ],
            [
                'key'          => 'smtp_encryption',
                'value'        => 'tls',
                'group'        => 'smtp',
                'is_encrypted' => 0,
            ],
            [
                'key'          => 'smtp_from_email',
                'value'        => '',
                'group'        => 'smtp',
                'is_encrypted' => 0,
            ],
            [
                'key'          => 'smtp_from_name',
                'value'        => 'WhatsApp Automation Platform',
                'group'        => 'smtp',
                'is_encrypted' => 0,
            ],
            [
                'key'          => 'email_provider',
                'value'        => 'smtp',
                'group'        => 'email',
                'is_encrypted' => 0,
            ],
            [
                'key'          => 'sendgrid_api_key',
                'value'        => '',
                'group'        => 'email',
                'is_encrypted' => 1,
            ],
            [
                'key'          => 'sendgrid_from_email',
                'value'        => '',
                'group'        => 'email',
                'is_encrypted' => 0,
            ],
            [
                'key'          => 'sendgrid_from_name',
                'value'        => 'WhatsApp Automation Platform',
                'group'        => 'email',
                'is_encrypted' => 0,
            ],
            [
                'key'          => 'sendgrid_sender_id',
                'value'        => '',
                'group'        => 'email',
                'is_encrypted' => 0,
            ],
            [
                'key'          => 'sendgrid_suppression_group_id',
                'value'        => '',
                'group'        => 'email',
                'is_encrypted' => 0,
            ],
            [
                'key'          => 'sendgrid_custom_unsubscribe_url',
                'value'        => '',
                'group'        => 'email',
                'is_encrypted' => 0,
            ],
            [
                'key'          => 'sendgrid_ip_pool',
                'value'        => '',
                'group'        => 'email',
                'is_encrypted' => 0,
            ],
            [
                'key'          => 'cheerio_email_campaign_name',
                'value'        => 'app-direct',
                'group'        => 'email',
                'is_encrypted' => 0,
            ],
            [
                'key'          => 'app_installed',
                'value'        => '0',
                'group'        => 'general',
                'is_encrypted' => 0,
            ],
        ];

        foreach ($settings as &$setting) {
            $setting['created_at'] = $now;
            $setting['updated_at'] = $now;
        }
        unset($setting);

        foreach ($settings as $setting) {
            $exists = $this->db->table('settings')->where('key', $setting['key'])->countAllResults();
            if ($exists === 0) {
                $this->db->table('settings')->insert($setting);
            }
        }
    }
}
