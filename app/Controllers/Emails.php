<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\ActivityLogger;
use App\Libraries\EmailLinkSigner;
use App\Libraries\EmailReputationGuard;
use App\Libraries\EmailSuppressionService;
use App\Libraries\EmailTracking;
use App\Libraries\SettingsService;
use App\Models\CampaignModel;
use App\Models\ContactModel;
use App\Models\EmailBuilderModel;
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

        $suppressed = (new EmailSuppressionService())->reasonFor($to);
        if ($suppressed !== null) {
            $message = sprintf('Skipped: %s %s, so this email was not sent.', $to, EmailSuppressionService::reasonLabel($suppressed));

            return $this->jsonResponse(false, ['skipped' => [strtolower($to) => $suppressed]], $message, ['to' => $message], 422);
        }

        $options = [];
        if ($campaignName !== '') {
            $options['campaign_name'] = $campaignName;
            // Persist latest campaign label as default for next sends.
            (new SettingsService())->setCheerioEmailConfig(['default_campaign' => $campaignName]);
        }

        $attFile = $this->request->getFile('attachment');
        if ($attFile !== null && $attFile->isValid() && ! $attFile->hasMoved()) {
            $attRes = \App\Libraries\EmailAttachmentHandler::handleUpload($attFile);
            if (! $attRes['ok']) {
                return $this->jsonResponse(false, null, $attRes['error'] ?? 'Attachment error', ['attachment' => $attRes['error']], 422);
            }
            $options['attachments'] = [
                [
                    'path' => $attRes['path'],
                    'name' => $attRes['name'],
                    'mime' => $attRes['mime'],
                ],
            ];
        }

        try {
            $mailer = service('emailProvider');
            $result = $isHtml
                ? $mailer->sendHtml($to, $subject, $body, $options)
                : $mailer->send($to, $subject, $body, $options);

            $ok = (bool) ($result['ok'] ?? false);
            $this->logSend($ok, 'single', $to, $subject, $result, [$to]);

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

        try {
            (new EmailReputationGuard())->assertHealthy();
        } catch (\RuntimeException $e) {
            return $this->jsonResponse(false, null, $e->getMessage(), [], 409);
        }

        $skipped = [];
        if ($recipients !== []) {
            $filter     = (new EmailSuppressionService())->filter($recipients);
            $recipients = $filter['allowed'];
            $skipped    = $filter['skipped'];
            if ($recipients === []) {
                $message = 'Nothing sent: all recipients were skipped — ' . EmailSuppressionService::summary($skipped) . '.';

                return $this->jsonResponse(false, ['skipped' => $skipped], $message, [$mode === 'label' ? 'label_name' : 'recipients' => $message], 422);
            }
        }

        $html = $isHtml
            ? $body
            : nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);

        $perRecipient = (new SettingsService())->getEmailProvider() !== SettingsService::EMAIL_PROVIDER_CHEERIO;
        $unsubUrl     = EmailTracking::unsubscribeUrl(0, $perRecipient);
        $html         = EmailTracking::applyMarketingFooter($html, $unsubUrl);

        $campaign = [
            'name'            => $campaignName !== '' ? $campaignName : ('bulk-' . date('Ymd-His')),
            'subject'         => $subject,
            'html'            => $html,
            'campaign_name'   => $campaignName !== '' ? $campaignName : null,
            'unsubscribe_url' => $unsubUrl,
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

        $attFile = $this->request->getFile('attachment');
        if ($attFile !== null && $attFile->isValid() && ! $attFile->hasMoved()) {
            $attRes = \App\Libraries\EmailAttachmentHandler::handleUpload($attFile);
            if (! $attRes['ok']) {
                return $this->jsonResponse(false, null, $attRes['error'] ?? 'Attachment error', ['attachment' => $attRes['error']], 422);
            }
            $campaign['attachments'] = [
                [
                    'path' => $attRes['path'],
                    'name' => $attRes['name'],
                    'mime' => $attRes['mime'],
                ],
            ];
        }

        try {
            $mailer = service('emailProvider');
            $result = $mailer->sendCampaign($campaign);
            $ok     = (bool) ($result['ok'] ?? false);
            $target = $mode === 'label' ? ('label:' . $labelName) : implode(', ', array_slice($recipients, 0, 5));
            $this->logSend($ok, 'bulk', $target, $subject, $result, $recipients);

            $message = $ok ? (string) ($result['message'] ?? 'Bulk email queued/sent.') : (string) ($result['message'] ?? 'Bulk send failed.');
            if ($skipped !== []) {
                $message .= ' ' . ucfirst(EmailSuppressionService::summary($skipped)) . '.';
                $result['skipped'] = $skipped;
            }

            return $this->jsonResponse($ok, $result, $message);
        } catch (\Throwable $e) {
            log_message('error', 'Emails::sendBulk failed: {msg}', ['msg' => $e->getMessage()]);

            return $this->jsonResponse(false, null, $e->getMessage(), [], 500);
        }
    }

    /**
     * Per-recipient status of one send (sent / failed / delivered / bounced / opened / clicked).
     * GET emails/logs/{id}/recipients
     */
    public function logRecipients(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('emails.view')) {
            return $denied;
        }

        $log = model(EmailLogModel::class)->find($id);
        if (! $log) {
            return $this->jsonResponse(false, null, 'Email log not found.', [], 404);
        }

        $target   = (string) ($log['to_email'] ?? '');
        $fallback = str_starts_with($target, 'label:') ? [] : (preg_split('/\s*,\s*/', $target) ?: []);
        $events   = model(\App\Models\EmailRecipientEventModel::class);
        $tracked  = $events->where('log_id', $id)->whereIn('event_type', ['sent', 'failed'])->countAllResults() > 0;

        // Legacy sends: apply the whole-send outcome when it is unambiguous (all sent / all failed).
        $result = [];
        if (! $tracked) {
            $raw    = json_decode((string) ($log['meta_json'] ?? ''), true)['raw'] ?? [];
            $status = (string) ($log['status'] ?? '');
            if ($status === 'failed') {
                $result = ['send' => 'failed', 'error' => (string) ($log['message'] ?? '')];
            } elseif ($status === 'sent' && empty($raw['failed']) && (int) ($raw['sent'] ?? count($fallback)) >= count($fallback)) {
                $result = ['send' => 'sent'];
            }
        }
        $rows = $events->recipientsForLog($id, $fallback, $result);

        $counts = array_count_values(array_column($rows, 'status'));

        return $this->jsonResponse(true, [
            'log' => [
                'id'         => (int) $log['id'],
                'kind'       => (string) ($log['kind'] ?? ''),
                'subject'    => (string) ($log['subject'] ?? ''),
                'provider'   => (string) ($log['provider'] ?? ''),
                'status'     => (string) ($log['status'] ?? ''),
                'message'    => (string) ($log['message'] ?? ''),
                'created_at' => format_app_datetime($log['created_at'] ?? null),
            ],
            'counts'     => $counts,
            'recipients' => $rows,
            'tracked'    => $tracked,
        ]);
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
            'emailCampaigns'  => model(\App\Models\EmailHtmlCampaignModel::class)
                ->select('id, name, status')
                ->orderBy('id', 'DESC')
                ->findAll(100),
            'emailTemplates'  => model(EmailBuilderModel::class)
                ->select('id, name, subject, html_content')
                ->orderBy('id', 'DESC')
                ->findAll(100),
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
     * @param list<string>         $recipients
     */
    protected function logSend(bool $ok, string $kind, string $target, string $subject, array $result, array $recipients = []): void
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
            $data  = is_array($result['data'] ?? null) ? $result['data'] : [];
            $logId = model(EmailLogModel::class)->record(
                $kind === 'bulk' ? 'bulk' : 'single',
                $ok ? 'sent' : 'failed',
                $subject,
                $target,
                isset($result['provider']) ? (string) $result['provider'] : null,
                isset($result['message']) ? (string) $result['message'] : null,
                ['raw' => array_diff_key($data, ['recipients' => true])],
                (int) ($this->currentUser['id'] ?? 0) ?: null
            );
            model(\App\Models\EmailRecipientEventModel::class)
                ->recordSendResults($logId, null, $recipients, $ok, $data, (string) ($result['message'] ?? ''));
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
            $this->recordRecipientEngagement($id, \App\Models\EmailRecipientEventModel::TYPE_OPEN);
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
     * Link click redirector: only follows http(s) targets signed for this email log (no open redirect).
     */
    public function trackClick(int $id): ResponseInterface
    {
        $url = trim((string) $this->request->getGet('url'));
        $sig = (string) $this->request->getGet('sig');
        $t   = (string) $this->request->getGet('t');

        if ($id <= 0 || ! EmailLinkSigner::isSafeRedirectUrl($url) || ! EmailLinkSigner::verifyClick($id, $url, $sig, $t)) {
            return redirect()->to(site_url());
        }

        $this->recordRecipientEngagement($id, \App\Models\EmailRecipientEventModel::TYPE_CLICK);

        return redirect()->to($url);
    }

    /**
     * Records aggregate + per-recipient engagement for a public tracking hit.
     */
    protected function recordRecipientEngagement(int $logId, string $type): void
    {
        try {
            \App\Libraries\TenantResolver::ensureFromPublicKey((string) $this->request->getGet('t'));

            $logs = model(EmailLogModel::class);
            $type === \App\Models\EmailRecipientEventModel::TYPE_OPEN
                ? $logs->recordOpen($logId)
                : $logs->recordClick($logId);

            $email = strtolower(trim((string) $this->request->getGet('e')));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $log = $logs->select('html_campaign_id')->find($logId);
                model(\App\Models\EmailRecipientEventModel::class)->record(
                    $email,
                    $type,
                    $logId,
                    ! empty($log['html_campaign_id']) ? (int) $log['html_campaign_id'] : null
                );
            }
        } catch (\Throwable) {
            // non-fatal — tracking must never break the pixel/redirect
        }
    }

    /**
     * Public unsubscribe endpoint (signed links only).
     *
     * GET  → confirmation page (email scanners that pre-fetch links do not unsubscribe anyone).
     * POST → unsubscribe; also the RFC 8058 List-Unsubscribe-Post one-click target (CSRF-exempt route).
     */
    public function unsubscribe(): ResponseInterface|string
    {
        $tenant = (string) ($this->request->getGet('t') ?: $this->request->getPost('t'));
        \App\Libraries\TenantResolver::ensureFromPublicKey($tenant);

        $email      = strtolower(trim((string) ($this->request->getGet('email') ?: $this->request->getPost('email'))));
        $signature  = (string) ($this->request->getGet('sig') ?: $this->request->getPost('sig'));
        $campaignId = (int) ($this->request->getGet('cid') ?: $this->request->getPost('cid') ?: 0);
        $isPost     = $this->request->is('post');
        $oneClick   = $isPost && $this->request->getPost('List-Unsubscribe') === 'One-Click';
        $wantsJson  = $this->request->isAJAX() || str_contains($this->request->getHeaderLine('Accept'), 'application/json');

        $valid = $email !== ''
            && filter_var($email, FILTER_VALIDATE_EMAIL)
            && EmailLinkSigner::verifyUnsubscribe($email, $signature, $tenant);

        $page = [
            'email'      => $email,
            'signature'  => $signature,
            'tenant'     => $tenant,
            'campaignId' => $campaignId,
            'sender'     => $this->publicSenderName(),
        ];

        if (! $valid) {
            if ($oneClick || $wantsJson) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status'  => 'error',
                    'message' => 'This unsubscribe link is invalid or has expired. Please contact the sender.',
                ]);
            }

            return view('public/email_unsubscribe', ['state' => 'invalid'] + $page);
        }

        if (! $isPost) {
            return view('public/email_unsubscribe', ['state' => 'confirm'] + $page);
        }

        $unsubscribes = model(\App\Models\EmailUnsubscribeModel::class);
        $already      = $unsubscribes->isUnsubscribed($email);
        $success      = $already || $unsubscribes->recordUnsubscribe(
            $email,
            $campaignId ?: null,
            $oneClick ? 'One-click unsubscribe (mail app)' : 'Unsubscribe link (confirmed)',
            (string) $this->request->getIPAddress()
        );
        if ($success && ! $already) {
            log_activity('email_unsubscribed', 'emails', 'Recipient unsubscribed: ' . $email, [
                'email'       => $email,
                'campaign_id' => $campaignId ?: null,
                'one_click'   => $oneClick,
            ]);
        }

        if ($oneClick || $wantsJson) {
            return $this->response->setStatusCode($success ? 200 : 500)->setJSON([
                'status'  => $success ? 'success' : 'error',
                'message' => $success ? 'You have been unsubscribed.' : 'Could not unsubscribe right now. Please try again later.',
            ]);
        }

        return view('public/email_unsubscribe', ['state' => $success ? 'done' : 'error'] + $page);
    }

    protected function publicSenderName(): string
    {
        try {
            $settings = service('settingsService');

            return trim((string) ($settings->get('ses_from_name', '') ?: $settings->get('app_name', '')));
        } catch (\Throwable) {
            return '';
        }
    }
}
