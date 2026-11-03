<?php

declare(strict_types=1);

namespace App\Libraries\Social;

use App\Libraries\CampaignService;
use RuntimeException;
use Throwable;

/**
 * "Publishes" a social post to WhatsApp by starting a normal campaign (approved template to a tag audience).
 * The campaign engine keeps enforcing everything WhatsApp needs: approved template, opt-in/STOP, wallet and queueing.
 * There is no official WhatsApp Status API, so this is a broadcast, not a Status post.
 *
 * Target options (social_post_targets.options JSON): template_id, tag_ids[], all, caption_var.
 * Never retried automatically: a retry could send the same broadcast twice.
 */
class WhatsAppBroadcastPublisher implements SocialPublisherInterface
{
    public const CAPTION_MAX = 900;

    public function platform(): string
    {
        return 'whatsapp';
    }

    /**
     * WhatsApp template variables cannot contain new lines or runs of spaces.
     */
    public static function sanitizeVariable(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > self::CAPTION_MAX ? rtrim(mb_substr($text, 0, self::CAPTION_MAX - 1)) . '…' : $text;
    }

    /**
     * @param array<string, mixed> $options decoded target options
     * @return array{ok: bool, message: string}
     */
    public static function validateOptions(array $options): array
    {
        if ((int) ($options['template_id'] ?? 0) <= 0) {
            return ['ok' => false, 'message' => 'Choose an approved WhatsApp template for the broadcast.'];
        }
        $tags = array_filter(array_map('intval', (array) ($options['tag_ids'] ?? [])));
        if (empty($options['all']) && $tags === []) {
            return ['ok' => false, 'message' => 'Choose who receives the WhatsApp broadcast (a customer group or all contacts).'];
        }

        return ['ok' => true, 'message' => ''];
    }

    public function publish(array $account, array $post, string $caption, ?string $mediaUrl): array
    {
        $options = is_array($post['target_options'] ?? null) ? $post['target_options'] : [];
        $check   = self::validateOptions($options);
        if (! $check['ok']) {
            return ['ok' => false, 'status' => 422, 'message' => $check['message']];
        }

        try {
            $campaigns  = new CampaignService();
            $title      = trim((string) ($post['title'] ?? '')) ?: 'Social post';
            $variables  = ! empty($options['caption_var']) ? ['1' => ['text' => self::sanitizeVariable($caption)]] : [];
            $campaignId = $campaigns->create([
                'name'         => mb_substr('Social: ' . $title . ' ' . date('d M H:i'), 0, 191),
                'template_id'  => (int) $options['template_id'],
                'message_type' => 'template',
                'variables'    => $variables,
                'created_by'   => $post['created_by'] ?? null,
            ]);
            $campaigns->saveAudience($campaignId, [
                'all'     => ! empty($options['all']),
                'tag_ids' => array_values(array_filter(array_map('intval', (array) ($options['tag_ids'] ?? [])))),
            ]);
            $campaigns->start($campaignId);
        } catch (RuntimeException $e) {
            // Policy / wallet / audience messages are written for the operator, so they are safe to show.
            log_message('error', 'WhatsAppBroadcastPublisher: ' . $e->getMessage());

            return ['ok' => false, 'status' => 422, 'message' => mb_substr($e->getMessage(), 0, 300)];
        } catch (Throwable $e) {
            log_message('error', 'WhatsAppBroadcastPublisher ' . $e::class);

            return ['ok' => false, 'status' => 502, 'message' => 'The WhatsApp broadcast could not be started. Check Campaigns.'];
        }

        return ['ok' => true, 'status' => 200, 'message' => 'Broadcast started', 'data' => [
            'external_id'  => (string) $campaignId,
            'external_url' => site_url('campaigns'),
        ]];
    }
}
