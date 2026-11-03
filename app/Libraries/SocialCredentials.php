<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * App credentials for the social scheduler. Saved per client in Settings (secrets encrypted) from the
 * Social Scheduler → Setup screen; the server .env values are the fallback (one shared Sateri app for every client).
 */
class SocialCredentials
{
    /** name => [settings key, .env key, is secret] */
    private const FIELDS = [
        'meta_app_id'           => ['social_meta_app_id', 'social.metaAppId', false],
        'meta_app_secret'       => ['social_meta_app_secret', 'social.metaAppSecret', true],
        'google_client_id'      => ['social_google_client_id', 'google.oauthClientId', false],
        'google_client_secret'  => ['social_google_client_secret', 'google.oauthClientSecret', true],
        'linkedin_client_id'    => ['social_linkedin_client_id', 'linkedin.clientId', false],
        'linkedin_client_secret' => ['social_linkedin_client_secret', 'linkedin.clientSecret', true],
        'drive_service_account' => ['social_drive_service_account', 'google.serviceAccountFile', true],
        'ai_caption_provider'   => ['social_ai_caption_provider', 'social.aiProvider', false],
        'anthropic_api_key'     => ['social_anthropic_api_key', 'social.anthropicApiKey', true],
        'anthropic_model'       => ['social_anthropic_model', 'social.anthropicModel', false],
        'openai_api_key'        => ['social_openai_api_key', 'social.openaiApiKey', true],
        'openai_model'          => ['social_openai_model', 'social.openaiModel', false],
    ];

    private SettingsService $settings;

    public function __construct(?SettingsService $settings = null)
    {
        $this->settings = $settings ?? new SettingsService();
    }

    public static function names(): array
    {
        return array_keys(self::FIELDS);
    }

    public static function isSecret(string $name): bool
    {
        return (self::FIELDS[$name][2] ?? false) === true;
    }

    /**
     * Saved value, else the .env value, else ''. For drive_service_account the .env value is a file path and is not returned here.
     */
    public function get(string $name): string
    {
        if (! isset(self::FIELDS[$name])) {
            return '';
        }
        [$key, $env] = self::FIELDS[$name];
        $saved = trim((string) $this->settings->get($key, ''));
        if ($saved !== '') {
            return $saved;
        }

        return $name === 'drive_service_account' ? '' : trim((string) env($env, ''));
    }

    public function set(string $name, string $value): bool
    {
        if (! isset(self::FIELDS[$name])) {
            return false;
        }
        [$key, , $secret] = self::FIELDS[$name];

        return $this->settings->set($key, trim($value), 'social', $secret);
    }

    /**
     * Google service account JSON (pasted in Setup), else the key file named in .env.
     *
     * @return array<string, mixed>|null
     */
    public function driveServiceAccount(): ?array
    {
        $json = $this->get('drive_service_account');
        if ($json === '') {
            $file = (string) env('google.serviceAccountFile', '');
            $json = $file !== '' && is_file($file) ? (string) file_get_contents($file) : '';
        }
        $data = json_decode($json, true);

        return is_array($data) && ! empty($data['client_email']) && ! empty($data['private_key']) ? $data : null;
    }

    /**
     * @return array{facebook: bool, youtube: bool, linkedin: bool, drive: bool}
     */
    public function readiness(): array
    {
        return [
            'facebook' => $this->get('meta_app_id') !== '' && $this->get('meta_app_secret') !== '',
            'youtube'  => $this->get('google_client_id') !== '' && $this->get('google_client_secret') !== '',
            'linkedin' => $this->get('linkedin_client_id') !== '' && $this->get('linkedin_client_secret') !== '',
            'drive'    => $this->driveServiceAccount() !== null,
        ];
    }
}
