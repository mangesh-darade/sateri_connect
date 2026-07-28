<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Security\Exceptions\SecurityException;

/**
 * Friendlier CSRF filter for web + AJAX requests.
 *
 * - Web forms: redirect back with a clear flash message
 * - AJAX/API-like requests: return JSON 403 instead of a debug page
 */
class CsrfFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $security = service('security');

        try {
            $security->verify($request);
        } catch (SecurityException) {
            $message = 'Your form/session security token expired or is invalid. Refresh the page and try again.';

            if ($request->isAJAX()) {
                $response = service('response')
                    ->setStatusCode(403)
                    ->setJSON([
                        'success' => false,
                        'message' => $message,
                        'code'    => 'csrf_invalid',
                    ]);

                if (function_exists('csrf_hash') && method_exists($response, 'setHeader')) {
                    $response->setHeader((string) csrf_header(), (string) csrf_hash());
                }

                return $response;
            }

            return redirect()->back()->withInput()->with('error', $message);
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        if (function_exists('csrf_hash') && method_exists($response, 'setHeader')) {
            $response->setHeader((string) csrf_header(), (string) csrf_hash());
        }

        return null;
    }
}
