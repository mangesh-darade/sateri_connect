<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\EmailHtmlCampaignModel;
use App\Models\EmailLogModel;
use RuntimeException;

/**
 * Send / finalize HTML email campaigns (shared by wizard UI and cron).
 */
class EmailCampaignService
{
    /**
     * @param array<string, mixed> $camp
     * @return array{ok:bool,sent:int,message:string,provider?:string,data?:mixed}
     */
    public function dispatch(array $camp, ?int $actorUserId = null): array
    {
        $model    = model(EmailHtmlCampaignModel::class);
        $id       = (int) ($camp['id'] ?? 0);
        $settings = new SettingsService();
        $provider = $settings->getEmailProvider();
        $mode     = (string) ($camp['mode'] ?? 'recipients');
        $html     = (string) ($camp['html_content'] ?? '');
        $subject  = (string) ($camp['subject'] ?? '');
        $name     = (string) ($camp['name'] ?? 'html-campaign');

        $options = ['campaign_name' => $name];
        if ($provider === SettingsService::EMAIL_PROVIDER_CHEERIO && ! empty($camp['cheerio_builder_id'])) {
            $options['email_builder_id'] = (string) $camp['cheerio_builder_id'];
        }

        $model->update($id, ['status' => 'sending', 'last_error' => null]);

        $mailer = service('emailProvider');

        // Resolve contacts from customer group / label if mode is label or if recipients list is empty
        $rawRecipients = is_array($camp['recipients'] ?? null) ? $camp['recipients'] : [];
        if ($mode === 'label' || ($rawRecipients === [] && ! empty($camp['label_name']))) {
            $labelName = trim((string) ($camp['label_name'] ?? ''));
            if ($labelName !== '') {
                $tagModel = model(\App\Models\TagModel::class);
                $tagRow   = $tagModel->where('name', $labelName)->first();
                if (! $tagRow && ctype_digit($labelName)) {
                    $tagRow = $tagModel->find((int) $labelName);
                }
                if ($tagRow) {
                    $groupContacts = model(\App\Models\ContactModel::class)
                        ->select('contacts.email')
                        ->join('contact_tags', 'contact_tags.contact_id = contacts.id')
                        ->where('contact_tags.tag_id', (int) $tagRow['id'])
                        ->where('contacts.email !=', '')
                        ->where('contacts.email IS NOT NULL', null, false)
                        ->findAll();
                    foreach ($groupContacts as $row) {
                        $em = strtolower(trim((string) ($row['email'] ?? '')));
                        if ($em !== '' && filter_var($em, FILTER_VALIDATE_EMAIL)) {
                            $rawRecipients[] = $em;
                        }
                    }
                    $rawRecipients = array_values(array_unique($rawRecipients));
                }
            }
        }

        // Filter out unsubscribed contacts
        $recipients = model(\App\Models\EmailUnsubscribeModel::class)->filterActiveRecipients($rawRecipients);

        // Prepare unsubscribe link and merge tags (Cheerio label sends are not personalized per recipient)
        $perRecipient = $provider !== SettingsService::EMAIL_PROVIDER_CHEERIO;
        $unsubUrl     = EmailTracking::unsubscribeUrl($id, $perRecipient);
        $options['unsubscribe_url'] = $unsubUrl;
        $options['campaign_id']     = $id;
        if (str_contains($html, '{{unsubscribe_url}}')) {
            $html = str_replace('{{unsubscribe_url}}', $unsubUrl, $html);
        } else {
            $html .= '<div style="margin-top: 32px; padding-top: 16px; border-top: 1px solid #e2e8f0; font-size: 11px; color: #94a3b8; text-align: center;">' .
                     'To stop receiving these emails, <a href="' . htmlspecialchars($unsubUrl, ENT_QUOTES, 'UTF-8') . '" style="color: #64748b; text-decoration: underline;">unsubscribe here</a>.' .
                     '</div>';
        }

        // Pre-create log to get ID for open tracking pixel
        $target = $mode === 'label'
            ? ('label:' . ($camp['label_name'] ?? ''))
            : implode(', ', array_slice($recipients, 0, 3));

        $logId = model(EmailLogModel::class)->record(
            'campaign',
            'queued',
            $subject,
            $target,
            $provider,
            'Sending in progress',
            [],
            $actorUserId,
            ! empty($camp['builder_id']) ? (int) $camp['builder_id'] : null,
            $id
        );

        if ($logId > 0) {
            $html .= EmailTracking::openPixelHtml($logId, $perRecipient);
            $options['log_id'] = $logId;
        }

        // Attachments support from campaign or inherited template
        $attachmentPath = trim((string) ($camp['attachment_path'] ?? ''));
        $attachmentName = trim((string) ($camp['attachment_name'] ?? ''));

        if ($attachmentPath === '' && ! empty($camp['builder_id'])) {
            $builderTpl = model(\App\Models\EmailBuilderModel::class)->find((int) $camp['builder_id']);
            if (! empty($builderTpl['attachment_path'])) {
                $attachmentPath = (string) $builderTpl['attachment_path'];
                $attachmentName = (string) ($builderTpl['attachment_name'] ?? basename($attachmentPath));
            }
        }

        if ($attachmentPath !== '') {
            $fullPath = str_starts_with($attachmentPath, FCPATH) ? $attachmentPath : FCPATH . ltrim($attachmentPath, '/\\');
            if (file_exists($fullPath)) {
                $options['attachments'] = [
                    [
                        'path' => $fullPath,
                        'name' => $attachmentName !== '' ? $attachmentName : basename($fullPath),
                        'mime' => mime_content_type($fullPath) ?: 'application/octet-stream',
                    ],
                ];
            }
        }

        if ($provider === SettingsService::EMAIL_PROVIDER_CHEERIO) {
            $campaignPayload = [
                'name'          => $name,
                'subject'       => $subject,
                'html'          => $html !== '' ? $html : '<p></p>',
                'campaign_name' => $name,
            ];
            if ($mode === 'label') {
                $label = trim((string) ($camp['label_name'] ?? ''));
                if ($label === '') {
                    throw new RuntimeException('Label name required for label mode.');
                }
                $campaignPayload['label_name'] = $label;
            } else {
                if ($recipients === []) {
                    throw new RuntimeException('No active recipients on this campaign (or all unsubscribed).');
                }
                $campaignPayload['recipients'] = $recipients;
            }
            if (! empty($options['email_builder_id'])) {
                $campaignPayload['email_builder_id'] = $options['email_builder_id'];
            }
            $result = $mailer->sendCampaign($campaignPayload);
        } else {
            // For SMTP, Amazon SES, SendGrid:
            if ($recipients === []) {
                $lbl = trim((string) ($camp['label_name'] ?? ''));
                $msg = $lbl !== ''
                    ? 'No contacts with valid email found in customer group / label "' . $lbl . '" (or all contacts unsubscribed).'
                    : 'No active recipients on this campaign (or all unsubscribed).';
                throw new RuntimeException($msg);
            }
            $result = $mailer->sendHtml($recipients, $subject, $html !== '' ? $html : '<p></p>', $options);
        }

        $ok = (bool) ($result['ok'] ?? false);
        $rdata = is_array($result['data'] ?? null) ? $result['data'] : [];
        $sentCount = (int) ($rdata['sent'] ?? $rdata['emailCount'] ?? ($rdata['data']['emailCount'] ?? 0));
        if ($ok && $sentCount === 0 && $mode === 'recipients') {
            $sentCount = count($recipients);
        }
        $failedCount = is_array($rdata['failed'] ?? null) ? count($rdata['failed']) : ($ok ? 0 : 1);

        $update = [
            'status'       => $ok ? 'sent' : 'failed',
            'sent_count'   => $sentCount,
            'failed_count' => $failedCount,
            'last_error'   => $ok ? null : (string) ($result['message'] ?? 'Send failed'),
            'sent_at'      => $ok ? date('Y-m-d H:i:s') : null,
        ];
        if (db_connect()->fieldExists('scheduled_at', 'email_html_campaigns')) {
            $update['scheduled_at'] = null;
        }
        $model->update($id, $update);

        if ($logId > 0) {
            model(EmailLogModel::class)->update($logId, [
                'status'    => $ok ? 'sent' : 'failed',
                'message'   => (string) ($result['message'] ?? ($ok ? 'Sent' : 'Failed')),
                'meta_json' => json_encode(['result' => array_diff_key($rdata, ['recipients' => true]) ?: ($result['data'] ?? null)]),
            ]);
            model(\App\Models\EmailRecipientEventModel::class)
                ->recordSendResults($logId, $id, $recipients, $ok, $rdata, (string) ($result['message'] ?? ''));
        }

        (new ActivityLogger())->log(
            $ok ? 'email_campaign_sent' : 'email_campaign_failed',
            'emails',
            ($ok ? 'Sent' : 'Failed') . ' HTML email campaign: ' . $name,
            ['campaign_id' => $id]
        );

        return [
            'ok'       => $ok,
            'sent'     => $sentCount,
            'message'  => (string) ($result['message'] ?? ($ok ? 'sent' : 'failed')),
            'provider' => (string) ($result['provider'] ?? ''),
            'data'     => $result['data'] ?? null,
        ];
    }

    /**
     * @return int Number of campaigns attempted
     */
    public function processScheduled(): int
    {
        $db = db_connect();
        if (! $db->tableExists('email_html_campaigns') || ! $db->fieldExists('scheduled_at', 'email_html_campaigns')) {
            return 0;
        }

        $now = date('Y-m-d H:i:s');
        $due = model(EmailHtmlCampaignModel::class)
            ->where('status', 'queued')
            ->where('scheduled_at <=', $now)
            ->where('scheduled_at IS NOT NULL', null, false)
            ->findAll();

        $started = 0;
        foreach ($due as $camp) {
            if (! is_array($camp) || empty($camp['id'])) {
                continue;
            }
            try {
                $this->dispatch($camp, null);
                $started++;
            } catch (\Throwable $e) {
                model(EmailHtmlCampaignModel::class)->update((int) $camp['id'], [
                    'status'     => 'failed',
                    'last_error' => $e->getMessage(),
                ]);
                log_message('error', 'Scheduled email campaign {id} failed: {msg}', [
                    'id'  => $camp['id'],
                    'msg' => $e->getMessage(),
                ]);
            }
        }

        return $started;
    }
}
