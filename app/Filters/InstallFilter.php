<?php

namespace App\Filters;

use App\Libraries\InstallStatus;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Redirects to the installer when the application is not installed.
 * Uses InstallStatus (lock file first) — never re-check DB on every
 * setting() call path, and never bounce installed apps into /install.
 */
class InstallFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $path = trim($request->getPath(), '/');

        // Allow installer, auth, webhooks, API, and assets
        $allowedPrefixes = [
            'install',
            'login',
            'logout',
            'signup',
            'verify-email',
            'resend-verification',
            'forgot-password',
            'reset-password',
            'webhooks',
            'webhook',
            'api',
            'assets',
            'uploads',
            'css',
            'js',
            'images',
            'favicon.ico',
        ];
        foreach ($allowedPrefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return null;
            }
        }

        if (InstallStatus::isInstalled()) {
            return null;
        }

        return redirect()->to(site_url('install'));
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
