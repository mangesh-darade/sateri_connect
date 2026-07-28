<?php

declare(strict_types=1);

namespace App\Libraries\Email;

use App\Libraries\CheerioDirectAPI;
use App\Libraries\SettingsService;
use Config\EmailProviders;

/**
 * Cheerio Direct API email transport (single + label/bulk announcements).
 */
class CheerioEmailDriver extends AbstractEmailDriver
{
    protected EmailProviders $config;
    protected CheerioDirectAPI $cheerio;

    public function __construct(
        ?SettingsService $settings = null,
        ?EmailProviders $config = null,
        ?CheerioDirectAPI $cheerio = null
    ) {
        parent::__construct($settings);
        $this->config  = $config ?? config(EmailProviders::class);
        $this->cheerio = $cheerio ?? new CheerioDirectAPI($settings);
    }

    public function getName(): string
    {
        return SettingsService::EMAIL_PROVIDER_CHEERIO;
    }

    public function loadCredentials(): void
    {
        $this->cheerio->loadCredentials();
    }

    /**
     * @param array<string, mixed> $options  label_name, tag_id_arr, email_builder_id, campaign_name
     */
    public function send(string|array $to, string $subject, string $body, array $options = []): array
    {
        $isHtml = (bool) ($options['html'] ?? false);

        if ($isHtml) {
            return $this->sendHtml($to, $subject, $body, $options);
        }

        $html = nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);

