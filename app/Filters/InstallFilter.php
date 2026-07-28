<?php

namespace App\Filters;

use App\Libraries\SettingsService;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Redirects to the installer when the application is not installed.
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

        try {
            if (is_file(WRITEPATH . 'install.lock')) {
                return null;
            }
            $settings = new SettingsService();
            if ($settings->isInstalled()) {
                return null;
            }
        } catch (Throwable $e) {
            // Settings table may not exist yet during first boot
            log_message('debug', 'InstallFilter settings check failed: {msg}', ['msg' => $e->getMessage()]);
        }

        return redirect()->to('/install');
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
