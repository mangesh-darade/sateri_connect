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
     * Get JSON request payload with post fallback.
     *
     * @return array<string, mixed>
     */
    protected function getJsonPayload(): array
    {
        $json = $this->request->getJSON(true);
        if (is_array($json)) {
            return $json;
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
