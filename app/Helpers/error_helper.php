<?php

declare(strict_types=1);

use App\Libraries\ErrorPresenter;
use CodeIgniter\HTTP\ResponseInterface;

if (! function_exists('present_app_error')) {
    /**
     * Build a reusable error-screen payload (HTML or controller use).
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    function present_app_error(array $overrides = [], int $statusCode = 500): array
    {
        return ErrorPresenter::manual($overrides, $statusCode);
    }
}

if (! function_exists('render_app_error')) {
    /**
     * Render the branded error screen as an HTTP response (controllers / filters).
     *
     * @param array<string, mixed> $overrides
     */
    function render_app_error(array $overrides = [], int $statusCode = 500): ResponseInterface
    {
        $error = ErrorPresenter::manual($overrides, $statusCode);
        $html  = view('errors/html/app_error', [
            'error'   => $error,
            'code'    => $error['code'],
            'message' => $error['message'],
            'title'   => $error['title'],
        ]);

        return service('response')
            ->setStatusCode($statusCode)
            ->setBody($html);
    }
}
