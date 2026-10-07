<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\EmailUnsubscribeModel;
use App\Models\EmailVerificationModel;

/**
 * One suppression check for every email send path (single, bulk, campaigns, drips, automations).
 *
 * Skips addresses that unsubscribed, hard-bounced or complained (email_unsubscribes — SES bounce /
 * complaint webhooks write there too) and, for marketing sends, addresses the List Verifier marked invalid.
 */
class EmailSuppressionService
{
    public const UNSUBSCRIBED = 'unsubscribed';
    public const BOUNCED      = 'bounced';
    public const COMPLAINED   = 'complained';
    public const INVALID      = 'invalid';

    protected const CHUNK = 500;

    /**
     * @param list<string> $emails
     *
     * @return array{allowed: list<string>, skipped: array<string, string>} skipped = email => reason
     */
    public function filter(array $emails, bool $skipVerifierInvalid = true): array
    {
        $allowed = [];
        $skipped = [];
        foreach ($emails as $email) {
            $e = strtolower(trim((string) $email));
            if ($e === '') {
                continue;
            }
            if (! filter_var($e, FILTER_VALIDATE_EMAIL)) {
                $skipped[$e] = self::INVALID;

                continue;
            }
            $allowed[$e] = true;
        }

        $list = array_keys($allowed);
        foreach (array_chunk($list, self::CHUNK) as $chunk) {
            foreach ($this->suppressedReasons($chunk) as $email => $reason) {
                $skipped[$email] = $reason;
            }
            if ($skipVerifierInvalid) {
                foreach ($this->verifierInvalid($chunk) as $email) {
                    $skipped[$email] ??= self::INVALID;
                }
            }
        }

        return [
            'allowed' => array_values(array_filter($list, static fn (string $e) => ! isset($skipped[$e]))),
            'skipped' => $skipped,
        ];
    }

    /**
     * Reason the address must not be emailed, or null when it is fine.
     */
    public function reasonFor(string $email, bool $skipVerifierInvalid = false): ?string
    {
        $result = $this->filter([$email], $skipVerifierInvalid);

        return $result['skipped'] === [] ? null : (string) reset($result['skipped']);
    }

    /**
     * Business-friendly summary, e.g. "3 skipped (2 unsubscribed, 1 bounced)".
     *
     * @param array<string, string> $skipped
     */
    public static function summary(array $skipped): string
    {
        if ($skipped === []) {
            return '';
        }

        $parts = [];
        foreach (array_count_values($skipped) as $reason => $count) {
            $parts[] = $count . ' ' . $reason;
        }

        return count($skipped) . ' skipped (' . implode(', ', $parts) . ')';
    }

    public static function reasonLabel(string $reason): string
    {
        return match ($reason) {
            self::BOUNCED    => 'bounced earlier',
            self::COMPLAINED => 'marked an earlier email as spam',
            self::INVALID    => 'is marked invalid by the List Verifier',
            default          => 'has unsubscribed',
        };
    }

    /**
     * @param list<string> $emails
     *
     * @return array<string, string>
     */
    protected function suppressedReasons(array $emails): array
    {
        if ($emails === []) {
            return [];
        }

        try {
            $rows = model(EmailUnsubscribeModel::class)
                ->select('email, reason')
                ->whereIn('email', $emails)
                ->where('is_deleted', 0)
                ->where('is_active', 1)
                ->findAll();
        } catch (\Throwable $e) {
            log_message('error', 'Email suppression lookup failed: {msg}', ['msg' => $e->getMessage()]);

            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $reason = strtolower((string) ($row['reason'] ?? ''));
            $out[strtolower((string) $row['email'])] = match (true) {
                str_contains($reason, 'complaint') => self::COMPLAINED,
                str_contains($reason, 'bounce')    => self::BOUNCED,
                default                            => self::UNSUBSCRIBED,
            };
        }

        return $out;
    }

    /**
     * @param list<string> $emails
     *
     * @return list<string>
     */
    protected function verifierInvalid(array $emails): array
    {
        if ($emails === []) {
            return [];
        }

        try {
            $rows = model(EmailVerificationModel::class)
                ->select('email')
                ->whereIn('email', $emails)
                ->where('status', 'invalid')
                ->findColumn('email');
        } catch (\Throwable $e) {
            log_message('error', 'Email verifier lookup failed: {msg}', ['msg' => $e->getMessage()]);

            return [];
        }

        return array_map('strtolower', (array) ($rows ?? []));
    }
}
