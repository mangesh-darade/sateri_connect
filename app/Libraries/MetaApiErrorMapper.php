<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Convert Meta Graph / WhatsApp Cloud API errors into operator-friendly messages.
 */
final class MetaApiErrorMapper
{
    public static function humanize(string $raw, ?int $httpStatus = null, mixed $errorCode = null): string
    {
        $lower = strtolower($raw);
        $code  = is_scalar($errorCode) ? (string) $errorCode : '';

        if ($httpStatus === 401 || $code === '190' || str_contains($lower, 'session has expired') || str_contains($lower, 'invalid oauth')) {
            return 'WhatsApp access token is invalid or expired for this account. Reconnect WhatsApp in Settings.';
        }

        if ($httpStatus === 403 || str_contains($lower, 'permission') || $code === '10' || $code === '200') {
            return 'This WhatsApp Business Account does not have permission for the requested operation.';
        }

        if ($httpStatus === 429 || $code === '4' || $code === '80007' || str_contains($lower, 'rate limit') || str_contains($lower, 'too many')) {
            return 'WhatsApp API rate limit reached. Wait a moment and try again.';
        }

        if (str_contains($lower, 'template name does not exist') || str_contains($lower, 'template not found') || $code === '132001') {
            return 'Selected WhatsApp template was not found on this WhatsApp Business Account. Sync templates and try again.';
        }

        if (str_contains($lower, 'not approved') || $code === '132016' || str_contains($lower, 'template is paused')) {
            return 'Selected WhatsApp template is not approved for this WhatsApp Business Account.';
        }

        if (str_contains($lower, 'language') || $code === '132012') {
            return 'Template language does not match an approved language for this WhatsApp Business Account.';
        }

        if (str_contains($lower, 'parameter') || str_contains($lower, 'required components') || $code === '132000') {
            return 'Required template variables are missing or invalid. Fill every variable before sending.';
        }

        if (str_contains($lower, 'phone number id') || str_contains($lower, 'unsupported get request') || $code === '100') {
            return 'Phone Number ID is invalid for this WhatsApp Business Account or access token.';
        }

        if (str_contains($lower, 'waba') || str_contains($lower, 'whatsapp business account')) {
            return 'WhatsApp Business Account ID is invalid or not linked to this access token.';
        }

        return $raw !== '' ? $raw : 'WhatsApp API request failed.';
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array{message: string, code: ?string, subcode: ?string, type: ?string}
     */
    public static function parseDecoded(array $decoded): array
    {
        $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];

        return [
            'message' => (string) ($error['message'] ?? $decoded['message'] ?? ''),
            'code'    => isset($error['code']) ? (string) $error['code'] : null,
            'subcode' => isset($error['error_subcode']) ? (string) $error['error_subcode'] : null,
            'type'    => isset($error['type']) ? (string) $error['type'] : null,
        ];
    }
}
