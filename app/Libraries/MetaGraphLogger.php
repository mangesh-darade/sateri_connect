<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Structured Meta/WhatsApp operation logs — never includes access tokens.
 */
final class MetaGraphLogger
{
    /**
     * @param array<string, mixed> $context
     */
    public static function log(
        string $operation,
        array $context = [],
        string $level = 'info'
    ): void {
        $safe = self::sanitize($context);
        $parts = [
            'op=' . $operation,
            'customer_id=' . (string) ($safe['customer_id'] ?? self::currentCustomerId()),
            'waba_id=' . (string) ($safe['waba_id'] ?? ''),
            'phone_number_id=' . (string) ($safe['phone_number_id'] ?? ''),
            'template_id=' . (string) ($safe['template_id'] ?? ''),
            'template_name=' . (string) ($safe['template_name'] ?? ''),
            'status=' . (string) ($safe['meta_status'] ?? $safe['status'] ?? ''),
            'error_code=' . (string) ($safe['meta_error_code'] ?? $safe['error_code'] ?? ''),
            'ts=' . date('c'),
        ];

        if (! empty($safe['detail'])) {
            $parts[] = 'detail=' . mb_substr((string) $safe['detail'], 0, 500);
        }

        $message = 'WhatsAppMeta ' . implode(' ', $parts);
        log_message($level, $message);
    }

    public static function currentCustomerId(): string
    {
        return SubdomainDatabase::resolve();
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function sanitize(array $context): array
    {
        $blocked = [
            'access_token',
            'token',
            'authorization',
            'Authorization',
            'app_secret',
            'client_secret',
            'password',
            'pin',
            'two_step_pin',
            'bearer',
        ];

        $out = [];
        foreach ($context as $key => $value) {
            $k = (string) $key;
            foreach ($blocked as $needle) {
                if (strcasecmp($k, $needle) === 0 || str_contains(strtolower($k), 'token') || str_contains(strtolower($k), 'secret')) {
                    continue 2;
                }
            }
            if (is_array($value)) {
                $out[$k] = self::sanitize($value);
            } elseif (is_scalar($value) || $value === null) {
                $out[$k] = $value;
            }
        }

        return $out;
    }
}
