<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\InstallStatus;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Application entry — route to install, login, or dashboard.
 *
 * Uses InstallStatus (install.lock first) so a transient/tenant DB issue
 * never mis-routes an installed app to /install (which then 302s to
 * /login — browser redirect churn / “reload performance” feel).
 */
class Home extends BaseController
{
    public function index(): ResponseInterface
    {
        if (! InstallStatus::isInstalled()) {
            return redirect()->to(site_url('install'));
        }

        if ($this->session->get('user_id')) {
            return redirect()->to(site_url('dashboard'));
        }

        return redirect()->to(site_url('login'));
    }

    public function privacyPolicy(): string
    {
        return view('public/privacy_policy', [
            'pageTitle' => 'Privacy Policy',
            'appName'   => function_exists('setting') ? (string) setting('app_name', 'Sateri Connect') : 'Sateri Connect',
            'updatedAt' => 'October 1, 2026',
        ]);
    }

    public function terms(): string
    {
        return view('public/terms', [
            'pageTitle' => 'Terms of Service',
            'appName'   => function_exists('setting') ? (string) setting('app_name', 'Sateri Connect') : 'Sateri Connect',
            'updatedAt' => 'October 1, 2026',
        ]);
    }

    public function dataDeletion(): ResponseInterface|string
    {
        // Meta Data Deletion Callback (POST)
        if (strtolower($this->request->getMethod()) === 'post') {
            $confirmationCode = 'del_' . bin2hex(random_bytes(8));
            return $this->response->setJSON([
                'url'               => site_url('data-deletion?id=' . $confirmationCode),
                'confirmation_code' => $confirmationCode,
            ]);
        }

        return view('public/data_deletion', [
            'pageTitle' => 'User Data Deletion Request',
            'appName'   => function_exists('setting') ? (string) setting('app_name', 'Sateri Connect') : 'Sateri Connect',
            'code'      => (string) ($this->request->getGet('id') ?? ''),
        ]);
    }
}
