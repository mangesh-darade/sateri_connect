<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\GoogleDriveService;
use App\Libraries\SettingsService;
use App\Libraries\SocialCredentials;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Throwable;

/**
 * Social Scheduler → Setup: enter app credentials and see what is ready, without editing .env.
 */
class SocialSetup extends BaseController
{
    public function index(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('social.accounts')) {
            return $denied;
        }

        $creds = new SocialCredentials();
        $drive = new GoogleDriveService();
        $last  = (string) (new SettingsService())->get('social_last_publish_at', '');
        $host  = (string) parse_url(site_url(), PHP_URL_HOST);

        $values = [];
        $saved  = [];
        foreach (SocialCredentials::names() as $name) {
            $saved[$name]  = $creds->get($name) !== '' || ($name === 'drive_service_account' && $drive->isConfigured());
            $values[$name] = SocialCredentials::isSecret($name) ? '' : $creds->get($name);
        }

        return view('social/setup', [
            'pageTitle'    => 'Social Setup',
            'values'       => $values,
            'saved'        => $saved,
            'ready'        => $creds->readiness(),
            'redirectBase' => site_url('social/accounts/callback'),
            'serviceEmail' => $drive->serviceAccountEmail(),
            'lastPublish'  => $last,
            'aiReady'      => (new \App\Libraries\SocialAiClient())->isConfigured(),
            'aiProvider'   => (new \App\Libraries\SocialAiClient())->provider(),
            'cronOk'       => $last !== '' && strtotime($last) > time() - 300,
            'publicUrl'    => ! in_array($host, ['localhost', '127.0.0.1', '::1'], true) && str_starts_with(site_url(), 'https://'),
            'cronCommand'  => 'php ' . ROOTPATH . 'spark social:publish',
            'defaultPageId' => (string) env('social.metaPageId', ''),
            'hasSavedYouTubeToken' => (string) env('social.youtubeRefreshToken', '') !== '',
        ]);
    }

    public function save(): ResponseInterface
    {
        if ($denied = $this->requirePermission('social.accounts')) {
            return $denied;
        }

        $creds   = new SocialCredentials();
        $changed = [];
        foreach (SocialCredentials::names() as $name) {
            $value = trim((string) $this->request->getPost($name));
            // An empty secret box means "keep what is saved"; plain fields can be cleared.
            if ($value === '' && SocialCredentials::isSecret($name)) {
                continue;
            }
            if ($name === 'drive_service_account') {
                $json = json_decode($value, true);
                if (! is_array($json) || empty($json['client_email']) || empty($json['private_key'])) {
                    return $this->jsonResponse(false, null, 'That is not a valid Google service account key file.', [], 422);
                }
            }
            if ($name === 'ai_caption_provider' && $value !== '' && ! isset(\App\Libraries\SocialAiClient::PROVIDERS[$value])) {
                return $this->jsonResponse(false, null, 'Choose Gemini, Claude or OpenAI for the caption AI.', [], 422);
            }
            if (strlen($value) > 8000) {
                return $this->jsonResponse(false, null, 'One of the values is too long.', [], 422);
            }
            if ($creds->set($name, $value)) {
                $changed[] = $name;
            }
        }
        // Names only: never log the values.
        log_activity('social_setup_saved', 'social', 'Updated social scheduler setup', ['fields' => $changed]);

        return $this->jsonResponse(true, ['ready' => $creds->readiness()], 'Setup saved.');
    }

    public function check(string $what = ''): ResponseInterface
    {
        if ($denied = $this->requirePermission('social.accounts')) {
            return $denied;
        }

        $creds = new SocialCredentials();

        if ($what === 'ai') {
            $r = (new \App\Libraries\SocialCaptionService())->test();

            return $r['ok']
                ? $this->jsonResponse(true, null, $r['message'])
                : $this->jsonResponse(false, null, (string) $r['message'], [], (int) ($r['status'] ?: 422));
        }

        if ($what === 'drive') {
            $drive = new GoogleDriveService();
            if (! $drive->isConfigured()) {
                return $this->jsonResponse(false, null, 'Add the Google service account key first.', [], 422);
            }

            return $drive->canAuthenticate()
                ? $this->jsonResponse(true, null, 'Google Drive login works. Share folders with ' . $drive->serviceAccountEmail() . '.')
                : $this->jsonResponse(false, null, 'Google rejected the service account key. Check that the Drive API is enabled.', [], 422);
        }

        if ($what === 'meta') {
            $id     = $creds->get('meta_app_id');
            $secret = $creds->get('meta_app_secret');
            if ($id === '' || $secret === '') {
                return $this->jsonResponse(false, null, 'Save the Meta App ID and App Secret first.', [], 422);
            }
            try {
                $client = Services::curlrequest(['timeout' => 20, 'http_errors' => false], null, null, false);
                $res    = $client->get('https://graph.facebook.com/v21.0/' . rawurlencode($id), ['query' => ['fields' => 'name', 'access_token' => $id . '|' . $secret]]);
                $body   = json_decode((string) $res->getBody(), true);
            } catch (Throwable $e) {
                log_message('error', 'SocialSetup::check meta ' . $e::class);

                return $this->jsonResponse(false, null, 'Could not reach Meta. Please try again.', [], 502);
            }
            if ($res->getStatusCode() === 200 && ! empty($body['name'])) {
                return $this->jsonResponse(true, null, 'Meta app found: ' . $body['name'] . '.');
            }

            return $this->jsonResponse(false, null, 'Meta rejected this App ID / App Secret pair.', [], 422);
        }

        return $this->jsonResponse(false, null, 'Unknown check.', [], 404);
    }
}
