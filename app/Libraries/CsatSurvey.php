<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\ContactModel;
use App\Models\CsatRatingModel;
use App\Models\NotificationModel;
use Throwable;

/**
 * Customer satisfaction question. When an agent resolves a WhatsApp chat the customer is asked to reply
 * 1-5. A bare 1-5 sent within a day of the question is the answer (and is not passed on to bots).
 * Plain text, so it works on every WhatsApp provider.
 */
class CsatSurvey
{
    private const WINDOW_SECONDS = 86400;
    private const LOW_RATING     = 2;

    /**
     * @param array<string, mixed> $contact
     * @param array<string, mixed> $conversation The conversation being resolved (for its current agent).
     *
     * @return bool True when the question was sent.
     */
    public function ask(array $contact, array $conversation): bool
    {
        try {
            $rules = (new InboxRules())->config();
            if (! $rules['csat_enabled'] || trim($rules['csat_question']) === '') {
                return false;
            }
            if (strtolower((string) ($contact['channel'] ?? 'whatsapp')) !== 'whatsapp'
                || strtolower((string) ($contact['consent_status'] ?? '')) === 'opted_out'
                || ! contact_within_24h_window($contact, false)) {
                return false;
            }

            $convId = (int) ($conversation['id'] ?? 0);
            $model  = model(CsatRatingModel::class);
            $recent = $model->where('conversation_id', $convId)
                ->where('asked_at >=', date('Y-m-d H:i:s', time() - self::WINDOW_SECONDS))
                ->countAllResults();
            if ($convId <= 0 || $recent > 0) {
                return false;
            }

            $model->insert([
                'conversation_id' => $convId,
                'contact_id'      => (int) $contact['id'],
                'agent_id'        => ! empty($conversation['assigned_to']) ? (int) $conversation['assigned_to'] : null,
                'asked_at'        => date('Y-m-d H:i:s'),
            ]);

            service('whatsAppConsent')->sendComplianceReply($contact, $rules['csat_question']);
            log_activity('send', 'csat', 'Satisfaction question sent', ['contact_id' => (int) $contact['id'], 'conversation_id' => $convId]);

            return true;
        } catch (Throwable $e) {
            log_message('warning', 'CSAT question failed: {msg}', ['msg' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Record the answer when this text is a bare 1-5 and a question is open for the contact.
     *
     * @return int|null The rating, or null when the message is not a CSAT answer.
     */
    public function tryRecord(int $contactId, string $text): ?int
    {
        if (preg_match('/^\s*([1-5])\s*$/', $text, $m) !== 1) {
            return null;
        }

        try {
            $model = model(CsatRatingModel::class);
            $row   = $model->where('contact_id', $contactId)
                ->where('rating', null)
                ->where('asked_at >=', date('Y-m-d H:i:s', time() - self::WINDOW_SECONDS))
                ->orderBy('id', 'DESC')
                ->first();
            if (! is_array($row)) {
                return null;
            }

            $rating = (int) $m[1];
            $model->update((int) $row['id'], ['rating' => $rating, 'answered_at' => date('Y-m-d H:i:s')]);
            log_activity('update', 'csat', 'Satisfaction rating received', ['contact_id' => $contactId, 'rating' => $rating]);

            $contact = model(ContactModel::class)->find($contactId);
            if (is_array($contact)) {
                $thanks = trim((new InboxRules())->config()['csat_thanks']);
                if ($thanks !== '') {
                    service('whatsAppConsent')->sendComplianceReply($contact, $thanks);
                }
                if ($rating <= self::LOW_RATING) {
                    $name = trim((string) ($contact['name'] ?? '')) ?: (string) ($contact['mobile'] ?? 'A customer');
                    model(NotificationModel::class)->notifyChatUsers(
                        'Low satisfaction rating',
                        $name . ' rated the support ' . $rating . ' out of 5.',
                        site_url('chat?contact_id=' . $contactId),
                        ! empty($row['agent_id']) ? (int) $row['agent_id'] : null,
                        'warning'
                    );
                }
            }

            return $rating;
        } catch (Throwable $e) {
            log_message('warning', 'CSAT answer failed: {msg}', ['msg' => $e->getMessage()]);

            return null;
        }
    }
}
