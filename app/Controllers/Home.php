<?php

declare(strict_types=1);

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * Application entry — route to install, login, or dashboard.
 */
class Home extends BaseController
{
    public function index(): ResponseInterface
    {
        try {
            $settings = service('settingsService');
            if (! $settings->isInstalled()) {
                return redirect()->to('/install');
            }
        } catch (\Throwable $e) {
            return redirect()->to('/install');
        }

        if ($this->session->get('user_id')) {
            return redirect()->to('/dashboard');
        }

        return redirect()->to('/login');
    }
}