        return $this->sendHtml($to, $subject, $html, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function sendHtml(string|array $to, string $subject, string $html, array $options = []): array
    {
        $this->loadCredentials();

        $labelName = trim((string) ($options['label_name'] ?? ''));
        if ($labelName !== '') {
            return $this->sendLabelCampaign($labelName, $subject, $html, $options);
        }

        if (is_array($to)) {
            return $this->sendManySingles($to, $subject, $html, $options);
        }

        $recipients = $this->normalizeRecipients($to);
        if ($recipients === []) {
            return $this->result(false, 'No valid recipient email addresses.');
        }

        if (count($recipients) === 1) {
            return $this->sendSingle($recipients[0], $subject, $html, $options);
        }

        return $this->sendManySingles($recipients, $subject, $html, $options);
    }

    public function sendCampaign(array $campaign): array
    {
        $subject = trim((string) ($campaign['subject'] ?? ''));
        if ($subject === '') {
            return $this->result(false, 'Cheerio campaign subject is required.');
        }

        $html = (string) ($campaign['html'] ?? '');
        if ($html === '') {
            $plain = (string) ($campaign['plain_text'] ?? '');
            $html  = nl2br(htmlspecialchars($plain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
        }

        if ($html === '') {
            return $this->result(false, 'Cheerio campaign body is required.');
        }

        $options = [
            'campaign_name'   => $campaign['campaign_name'] ?? $campaign['name'] ?? null,
            'label_name'      => $campaign['label_name'] ?? null,
            'tag_id_arr'      => $campaign['tag_id_arr'] ?? null,
            'email_builder_id'=> $campaign['email_builder_id'] ?? null,
        ];

        $labelName = trim((string) ($campaign['label_name'] ?? ''));
        if ($labelName !== '') {
            return $this->sendLabelCampaign($labelName, $subject, $html, array_filter(
                $options,
                static fn ($value) => $value !== null && $value !== ''
            ));
        }

        $recipients = $this->normalizeRecipients($campaign['recipients'] ?? []);
        if ($recipients === []) {
            return $this->result(
                false,
                'Cheerio bulk marketing currently needs `label_name` or explicit `recipients`.'
            );
        }

        return $this->sendManySingles($recipients, $subject, $html, array_filter(
            $options,
            static fn ($value) => $value !== null && $value !== ''
        ));
    }

    /**
     * @param array<string, mixed> $options
     */
    protected function sendSingle(string $email, string $subject, string $html, array $options): array
    {
        $campaign = trim((string) ($options['campaign_name'] ?? $this->settings->get('cheerio_email_campaign_name', $this->config->defaultCampaignName)));

        $payload = [
            'email'        => $email,
            'subject'      => $subject,
            'campaignName' => $campaign !== '' ? $campaign : $this->config->defaultCampaignName,
        ];

        $builderId = trim((string) ($options['email_builder_id'] ?? ''));
        if ($builderId !== '') {
            $payload['emailBuilderId'] = $builderId;
        } else {
            $payload['htmlContent'] = $html;
        }

        try {
            $response = $this->cheerio->request('POST', 'v1/announcements/single-email/send', $payload);
            $ok       = (bool) ($response['flag'] ?? $response['ok'] ?? true);

            if (! $ok) {
                $msg = (string) ($response['message'] ?? 'Cheerio email API returned an error.');

                return $this->result(false, $msg, $response);
            }

            $apiMsg = trim((string) ($response['message'] ?? 'Successfully Sent Email'));
            $balance = $response['data']['balance']['email'] ?? null;
            $msg     = $apiMsg . ' to ' . $email . '.';
            if ($balance !== null) {
                $msg .= ' Email credits left: ' . (string) $balance . '.';
            }
            $msg .= ' If it is not in the inbox, check Spam/Promotions and verify Sender ID in Cheerio Dashboard.';

            return $this->result(true, $msg, $response);
        } catch (\Throwable $e) {
            log_message('error', 'CheerioEmailDriver single send failed: {msg}', ['msg' => $e->getMessage()]);

            return $this->result(false, $e->getMessage());
        }
    }

    /**
     * @param list<string>         $recipients
     * @param array<string, mixed> $options
     */
    protected function sendManySingles(array $recipients, string $subject, string $html, array $options): array
    {
        $sent   = 0;
        $failed = [];

        foreach ($recipients as $email) {
            $result = $this->sendSingle($email, $subject, $html, $options);
            if ($result['ok']) {
                $sent++;
            } else {
                $failed[] = ['email' => $email, 'message' => $result['message']];
            }
        }

        if ($failed === []) {
            return $this->result(true, sprintf('Sent %d email(s) via Cheerio.', $sent), [
                'sent' => $sent,
            ]);
        }

        if ($sent > 0) {
            return $this->result(false, sprintf('Sent %d of %d. %d failed.', $sent, count($recipients), count($failed)), [
                'sent'   => $sent,
                'failed' => $failed,
            ]);
        }

        return $this->result(false, 'All Cheerio email sends failed.', ['failed' => $failed]);
    }

    /**
     * @param array<string, mixed> $options
     */
    protected function sendLabelCampaign(string $labelName, string $subject, string $html, array $options): array
    {
        $campaign = trim((string) ($options['campaign_name'] ?? $this->settings->get('cheerio_email_campaign_name', $this->config->defaultCampaignName)));
        $tagIds   = $options['tag_id_arr'] ?? [];

        if (! is_array($tagIds)) {
            $tagIds = [];
        }

        $payload = [
            'labelName'    => $labelName,
            'tagIdArr'     => array_values($tagIds),
            'campaignName' => $campaign !== '' ? $campaign : $this->config->defaultCampaignName,
            'htmlData'     => $html,
            'subject'      => $subject,
        ];

        try {
            $response = $this->cheerio->request('POST', 'v1/announcements/label-email/send', $payload);
            $ok       = (bool) ($response['flag'] ?? $response['ok'] ?? true);

            if (! $ok) {
                $msg = (string) ($response['message'] ?? 'Cheerio label email API returned an error.');

                return $this->result(false, $msg, $response);
            }

            $count = (int) ($response['data']['emailCount'] ?? $response['emailCount'] ?? 0);
            $msg   = $count > 0
                ? sprintf('Cheerio label campaign queued for %d contact(s).', $count)
                : 'Cheerio label email campaign accepted.';

            return $this->result(true, $msg, $response);
        } catch (\Throwable $e) {
            log_message('error', 'CheerioEmailDriver label send failed: {msg}', ['msg' => $e->getMessage()]);

            return $this->result(false, $e->getMessage());
        }
    }
}
