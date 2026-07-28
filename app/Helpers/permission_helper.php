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
