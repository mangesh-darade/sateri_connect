<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Base Controller for Sateri Connect Public REST API v1.
 * Enforces API Standard: { "status": "success|error", "message": "", "data": {} }
 */
abstract class BaseV1Controller extends BaseController
{
    /**
     * Standard success JSON response.
     *
     * @param mixed  $data
     * @param string $message
     * @param int    $statusCode
     */
    protected function respondSuccess(mixed $data = null, string $message = 'Success', int $statusCode = 200): ResponseInterface
    {
        return $this->attachCors($this->response->setStatusCode($statusCode)->setJSON([
            'status'  => 'success',
            'message' => $message,
            'data'    => $data ?? (object) [],
        ]));
    }

    /**
     * Standard error JSON response.
     *
     * @param string $message
     * @param int    $statusCode
     * @param mixed  $data
     * @param array  $errors
     */
    protected function respondError(string $message, int $statusCode = 400, mixed $data = null, array $errors = []): ResponseInterface
    {
        $payload = [
            'status'  => 'error',
            'message' => $message,
            'data'    => $data ?? (object) [],
        ];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        return $this->attachCors($this->response->setStatusCode($statusCode)->setJSON($payload));
    }

    /**
     * Validation failed response (HTTP 422 Unprocessable Entity).
     *
     * @param array<string, string> $errors
     * @param string                $message
     */
    protected function respondValidationError(array $errors, string $message = 'Validation failed'): ResponseInterface
    {
        return $this->attachCors($this->response->setStatusCode(422)->setJSON([
            'status'  => 'error',
            'message' => $message,
            'errors'  => $errors,
            'data'    => (object) [],
        ]));
    }

    /**
     * Attach CORS headers for client-side API consumers and interactive tester.
     */
    protected function attachCors(ResponseInterface $res): ResponseInterface
    {
        return $res
            ->setHeader('Access-Control-Allow-Origin', '*')
            ->setHeader('Access-Control-Allow-Headers', 'X-API-Key, Authorization, Content-Type, Accept')
            ->setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
    }

    /**
     * Validate URL to prevent SSRF (Server-Side Request Forgery) attacks.
     * Blocks internal IPs, AWS/GCP metadata endpoints (169.254.169.254), loopback (127.0.0.1), and unsafe protocols.
     */
    protected function validateSafeUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);
        if (! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower($parts['host']);
        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return ENVIRONMENT === 'development';
        }

        // Check if host is direct IP address
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (ENVIRONMENT === 'development' && in_array($host, ['127.0.0.1', '::1'], true)) {
                return true;
            }

            // Reject private, loopback, and link-local cloud metadata IPs (169.254.169.254)
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        // Resolve DNS hostname to verify IP
        $ip = gethostbyname($host);
        if ($ip !== $host && filter_var($ip, FILTER_VALIDATE_IP)) {
            if (ENVIRONMENT === 'development' && in_array($ip, ['127.0.0.1', '::1'], true)) {
                return true;
            }

            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return true;
    }

    /**
     * Get JSON request payload with post fallback.
     *
     * @return array<string, mixed>
     */
    protected function getJsonPayload(): array
    {
        try {
            $json = $this->request->getJSON(true);
            if (is_array($json)) {
                return $json;
            }
        } catch (\Throwable) {
            // Soft-catch malformed JSON to fallback to raw decode or empty array
        }

        $post = $this->request->getPost();
        if (is_array($post) && $post !== []) {
            return $post;
        }

        $raw = (string) $this->request->getBody();
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * Get currently authenticated API user ID.
     */
    protected function currentUserId(): int
    {
        return (int) (session('api_user_id') ?: session('user_id') ?: 1);
    }
}
