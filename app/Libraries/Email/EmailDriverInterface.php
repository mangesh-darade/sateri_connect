<?php

declare(strict_types=1);

namespace App\Libraries\Email;

/**
 * Common contract for outbound email transports (SMTP, SendGrid, Cheerio, …).
 *
 * @phpstan-type EmailResult array{ok: bool, message: string, provider: string, data?: mixed}
 */
interface EmailDriverInterface
{
    public function getName(): string;

    public function loadCredentials(): void;

    /**
     * Send a plain-text email to one or more recipients.
     *
     * @param string|list<string>         $to
     * @param array<string, mixed>        $options  from_name, from_email, campaign_name, label_name, reply_to, cc, bcc
     *
     * @return EmailResult
     */
    public function send(string|array $to, string $subject, string $body, array $options = []): array;

    /**
     * Send an HTML email to one or more recipients.
     *
     * @param string|list<string>         $to
     * @param array<string, mixed>        $options
     *
     * @return EmailResult
     */
    public function sendHtml(string|array $to, string $subject, string $html, array $options = []): array;

    /**
     * Verify credentials by sending a test message.
     *
     * @return EmailResult
     */
    public function testConnection(string $to): array;

    /**
     * Send a marketing / bulk campaign using provider-native campaign primitives.
     *
     * Typical uses:
     * - offers, promotions, newsletters, product updates
     * - one-to-many campaign sends
     *
     * Common keys:
     * - `name`, `subject`, `html`, `plain_text`, `send_at`, `categories`
     * - `recipients`, `list_ids`, `segment_ids`, `all`
     * - `label_name`, `tag_id_arr`, `campaign_name`
     * - `sender_id`, `suppression_group_id`, `custom_unsubscribe_url`, `design_id`
     *
     * @param array<string, mixed> $campaign
     *
     * @return EmailResult
     */
    public function sendCampaign(array $campaign): array;
}
