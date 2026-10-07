<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\InstallStatus;
use App\Libraries\MetaDataDeletionService;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

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
            return redirect()->to(site_url(landing_path() ?? 'dashboard'));
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
        $service = new MetaDataDeletionService();

        if (strtolower($this->request->getMethod()) === 'post') {
            try {
                $payload = $service->parseSignedRequest((string) ($this->request->getPost('signed_request') ?? ''));
                $result  = $service->record($payload);
            } catch (Throwable $e) {
                $status = in_array($e->getCode(), [400, 503], true) ? $e->getCode() : 500;
                log_message('warning', 'Meta data deletion callback rejected: {msg}', ['msg' => $e->getMessage()]);

                return $this->response->setStatusCode($status)->setJSON(['error' => $status === 500 ? 'Unable to process request.' : $e->getMessage()]);
            }

            log_activity('delete', 'privacy', 'Meta data deletion request received', [
                'confirmation_code' => $result['confirmation_code'],
                'status'            => $result['status'],
            ]);

            return $this->response->setJSON([
                'url'               => site_url('data-deletion?id=' . $result['confirmation_code']),
                'confirmation_code' => $result['confirmation_code'],
            ]);
        }

        $code = trim((string) ($this->request->getGet('id') ?? ''));

        return view('public/data_deletion', [
            'pageTitle' => 'User Data Deletion Request',
            'appName'   => function_exists('setting') ? (string) setting('app_name', 'Sateri Connect') : 'Sateri Connect',
            'code'      => $code,
            'request'   => $code !== '' ? $service->findByCode($code) : null,
        ]);
    }
}
