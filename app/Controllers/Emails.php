<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\ActivityLogger;
use App\Libraries\SettingsService;
use App\Models\CampaignModel;
use App\Models\ContactModel;
use App\Models\EmailLogModel;
use App\Models\TagModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Outbound email compose — single + bulk via active email provider.
 */
class Emails extends BaseController
{
    protected const MAX_BULK_RECIPIENTS = 100;

    public function index(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('emails.view')) {
            return $denied;
        }

        // Hub lives in Email Manager (tabs: builder, drips, verifier, campaigns, senders).
        return redirect()->to(site_url('email-manager'));
    }

    public function single(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('emails.send')) {
            return $denied;
        }

        return $this->render('emails/single', $this->composeCommonData('Send email'));
    }

    public function bulk(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('emails.send')) {
            return $denied;
        }

        $data = $this->composeCommonData('Bulk email');
        $data['tags'] = model(TagModel::class)->orderBy('name', 'ASC')->findAll(200);
        $data['contactsWithEmail'] = model(ContactModel::class)
            ->select('id, name, email')
            ->where('email !=', '')
            ->where('email IS NOT NULL', null, false)
            ->orderBy('name', 'ASC')
            ->findAll(300);
        $data['maxRecipients'] = self::MAX_BULK_RECIPIENTS;

        return $this->render('emails/bulk', $data);
    }

    public function sendSingle(): ResponseInterface
    {
        if ($denied = $this->requirePermission('emails.send')) {
            return $denied;
        }

        $input   = $this->requestInput();
        $to      = trim((string) ($input['to'] ?? ''));
        $subject = trim((string) ($input['subject'] ?? ''));
        $body    = (string) ($input['body'] ?? '');
        $isHtml  = ! empty($input['is_html']);
        $campaignName = trim((string) ($input['campaign_name'] ?? ''));

        $errors = [];
        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $errors['to'] = 'A valid recipient email is required.';
        }
        if ($subject === '') {
            $errors['subject'] = 'Subject is required.';
        }
        if (trim(strip_tags($body)) === '') {
            $errors['body'] = 'Message body is required.';
        }

        if ($errors !== []) {
            return $this->jsonResponse(false, null, 'Please fix the form errors.', $errors, 422);
        }

        $options = [];
        if ($campaignName !== '') {
            $options['campaign_name'] = $campaignName;
            // Persist latest campaign label as default for next sends.
            (new SettingsService())->setCheerioEmailConfig(['default_campaign' => $campaignName]);
        }

        try {
            $mailer = service('emailProvider');
            $result = $isHtml
                ? $mailer->sendHtml($to, $subject, $body, $options)
                : $mailer->send($to, $subject, $body, $options);

            $ok = (bool) ($result['ok'] ?? false);
            $this->logSend($ok, 'single', $to, $subject, $result);

            return $this->jsonResponse(
                $ok,
                $result,
                $ok ? (string) ($result['message'] ?? 'Email sent.') : (string) ($result['message'] ?? 'Send failed.')
            );
        } catch (\Throwable $e) {
            log_message('error', 'Emails::sendSingle failed: {msg}', ['msg' => $e->getMessage()]);

            return $this->jsonResponse(false, null, $e->getMessage(), [], 500);
        }
    }

    public function sendBulk(): ResponseInterface
    {
        if ($denied = $this->requirePermission('emails.send')) {
            return $denied;
        }

        $input   = $this->requestInput();
        $subject = trim((string) ($input['subject'] ?? ''));
        $body    = (string) ($input['body'] ?? '');
        $isHtml  = ! empty($input['is_html']);
        $mode    = strtolower(trim((string) ($input['mode'] ?? 'recipients')));
        $campaignName = trim((string) ($input['campaign_name'] ?? ''));
        $labelName    = trim((string) ($input['label_name'] ?? ''));

        $errors = [];
        if ($subject === '') {
            $errors['subject'] = 'Subject is required.';
        }
        if (trim(strip_tags($body)) === '') {
            $errors['body'] = 'Message body is required.';
        }

        $recipients = [];
        if ($mode === 'label') {
            if ($labelName === '') {
                $errors['label_name'] = 'Cheerio label name is required for label mode.';
            }
            $provider = (new SettingsService())->getEmailProvider();
            if ($provider !== SettingsService::EMAIL_PROVIDER_CHEERIO) {
                $errors['mode'] = 'Label bulk send is only available when Cheerio Email is the active provider.';
            }
        } else {
            $recipients = $this->resolveBulkRecipients($input);
            if ($recipients === []) {
                $errors['recipients'] = 'Add at least one valid recipient email (paste list and/or select contacts).';
            } elseif (count($recipients) > self::MAX_BULK_RECIPIENTS) {
                $errors['recipients'] = 'Maximum ' . self::MAX_BULK_RECIPIENTS . ' recipients per bulk send.';
            }
        }

        if ($errors !== []) {
            return $this->jsonResponse(false, null, 'Please fix the form errors.', $errors, 422);
        }

        $html = $isHtml
            ? $body
            : nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);

        $campaign = [
            'name'          => $campaignName !== '' ? $campaignName : ('bulk-' . date('Ymd-His')),
            'subject'       => $subject,
            'html'          => $html,
            'campaign_name' => $campaignName !== '' ? $campaignName : null,
        ];
        if ($campaignName !== '') {
            // Persist latest campaign label as default for next sends.
            (new SettingsService())->setCheerioEmailConfig(['default_campaign' => $campaignName]);
        }

        if ($mode === 'label') {
            $campaign['label_name'] = $labelName;
        } else {
            $campaign['recipients'] = $recipients;
        }

        try {
            $mailer = service('emailProvider');
            $result = $mailer->sendCampaign($campaign);
            $ok     = (bool) ($result['ok'] ?? false);
            $target = $mode === 'label' ? ('label:' . $labelName) : implode(', ', array_slice($recipients, 0, 5));
            $this->logSend($ok, 'bulk', $target, $subject, $result);

            return $this->jsonResponse(
                $ok,
                $result,
                $ok ? (string) ($result['message'] ?? 'Bulk email queued/sent.') : (string) ($result['message'] ?? 'Bulk send failed.')
            );
        } catch (\Throwable $e) {
            log_message('error', 'Emails::sendBulk failed: {msg}', ['msg' => $e->getMessage()]);

            return $this->jsonResponse(false, null, $e->getMessage(), [], 500);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function composeCommonData(string $pageTitle): array
    {
        $settings = new SettingsService();
        $provider = $settings->getEmailProvider();

        return [
            'pageTitle'       => $pageTitle,
            'provider'        => $provider,
            'providerLabel'   => $this->providerLabel($provider),
            'providerDetail'  => $this->providerDetail($settings, $provider),
            'defaultTo'       => $this->defaultTestEmail($settings),
            'defaultCampaign' => (string) $settings->get('cheerio_email_campaign_name', 'app-direct'),
            'campaigns'       => model(CampaignModel::class)
                ->select('id, name, status')
                ->orderBy('name', 'ASC')
                ->findAll(200),
            'isCheerio'       => $provider === SettingsService::EMAIL_PROVIDER_CHEERIO,
            'isSendGrid'      => $provider === SettingsService::EMAIL_PROVIDER_SENDGRID,
            'isSmtp'          => $provider === SettingsService::EMAIL_PROVIDER_SMTP,
        ];
    }

    protected function providerDetail(SettingsService $settings, string $provider): string
    {
        return match ($provider) {
            SettingsService::EMAIL_PROVIDER_CHEERIO => 'Uses your Cheerio API key. Sender ID must be verified in Cheerio.',
            SettingsService::EMAIL_PROVIDER_SENDGRID => trim(
                'From: ' . ((string) $settings->get('sendgrid_from_email', '') ?: 'not set')
            ),
            default => trim(sprintf(
                '%s · From: %s',
                (string) $settings->get('smtp_host', 'host not set'),
                (string) $settings->get('smtp_from_email', '') ?: ((string) $settings->get('smtp_user', '') ?: 'not set')
            )),
        };
    }

    protected function defaultTestEmail(SettingsService $settings): string
    {
        $candidates = [
            (string) $settings->get('smtp_from_email', ''),
            (string) $settings->get('smtp_user', ''),
            (string) $settings->get('app_email', ''),
            (string) ($this->currentUser['email'] ?? ''),
            'sateri.mangesh@gmail.com',
        ];

        foreach ($candidates as $email) {
            $email = trim($email);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        }

        return 'sateri.mangesh@gmail.com';
    }

    protected function providerLabel(string $provider): string
    {
        return match ($provider) {
            SettingsService::EMAIL_PROVIDER_SENDGRID => 'SendGrid',
            SettingsService::EMAIL_PROVIDER_CHEERIO  => 'Cheerio Email API',
            default                                  => 'SMTP',
        };
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return list<string>
     */
    protected function resolveBulkRecipients(array $input): array
    {
        $raw = (string) ($input['recipients'] ?? '');
        $emails = [];

        if ($raw !== '') {
            $parts = preg_split('/[\s,;]+/', $raw) ?: [];
            foreach ($parts as $part) {
                $part = trim($part);
                if ($part !== '' && filter_var($part, FILTER_VALIDATE_EMAIL)) {
                    $emails[] = strtolower($part);
                }
            }
        }

        $contactIds = $input['contact_ids'] ?? [];
        if (is_string($contactIds)) {
            $decoded = json_decode($contactIds, true);
            $contactIds = is_array($decoded) ? $decoded : (preg_split('/[\s,;]+/', $contactIds) ?: []);
        }

        if (is_array($contactIds) && $contactIds !== []) {
            $ids = array_values(array_filter(array_map('intval', $contactIds), static fn (int $id) => $id > 0));
            if ($ids !== []) {
                $rows = model(ContactModel::class)
                    ->select('email')
                    ->whereIn('id', $ids)
                    ->findAll();
                foreach ($rows as $row) {
                    $email = strtolower(trim((string) ($row['email'] ?? '')));
                    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $emails[] = $email;
                    }
                }
            }
        }

        return array_values(array_unique($emails));
    }

    /**
     * @param array<string, mixed> $result
     */
    protected function logSend(bool $ok, string $kind, string $target, string $subject, array $result): void
    {
        try {
            (new ActivityLogger())->log(
                $ok ? 'email_send' : 'email_send_failed',
                'emails',
                sprintf('%s email %s: %s', ucfirst($kind), $ok ? 'sent' : 'failed', $subject),
                [
                    'kind'     => $kind,
                    'target'   => $target,
                    'provider' => $result['provider'] ?? null,
                    'message'  => $result['message'] ?? null,
                ]
            );
        } catch (\Throwable) {
            // non-fatal
        }

        try {
            model(EmailLogModel::class)->record(
                $kind === 'bulk' ? 'bulk' : 'single',
                $ok ? 'sent' : 'failed',
                $subject,
                $target,
                isset($result['provider']) ? (string) $result['provider'] : null,
                isset($result['message']) ? (string) $result['message'] : null,
                ['raw' => $result['data'] ?? null],
                (int) ($this->currentUser['id'] ?? 0) ?: null
            );
        } catch (\Throwable) {
            // non-fatal — table may not exist until migrate
        }
    }
}
