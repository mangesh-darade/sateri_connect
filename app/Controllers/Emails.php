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
        $tagModel = model(TagModel::class);
        $data['tags'] = $tagModel->orderBy('name', 'ASC')->findAll(200);
        $data['customerGroups'] = $tagModel->listWithContactCounts();

        $contacts = model(ContactModel::class)
            ->select('id, name, email')
            ->where('email !=', '')
            ->where('email IS NOT NULL', null, false)
            ->orderBy('name', 'ASC')
            ->findAll(500);

        try {
            $contactTags = db_connect()->table('contact_tags')
                ->select('contact_id, tag_id')
                ->get()
                ->getResultArray();
            $tagMap = [];
            foreach ($contactTags as $ct) {
                $tagMap[(int) $ct['contact_id']][] = (int) $ct['tag_id'];
            }
            foreach ($contacts as &$c) {
                $c['tag_ids'] = $tagMap[(int) $c['id']] ?? [];
            }
            unset($c);
        } catch (\Throwable) {
            // ignore
        }

        $data['contactsWithEmail'] = $contacts;
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
                $errors['label_name'] = 'Label or Customer Group name is required.';
            }
            $provider = (new SettingsService())->getEmailProvider();
            if ($provider !== SettingsService::EMAIL_PROVIDER_CHEERIO) {
                // For non-Cheerio providers (Amazon SES, SMTP, SendGrid), resolve group contacts directly
                $tagRow = model(TagModel::class)->where('name', $labelName)->first();
                if ($tagRow) {
                    $groupContacts = model(ContactModel::class)
                        ->select('contacts.email')
                        ->join('contact_tags', 'contact_tags.contact_id = contacts.id')
                        ->where('contact_tags.tag_id', (int) $tagRow['id'])
                        ->where('contacts.email !=', '')
                        ->where('contacts.email IS NOT NULL', null, false)
                        ->findAll();
                    foreach ($groupContacts as $row) {
                        $email = strtolower(trim((string) ($row['email'] ?? '')));
                        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            $recipients[] = $email;
                        }
                    }
                    $recipients = array_values(array_unique($recipients));
                }
                if ($recipients === []) {
                    $errors['label_name'] = 'No contacts with valid email found in group/label "' . $labelName . '".';
                } elseif (count($recipients) > self::MAX_BULK_RECIPIENTS) {
                    $errors['label_name'] = 'Maximum ' . self::MAX_BULK_RECIPIENTS . ' recipients allowed (found ' . count($recipients) . ').';
                }
            }
        } else {
            $recipients = $this->resolveBulkRecipients($input);
            if ($recipients === []) {
                $errors['recipients'] = 'Add at least one valid recipient email (paste list, select contacts, or load a group).';
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
            'isSes'           => $provider === SettingsService::EMAIL_PROVIDER_SES,
        ];
    }

    protected function providerDetail(SettingsService $settings, string $provider): string
    {
        return match ($provider) {
            SettingsService::EMAIL_PROVIDER_CHEERIO => 'Uses your Cheerio API key. Sender ID must be verified in Cheerio.',
            SettingsService::EMAIL_PROVIDER_SENDGRID => trim(
                'From: ' . ((string) $settings->get('sendgrid_from_email', '') ?: 'not set')
            ),
            SettingsService::EMAIL_PROVIDER_SES => trim(
                sprintf('AWS Region: %s · From: %s', (string) $settings->get('ses_region', 'ap-south-1'), (string) $settings->get('ses_from_email', '') ?: 'not set')
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
            (string) $settings->get('ses_from_email', ''),
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
            SettingsService::EMAIL_PROVIDER_SES      => 'Amazon SES',
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

        $groupId = (int) ($input['group_id'] ?? 0);
        if ($groupId > 0) {
            try {
                $groupRows = model(ContactModel::class)
                    ->select('contacts.email')
                    ->join('contact_tags', 'contact_tags.contact_id = contacts.id')
                    ->where('contact_tags.tag_id', $groupId)
                    ->where('contacts.email !=', '')
                    ->where('contacts.email IS NOT NULL', null, false)
                    ->findAll();
                foreach ($groupRows as $row) {
                    $email = strtolower(trim((string) ($row['email'] ?? '')));
                    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $emails[] = $email;
                    }
                }
            } catch (\Throwable) {
                // ignore
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

    /**
     * Public tracking pixel: serves 1x1 transparent GIF and records open.
     */
    public function trackOpen(int $id): ResponseInterface
    {
        if ($id > 0) {
            try {
                model(EmailLogModel::class)->recordOpen($id);
            } catch (\Throwable) {
                // non-fatal
            }
        }

        // 1x1 43-byte transparent GIF
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

        return $this->response
            ->setContentType('image/gif')
            ->setHeader('Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0')
            ->setHeader('Pragma', 'no-cache')
            ->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT')
            ->setBody($gif);
    }

    /**
     * Link click redirector: records click and 302 redirects to target.
     */
    public function trackClick(int $id): ResponseInterface
    {
        $url = trim((string) $this->request->getGet('url'));

        if ($id > 0) {
            try {
                model(EmailLogModel::class)->recordClick($id);
            } catch (\Throwable) {
                // non-fatal
            }
        }

        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            $url = site_url();
        }

        return redirect()->to($url);
    }

    /**
     * Public 1-Click Unsubscribe endpoint.
     */
    public function unsubscribe(): ResponseInterface|string
    {
        $email      = strtolower(trim((string) ($this->request->getGet('email') ?: $this->request->getPost('email'))));
        $campaignId = (int) ($this->request->getGet('cid') ?: $this->request->getPost('cid') ?: 0);
        $reason     = trim((string) ($this->request->getPost('reason') ?: 'Direct unsubscribe request'));

        $success = false;
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $ip      = (string) $this->request->getIPAddress();
            $success = model(\App\Models\EmailUnsubscribeModel::class)->recordUnsubscribe($email, $campaignId ?: null, $reason, $ip);
        }

        if ($this->request->isAJAX() || $this->request->getHeaderLine('Accept') === 'application/json') {
            return $this->response->setJSON([
                'status'  => $success ? 'success' : 'error',
                'message' => $success ? 'You have been successfully unsubscribed.' : 'Please provide a valid email.',
            ]);
        }

        // Standalone clean HTML page
        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Unsubscribed</title>' .
            '<meta name="viewport" content="width=device-width, initial-scale=1">' .
            '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">' .
            '</head><body class="bg-light d-flex align-items-center min-vh-100">' .
            '<div class="container" style="max-width: 520px;">' .
            '<div class="card shadow-sm border-0 rounded-4 text-center p-4 p-md-5 bg-white">' .
            '<div class="mb-3 text-success"><svg width="64" height="64" fill="currentColor" class="bi bi-check-circle-fill" viewBox="0 0 16 16"><path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zm-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/></svg></div>' .
            '<h3 class="fw-bold mb-2">Unsubscribed Successfully</h3>' .
            '<p class="text-muted mb-4">' . ($email !== '' ? '<strong>' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</strong> will no longer receive marketing emails from this sender.' : 'Your email has been removed from future marketing lists.') . '</p>' .
            '<p class="small text-muted mb-0">You can close this tab safely.</p>' .
            '</div></div></body></html>';
    }
}
