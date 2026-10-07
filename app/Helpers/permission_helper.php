<?php

/**
 * Permission helper functions.
 */

if (! function_exists('can')) {
    /**
     * Whether the current session user has a permission slug.
     */
    function can(string $permission): bool
    {
        $session = session();

        $userId = $session->get('user_id') ?: $session->get('api_user_id');
        if (! $userId) {
            return false;
        }

        $roleSlug = (string) ($session->get('role_slug') ?: $session->get('api_role_slug') ?: '');
        if (in_array($roleSlug, ['super-admin', 'super_admin'], true)) {
            return true;
        }

        $permissions = $session->get('permissions');
        if (! is_array($permissions) || $permissions === []) {
            $permissions = $session->get('api_permissions');
        }
        if (! is_array($permissions)) {
            return false;
        }

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }
}

if (! function_exists('landing_path')) {
    /**
     * First panel page the current user may open (ordered like the sidebar), or null when none.
     */
    function landing_path(): ?string
    {
        $pages = [
            'dashboard.view'   => 'dashboard',
            'chat.view'        => 'chat',
            'contacts.view'    => 'contacts',
            'campaigns.view'   => 'campaigns',
            'emails.view'      => 'email-manager',
            'templates.view'   => 'templates',
            'automations.view' => 'automations',
            'sequences.view'   => 'sequences',
            'keywords.view'    => 'keywords',
            'queue.view'       => 'queue',
            'reports.view'     => 'analytics',
            'users.view'       => 'users',
            'roles.view'       => 'roles',
            'settings.view'    => 'settings',
            'guide.view'       => 'guide/local',
        ];

        foreach ($pages as $permission => $path) {
            if (can($permission)) {
                return $path;
            }
        }

        return null;
    }
}

if (! function_exists('permission_denied_response')) {
    /**
     * Non-AJAX denial: redirect to the first allowed page, or a 403 screen when that would loop.
     */
    function permission_denied_response(string $message = 'You do not have permission to access this resource.'): \CodeIgniter\HTTP\ResponseInterface
    {
        $target  = landing_path();
        $current = trim(uri_string(), '/');

        if ($target !== null && $target !== $current) {
            return redirect()->to(site_url($target))->with('error', $message);
        }

        helper('error');
        $actions = $target !== null
            ? [['label' => 'Go to my workspace', 'url' => site_url($target), 'primary' => true]]
            : [];
        $actions[] = ['label' => 'Sign out', 'url' => site_url('logout'), 'primary' => $target === null];

        return render_app_error([
            'kind'         => 'permission',
            'title'        => 'Access denied',
            'headline'     => 'You do not have access to this page',
            'message'      => $message . ' Ask your administrator to update your role permissions.',
            'icon'         => 'fa-lock',
            'show_details' => false,
            'home_url'     => site_url($target ?? 'logout'),
            'actions'      => $actions,
        ], 403);
    }
}

if (! function_exists('user_role')) {
    /**
     * Return the current user's role slug (or name if slug missing).
     */
    function user_role(): ?string
    {
        $session = session();

        $slug = $session->get('role_slug');
        if (is_string($slug) && $slug !== '') {
            return $slug;
        }

        $name = $session->get('role_name');

        return is_string($name) && $name !== '' ? $name : null;
    }
}
