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
}
