<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\MasterTenantRepository;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Platform super admins. Only the main platform super admin can add more.
 */
class PlatformAdmins extends BaseController
{
    public function index(): string|ResponseInterface
    {
        $repo = new MasterTenantRepository();
        if ($denied = $this->denyUnlessPrimary($repo)) {
            return $denied;
        }

        return view('platform/admins/index', [
            'pageTitle' => 'Super admins',
            'navActive' => 'admins',
            'admins'    => $repo->listPlatformAdmins(),
            'currentId' => (int) session('platform_admin_id'),
        ]);
    }

    public function store(): ResponseInterface
    {
        $name     = trim((string) $this->request->getPost('name'));
        $email    = strtolower(trim((string) $this->request->getPost('email')));
        $password = (string) $this->request->getPost('password');
        $confirm  = (string) $this->request->getPost('password_confirm');

        if (mb_strlen($name) < 2 || mb_strlen($name) > 150) {
            return redirect()->back()->withInput()->with('error', 'Name must be between 2 and 150 characters.');
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return redirect()->back()->withInput()->with('error', 'Enter a valid email address.');
        }
        if (strlen($password) < 8) {
            return redirect()->back()->withInput()->with('error', 'Password must be at least 8 characters.');
        }
        if ($password !== $confirm) {
            return redirect()->back()->withInput()->with('error', 'Password confirmation does not match.');
        }

        $repo = new MasterTenantRepository();
        if ($denied = $this->denyUnlessPrimary($repo)) {
            return $denied;
        }
        if ($repo->findPlatformAdminByEmail($email) !== null) {
            return redirect()->back()->withInput()->with('error', 'A super admin with this email already exists.');
        }
        if (! $repo->createPlatformAdmin($email, $password, $name)) {
            return redirect()->back()->withInput()->with('error', 'Could not create the super admin.');
        }

        log_activity('create', 'platform', 'Platform super admin created', [
            'email' => $email,
        ]);

        return redirect()->to('/platform/admins')->with('success', 'Super admin created. They can sign in from the platform login and will see only the clients they create.');
    }

    protected function denyUnlessPrimary(MasterTenantRepository $repo): ?ResponseInterface
    {
        if ($repo->isPrimaryPlatformAdmin((int) session('platform_admin_id'))) {
            return null;
        }

        return redirect()->to('/platform/clients')->with('error', 'Only the main platform super admin can manage super admins.');
    }
}
