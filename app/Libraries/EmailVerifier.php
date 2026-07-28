<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Local email verification (syntax + MX + disposable heuristics).
 * Cheerio has no public verifier endpoint; this runs server-side checks.
 */
class EmailVerifier
{
    /** @var list<string> */
    protected array $disposableDomains = [
        'mailinator.com', 'guerrillamail.com', 'tempmail.com', '10minutemail.com',
        'trashmail.com', 'yopmail.com', 'sharklasers.com', 'throwaway.email',
        'temp-mail.org', 'getnada.com', 'maildrop.cc',
    ];

    /**
     * @return array{
     *   email:string,status:string,syntax_ok:bool,mx_ok:bool,disposable:bool,
     *   checks:array<string,mixed>
     * }
     */
    public function verify(string $email): array
    {
        $email = strtolower(trim($email));
        $checks = [];

        $syntaxOk = (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
        $checks['syntax'] = $syntaxOk ? 'pass' : 'fail';

        $domain = '';
        if ($syntaxOk && str_contains($email, '@')) {
            $domain = substr($email, strrpos($email, '@') + 1);
        }

        $disposable = $domain !== '' && in_array($domain, $this->disposableDomains, true);
        $checks['disposable'] = $disposable ? 'fail' : 'pass';

        $mxOk = false;
        if ($domain !== '') {
            $mxHosts = [];
            if (@getmxrr($domain, $mxHosts) && $mxHosts !== []) {
                $mxOk = true;
                $checks['mx'] = ['pass' => true, 'hosts' => array_slice($mxHosts, 0, 5)];
            } else {
                // Fallback: A record may still accept mail
                $a = @gethostbynamel($domain);
                if (is_array($a) && $a !== []) {
                    $mxOk = true;
                    $checks['mx'] = ['pass' => true, 'hosts' => array_slice($a, 0, 3), 'via' => 'A'];
                } else {
                    $checks['mx'] = ['pass' => false, 'hosts' => []];
                }
            }
        } else {
            $checks['mx'] = ['pass' => false, 'hosts' => []];
        }

        $status = 'unknown';
        if (! $syntaxOk) {
            $status = 'invalid';
        } elseif ($disposable) {
            $status = 'risky';
        } elseif ($mxOk) {
            $status = 'valid';
        } else {
            $status = 'invalid';
        }

        return [
            'email'      => $email,
            'status'     => $status,
            'syntax_ok'  => $syntaxOk,
            'mx_ok'      => $mxOk,
            'disposable' => $disposable,
            'checks'     => $checks,
        ];
    }

    /**
     * @param list<string> $emails
     * @return list<array<string, mixed>>
     */
    public function verifyMany(array $emails, int $max = 50): array
    {
        $out = [];
        $seen = [];
        foreach ($emails as $email) {
            $email = strtolower(trim((string) $email));
            if ($email === '' || isset($seen[$email])) {
                continue;
            }
            $seen[$email] = true;
            $out[] = $this->verify($email);
            if (count($out) >= $max) {
                break;
            }
        }

        return $out;
    }
}
