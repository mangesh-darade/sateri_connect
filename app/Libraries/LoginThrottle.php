<?php

declare(strict_types=1);

namespace App\Libraries;

use Throwable;

/**
 * Brute-force limits shared by the web and API login.
 *
 * Failures are counted per (email + IP) and per email overall. One IP is blocked after a few misses,
 * but only a distributed attack reaches the higher per-account cap, so a single attacker cannot lock a
 * real user out of their own account.
 */
class LoginThrottle
{
    public const PAIR_MAX    = 5;
    public const ACCOUNT_MAX = 30;
    public const WINDOW      = 900; // 15 minutes

    /**
     * A valid bcrypt hash of a random value. Verifying against it when the email is unknown keeps the
     * response time of "no such user" equal to "wrong password".
     */
    public const DUMMY_HASH = '$2y$10$/NPL9EYuj8vL6K02GHinQ.ZIWxd/VkHkCMv41PN5v9YSq07qX5dwW';

    public static function blocked(string $email, string $ip): bool
    {
        return self::count(self::pairKey($email, $ip)) >= self::PAIR_MAX
            || self::count(self::accountKey($email)) >= self::ACCOUNT_MAX;
    }

    public static function fail(string $email, string $ip): void
    {
        foreach ([self::pairKey($email, $ip), self::accountKey($email)] as $key) {
            try {
                cache()->save($key, ['attempts' => self::count($key) + 1], self::WINDOW);
            } catch (Throwable) {
                // cache unavailable: the IP limiter still applies
            }
        }
    }

    public static function clear(string $email, string $ip): void
    {
        try {
            cache()->delete(self::pairKey($email, $ip));
        } catch (Throwable) {
            // ignore
        }
    }

    /** password_verify that always spends the hashing time, even when the user does not exist. */
    public static function verify(string $password, ?string $hash): bool
    {
        $isReal = is_string($hash) && $hash !== '';

        return password_verify($password, $isReal ? $hash : self::DUMMY_HASH) && $isReal;
    }

    protected static function count(string $key): int
    {
        try {
            $data = cache($key);
        } catch (Throwable) {
            return 0;
        }

        return is_array($data) ? (int) ($data['attempts'] ?? 0) : 0;
    }

    protected static function pairKey(string $email, string $ip): string
    {
        return 'login_pair_' . md5(strtolower(trim($email)) . '|' . $ip);
    }

    protected static function accountKey(string $email): string
    {
        return 'login_acct_' . md5(strtolower(trim($email)));
    }
}
