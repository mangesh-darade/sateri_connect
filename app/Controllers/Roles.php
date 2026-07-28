<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\ActivityLogger;
use App\Models\PermissionModel;
use App\Models\RoleModel;
use App\Models\UserModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Roles and permissions matrix.
 */
class Roles extends BaseController
{
    public function index(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('roles.view')) {
            return $denied;
        }

        $roles       = model(RoleModel::class)->orderBy('name', 'ASC')->findAll();
        $permissions = model(PermissionModel::class)->getGroupedByModule();
        $matrix      = [];

        foreach ($roles as $role) {
            $perms = model(RoleModel::class)->getPermissions((int) $role['id']);
            $matrix[(int) $role['id']] = array_map(
                static fn (array $p): int => (int) $p['id'],
                $perms
            );
        }

        return $this->render('roles/index', [
            'pageTitle'   => 'Roles & Permissions',
            'roles'       => $roles,
            'permissions' => $permissions,
            'matrix'      => $matrix,
        ]);
    }

    public function update(): ResponseInterface
    {
        if ($denied = $this->requirePermission('roles.edit')) {
            return $denied;
        }

        $input = $this->request->getJSON(true) ?: $this->request->getPost();
        $rolePermissions = $input['role_permissions'] ?? null;

        // Also support form: permissions[role_id][] = permission_id
        if ($rolePermissions === null && isset($input['permissions']) && is_array($input['permissions'])) {
            $rolePermissions = $input['permissions'];
        }

        if (! is_array($rolePermissions)) {
            return redirect()->back()->with('error', 'Invalid permissions payload.');
        }

        $roleModel = model(RoleModel::class);

        foreach ($rolePermissions as $roleId => $permissionIds) {
            $roleId = (int) $roleId;
            $role   = $roleModel->find($roleId);

            if ($role === null) {
                continue;
            }

            // Never strip super-admin of all access via matrix accidentally
            if (in_array($role['slug'] ?? '', ['super-admin', 'super_admin'], true)) {
                continue;
            }

            if (! is_array($permissionIds)) {
                $permissionIds = [];
            }

            $ids = array_values(array_unique(array_map('intval', $permissionIds)));
            $roleModel->syncPermissions($roleId, $ids);
        }

        (new ActivityLogger())->log('update', 'roles', 'Permissions matrix updated');

        if ($this->request->isAJAX()) {
            return $this->jsonResponse(true, null, 'Permissions updated.');
        }

        return redirect()->to('/roles')->with('success', 'Permissions updated.');
    }

    public function store(): ResponseInterface
    {
        if ($denied = $this->requirePermission('roles.create')) {
            return $denied;
        }

        $rules = [
            'name' => 'required|min_length[2]|max_length[100]',
            'slug' => 'required|min_length[2]|max_length[100]|alpha_dash|is_unique[roles.slug]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $id = model(RoleModel::class)->insert([
            'name'        => $this->request->getPost('name'),
            'slug'        => $this->request->getPost('slug'),
            'description' => $this->request->getPost('description'),
        ]);

        if (! $id) {
            return redirect()->back()->withInput()->with('errors', model(RoleModel::class)->errors());
        }

        (new ActivityLogger())->log('create', 'roles', 'Role created', ['role_id' => $id]);

        return redirect()->to('/roles')->with('success', 'Role created.');
    }

    public function delete(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('roles.delete')) {
            return $denied;
        }

        $role = model(RoleModel::class)->find($id);
        if ($role === null) {
            return redirect()->to('/roles')->with('error', 'Role not found.');
        }

        if (in_array($role['slug'] ?? '', ['super-admin', 'super_admin', 'admin'], true)) {
            return redirect()->to('/roles')->with('error', 'System roles cannot be deleted.');
        }

        $usersWithRole = model(UserModel::class)->where('role_id', $id)->countAllResults();
        if ($usersWithRole > 0) {
            return redirect()->to('/roles')->with('error', 'Cannot delete a role assigned to users.');
        }

        db_connect()->table('role_permissions')->where('role_id', $id)->delete();
        model(RoleModel::class)->delete($id);

        (new ActivityLogger())->log('delete', 'roles', 'Role deleted', ['role_id' => $id]);

        return redirect()->to('/roles')->with('success', 'Role deleted.');
    }
}
