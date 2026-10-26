<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Requires platform super-admin session (master.platform_admins).
 */
class PlatformAuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $session = session();
        if (! $session->get('platform_admin_id')) {
            if ($request->isAJAX()) {
                return service('response')
                    ->setStatusCode(401)
                    ->setJSON(['success' => false, 'message' => 'Platform admin required.']);
            }

            return redirect()->to('/login?tenant=_platform')->with('error', 'Sign in as platform super admin.');
        }

        // At most once a minute, make sure this admin still exists and is active (cross-tenant power).
        $checkedAt = (int) $session->get('platform_admin_checked_at');
        if ($checkedAt < 1 || (time() - $checkedAt) >= 60) {
            try {
                $admin = (new \App\Libraries\MasterTenantRepository())
                    ->findPlatformAdminById((int) $session->get('platform_admin_id'));
                if ($admin === null || strtolower((string) ($admin['status'] ?? '')) !== 'active') {
                    $session->destroy();
                    if ($request->isAJAX()) {
                        return service('response')
                            ->setStatusCode(401)
                            ->setJSON(['success' => false, 'message' => 'Platform admin required.']);
                    }

                    return redirect()->to('/login?tenant=_platform')->with('error', 'Sign in as platform super admin.');
                }
                $session->set('platform_admin_checked_at', time());
            } catch (\Throwable $e) {
                log_message('warning', 'PlatformAuthFilter revalidation skipped: {msg}', ['msg' => $e->getMessage()]);
            }
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
