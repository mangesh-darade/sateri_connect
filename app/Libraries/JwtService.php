<?php

namespace App\Libraries;

use Config\Jwt as JwtConfig;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;
use stdClass;
use Throwable;
use UnexpectedValueException;

/**
 * JWT generate / validate / decode using firebase/php-jwt.
 */
class JwtService
{
    protected JwtConfig $config;

    public function __construct(?JwtConfig $config = null)
    {
        $this->config = $config ?? config('Jwt');
    }

    /**
     * Generate a signed JWT for a user.
     *
     * @param array<string, mixed> $claims Extra claims merged into the payload
     *
     * @throws RuntimeException
     */
    public function generate(int|string $userId, array $claims = []): string
    {
        $secret = $this->resolveSecret();
        $now    = time();
        $ttl    = max(60, (int) $this->config->ttl);

        $payload = array_merge([
            'iss' => $this->config->issuer,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttl,
            'sub' => (string) $userId,
            'uid' => (int) $userId,
        ], $claims);

        try {
            return JWT::encode($payload, $secret, $this->config->algo);
        } catch (Throwable $e) {
            log_message('error', 'JwtService::generate failed: {msg}', ['msg' => $e->getMessage()]);

            throw new RuntimeException('Failed to generate JWT: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Validate a token and return the decoded payload, or null if invalid.
     */
    public function validate(string $token): ?stdClass
    {
        try {
            return $this->decode($token);
        } catch (Throwable $e) {
            log_message('debug', 'JwtService::validate failed: {msg}', ['msg' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Decode and verify a JWT. Throws on invalid / expired tokens.
     *
     * @throws UnexpectedValueException|RuntimeException
     */
    public function decode(string $token): stdClass
    {
        if ($token === '') {
            throw new UnexpectedValueException('Empty JWT token.');
        }

        $secret = $this->resolveSecret();

        try {
            $decoded = JWT::decode($token, new Key($secret, $this->config->algo));

            return $decoded;
        } catch (Throwable $e) {
            throw $e instanceof UnexpectedValueException
                ? $e
                : new UnexpectedValueException('Invalid JWT: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Resolve signing secret from config / env / encryption key.
     *
     * @throws RuntimeException
     */
    protected function resolveSecret(): string
    {
        $secret = $this->config->secret;

        if ($secret === '') {
            $secret = (string) env('JWT_SECRET', '');
        }

        if ($secret === '') {
            $secret = (string) (config('Encryption')->key ?? '');
        }

        if ($secret === '') {
            throw new RuntimeException('JWT secret is not configured. Set JWT_SECRET or encryption.key.');
        }

        return $secret;
    }
}
