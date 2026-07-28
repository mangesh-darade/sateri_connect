<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Base for REST API controllers — JSON envelope + authenticated user helpers.
 */
abstract class BaseApiController extends BaseController
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $errors
     */
    protected function respondSuccess(
        mixed $data = null,
        string $message = 'OK',
        int $status = 200
    ): ResponseInterface {
        return $this->response->setStatusCode($status)->setJSON([
            'success' => true,
            'message' => $message,
            'data'    => $data,
            'errors'  => null,
        ]);
    }

    /**
     * @param array<string, mixed> $errors
     */
    protected function respondError(
        string $message,
        array $errors = [],
        int $status = 400,
        mixed $data = null
    ): ResponseInterface {
        return $this->response->setStatusCode($status)->setJSON([
            'success' => false,
            'message' => $message,
            'data'    => $data,
            'errors'  => $errors === [] ? null : $errors,
        ]);
    }

    protected function respondValidationError(array $errors, string $message = 'Validation failed.'): ResponseInterface
    {
        return $this->respondError($message, $errors, 422);
    }

    protected function apiUserId(): int
    {
        return (int) (session('api_user_id') ?: session('user_id') ?: 0);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function apiUser(): ?array
    {
        $user = session('api_user');

        return is_array($user) ? $user : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getJsonInput(): array
    {
        $json = $this->request->getJSON(true);

        if (is_array($json)) {
            return $json;
        }

        return $this->request->getPost() ?: [];
    }
}
