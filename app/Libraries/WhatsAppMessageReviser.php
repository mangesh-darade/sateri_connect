<?php

declare(strict_types=1);

namespace App\Libraries;

use Throwable;

/**
 * Sends the changed text of an already sent WhatsApp message.
 *
 * It first asks the provider to change the sent message (only inside the 15-minute edit window). The WhatsApp
 * Business API has no edit call today and answers "The parameter status is required", so when that fails the
 * new text goes out as a new message, which needs the 24-hour window to be open.
 */
class WhatsAppMessageReviser
{
    /**
     * @param object $api provider client with editText() and sendText() (service('whatsApp'))
     *
     * @return array{ok: bool, status: int, message: string, data?: array{mode: string, external_id: string, result: array<string, mixed>}}
     */
    public function revise(object $api, string $to, string $waMessageId, string $content, bool $canEditInPlace, bool $within24h): array
    {
        if ($canEditInPlace && $waMessageId !== '') {
            try {
                $result     = (array) $api->editText($to, $waMessageId, $content);
                $returnedId = trim((string) ($result['messages'][0]['id'] ?? $result['message_id'] ?? $result['id'] ?? ''));

                if ($returnedId !== '' && $returnedId !== $waMessageId) {
                    // The provider sent a replacement message instead of changing the old one.
                    return $this->ok('resent', $returnedId, $result, 'Sent again as a new message.');
                }

                return $this->ok('edited', $waMessageId, $result, 'Message updated on WhatsApp.');
            } catch (Throwable $e) {
                log_message('info', 'WhatsAppMessageReviser: edit not available, sending as a new message: {msg}', [
                    'msg' => $e->getMessage(),
                ]);
            }
        }

        if (! $within24h) {
            return [
                'ok'      => false,
                'status'  => 422,
                'message' => 'A sent WhatsApp message cannot be changed, and the 24-hour window is closed, so it cannot be sent again. Use a template.',
            ];
        }

        try {
            $result     = (array) $api->sendText($to, $content);
            $externalId = trim((string) ($result['messages'][0]['id'] ?? $result['message_id'] ?? ''));

            return $this->ok('resent', $externalId, $result, 'Sent again as a new message.');
        } catch (Throwable $e) {
            log_message('error', 'WhatsAppMessageReviser::revise send failed for {to}: {msg}', [
                'to'  => strlen($to) > 4 ? str_repeat('*', strlen($to) - 4) . substr($to, -4) : '****',
                'msg' => $e->getMessage(),
            ]);

            return [
                'ok'      => false,
                'status'  => 502,
                'message' => 'WhatsApp could not send the message right now. Please try again.',
            ];
        }
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array{ok: bool, status: int, message: string, data: array{mode: string, external_id: string, result: array<string, mixed>}}
     */
    private function ok(string $mode, string $externalId, array $result, string $message): array
    {
        return [
            'ok'      => true,
            'status'  => 200,
            'message' => $message,
            'data'    => ['mode' => $mode, 'external_id' => $externalId, 'result' => $result],
        ];
    }
}
