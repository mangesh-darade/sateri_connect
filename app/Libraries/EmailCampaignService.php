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
                $recipients = $camp['recipients'] ?? [];
                if (! is_array($recipients) || $recipients === []) {
                    throw new RuntimeException('No recipients on this campaign.');
                }
                $campaignPayload['recipients'] = $recipients;
            }
            if (! empty($options['email_builder_id'])) {
                $campaignPayload['email_builder_id'] = $options['email_builder_id'];
            }
            $result = $mailer->sendCampaign($campaignPayload);
        } else {
            if ($mode === 'label') {
                throw new RuntimeException('Label mode is only available for Cheerio provider.');
            }
            $recipients = $camp['recipients'] ?? [];
            if (! is_array($recipients) || $recipients === []) {
                throw new RuntimeException('No recipients on this campaign.');
            }
            $result = $mailer->sendHtml($recipients, $subject, $html !== '' ? $html : '<p></p>', $options);
        }

        $ok = (bool) ($result['ok'] ?? false);
        $rdata = is_array($result['data'] ?? null) ? $result['data'] : [];
        $sentCount = (int) ($rdata['sent'] ?? $rdata['emailCount'] ?? ($rdata['data']['emailCount'] ?? 0));
        if ($ok && $sentCount === 0 && $mode === 'recipients') {
            $sentCount = count($camp['recipients'] ?? []);
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

        $target = $mode === 'label'
            ? ('label:' . ($camp['label_name'] ?? ''))
            : implode(', ', array_slice($camp['recipients'] ?? [], 0, 3));

        model(EmailLogModel::class)->record(
            'campaign',
            $ok ? 'sent' : 'failed',
            $subject,
            $target,
            (string) ($result['provider'] ?? ''),
            (string) ($result['message'] ?? ''),
            ['result' => $result['data'] ?? null],
            $actorUserId,
            ! empty($camp['builder_id']) ? (int) $camp['builder_id'] : null,
            $id
        );

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
