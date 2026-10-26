<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\ActivityLogger;
use App\Libraries\DataTablesQuery;
use App\Models\RoleModel;
use App\Models\UserModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * User CRUD with role assignment.
 */
class Users extends BaseController
{
    public function index(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('users.view')) {
            return $denied;
        }

        return $this->render('users/index', [
            'pageTitle' => 'Users',
            'seats'     => (new \App\Libraries\TenantPlanLimits())->snapshot(),
        ]);
    }

    /** DataTables JSON for the team users list (never exposes password or token columns). */
    public function data(): ResponseInterface
    {
        if ($denied = $this->requirePermission('users.view')) {
            return $denied;
        }

        return $this->response->setJSON(DataTablesQuery::respond($this->request, [
            'builder'  => static fn () => db_connect()->table('users u')
                ->select('u.id, u.name, u.email, u.phone, u.status, u.last_login, r.name AS role_name')
                ->join('roles r', 'r.id = u.role_id', 'left')
                ->where('u.deleted_at', null),
            'columns'  => [0 => 'u.name', 1 => 'u.email', 2 => 'u.phone', 3 => 'r.name', 4 => 'u.status', 5 => 'u.last_login'],
            'search'   => ['u.name', 'u.email', 'u.phone', 'r.name', 'u.status'],
            'order'    => [0, 'asc'],
            'tiebreak' => 'u.id',
            'map'      => static fn (array $r): array => [
                'id'         => (int) $r['id'],
                'name'       => (string) $r['name'],
                'email'      => (string) $r['email'],
                'phone'      => (string) ($r['phone'] ?? ''),
                'role_name'  => (string) ($r['role_name'] ?? ''),
                'status'     => (string) ($r['status'] ?? 'active'),
                'last_login' => format_app_datetime($r['last_login'] ?? null),
            ],
        ]));
    }

    public function create(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('users.create')) {
            return $denied;
        }

        if ($block = (new \App\Libraries\TenantPlanLimits())->userCreateBlock()) {
            return redirect()->to('/users')->with('error', $block);
        }

        return $this->render('users/form', [
            'pageTitle' => 'Create User',
            'editUser'  => null,
            'roles'     => $this->rolesForAssignment(),
        ]);
    }

    public function store(): ResponseInterface
    {
        if ($denied = $this->requirePermission('users.create')) {
            return $denied;
        }

        $rules = [
            'name'             => 'required|min_length[2]|max_length[150]',
            'email'            => 'required|valid_email|is_unique[users.email]',
            'password'         => 'required|min_length[8]',
            'password_confirm' => 'required|matches[password]',
            'role_id'          => 'required|is_natural_no_zero',
            'status'           => 'permit_empty|in_list[active,inactive]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $roleId = (int) $this->request->getPost('role_id');
        if ($err = $this->assertAssignableRole($roleId)) {
            return redirect()->back()->withInput()->with('error', $err);
        }

        if ($block = (new \App\Libraries\TenantPlanLimits())->userCreateBlock()) {
            return redirect()->back()->withInput()->with('error', $block);
        }

        $id = model(UserModel::class)->insert([
            'name'              => $this->request->getPost('name'),
            'email'             => $this->request->getPost('email'),
            'password'          => $this->request->getPost('password'),
            'phone'             => $this->request->getPost('phone') ?: null,
            'role_id'           => $roleId,
            'status'            => $this->request->getPost('status') ?: 'active',
            'email_verified_at' => date('Y-m-d H:i:s'),
        ]);

        if (! $id) {
            return redirect()->back()->withInput()->with('errors', model(UserModel::class)->errors());
        }

        (new ActivityLogger())->log('create', 'users', 'User created', ['user_id' => $id]);

        return redirect()->to('/users')->with('success', 'User created.');
    }

    public function edit(int $id): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('users.edit')) {
            return $denied;
        }

        $user = model(UserModel::class)->find($id);
        if ($user === null) {
            return redirect()->to('/users')->with('error', 'User not found.');
        }

        unset($user['password']);

        return $this->render('users/form', [
            'pageTitle' => 'Edit User',
            'editUser'  => $user,
            'roles'     => $this->rolesForAssignment($user),
        ]);
    }

    public function update(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('users.edit')) {
            return $denied;
        }

        $model = model(UserModel::class);
        $user  = $model->find($id);

        if ($user === null) {
            return redirect()->to('/users')->with('error', 'User not found.');
        }
        if ($err = $this->assertManageableUser($user)) {
            return redirect()->to('/users')->with('error', $err);
        }

        $rules = [
            'name'    => 'required|min_length[2]|max_length[150]',
            'email'   => "required|valid_email|is_unique[users.email,id,{$id}]",
            'role_id' => 'required|is_natural_no_zero',
            'status'  => 'permit_empty|in_list[active,inactive]',
            'password'=> 'permit_empty|min_length[8]',
        ];

        $password = (string) $this->request->getPost('password');
        if ($password !== '') {
            $rules['password_confirm'] = 'required|matches[password]';
        }

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $roleId = (int) $this->request->getPost('role_id');
        if ($err = $this->assertAssignableRole($roleId, (int) ($user['role_id'] ?? 0))) {
            return redirect()->back()->withInput()->with('error', $err);
        }

        $data = [
            'name'    => $this->request->getPost('name'),
            'email'   => $this->request->getPost('email'),
            'phone'   => $this->request->getPost('phone') ?: null,
            'role_id' => $roleId,
            'status'  => $this->request->getPost('status') ?: 'active',
        ];

        if ($password !== '') {
            $data['password'] = $password;
        }

        $changes = [];
        foreach (['name', 'email', 'phone', 'role_id', 'status'] as $field) {
            $oldVal = (string) ($user[$field] ?? '');
            $newVal = (string) ($data[$field] ?? '');
            if ($oldVal !== $newVal) {
                $changes[$field] = [
                    'old' => $oldVal !== '' ? $oldVal : '(empty)',
                    'new' => $newVal !== '' ? $newVal : '(empty)',
                ];
            }
        }
        if ($password !== '') {
            $changes['password'] = ['old' => '********', 'new' => '******** (password reset)'];
        }

        $model->update($id, $data);

        (new ActivityLogger())->log('update', 'users', 'User updated: ' . ($user['name'] ?? ('#' . $id)), [
            'user_id' => $id,
            'changes' => $changes,
        ]);

        return redirect()->to('/users')->with('success', 'User updated.');
    }

    public function delete(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('users.delete')) {
            return $denied;
        }

        if ($id === $this->userId()) {
            return $this->request->isAJAX()
                ? $this->jsonResponse(false, null, 'You cannot delete your own account.', [], 422)
                : redirect()->to('/users')->with('error', 'You cannot delete your own account.');
        }

        $model  = model(UserModel::class);
        $target = $model->find($id);
        if ($target === null) {
            return redirect()->to('/users')->with('error', 'User not found.');
        }
        if ($err = $this->assertManageableUser($target)) {
            return $this->request->isAJAX()
                ? $this->jsonResponse(false, null, $err, [], 403)
                : redirect()->to('/users')->with('error', $err);
        }

        $model->delete($id);
        (new ActivityLogger())->log('delete', 'users', 'User deleted', ['user_id' => $id]);

        if ($this->request->isAJAX()) {
            return $this->jsonResponse(true, null, 'User deleted.');
        }

        return redirect()->to('/users')->with('success', 'User deleted.');
    }

    /**
     * Roles offered on create/edit. Super Admin is never a choice for a new user.
     * An existing Super Admin keeps that option so their account can still be saved.
     *
     * @param array<string, mixed>|null $currentUser
     * @return list<array<string, mixed>>
     */
    protected function rolesForAssignment(?array $currentUser = null): array
    {
        $keepId = (int) ($currentUser['role_id'] ?? 0);
        $roles  = model(RoleModel::class)->orderBy('name', 'ASC')->findAll();

        return array_values(array_filter(
            $roles,
            static function (array $role) use ($keepId): bool {
                $slug = (string) ($role['slug'] ?? '');
                if (! in_array($slug, ['super-admin', 'super_admin'], true)) {
                    return true;
                }

                return $keepId > 0 && (int) ($role['id'] ?? 0) === $keepId;
            }
        ));
    }

    /**
     * Super Admin cannot be assigned when creating a user or changing someone into that role.
     */
    protected function assertAssignableRole(int $roleId, ?int $currentRoleId = null): ?string
    {
        $role = model(RoleModel::class)->find($roleId);
        if ($role === null) {
            return 'Selected role was not found.';
        }

        $slug = (string) ($role['slug'] ?? '');

        if (in_array($slug, ['super-admin', 'super_admin'], true) && $currentRoleId !== $roleId) {
            return 'Super Admin cannot be assigned from this screen.';
        }

        return null;
    }

    /**
     * Non–super-admins cannot edit or delete a super-admin account (email/password takeover).
     *
     * @param array<string, mixed> $target
     */
    protected function assertManageableUser(array $target): ?string
    {
        if ($this->actorIsSuperAdmin()) {
            return null;
        }

        $role = model(RoleModel::class)->find((int) ($target['role_id'] ?? 0));
        if (in_array((string) ($role['slug'] ?? ''), ['super-admin', 'super_admin'], true)) {
            return 'Only a super admin can change a super-admin account.';
        }

        return null;
    }

    protected function actorIsSuperAdmin(): bool
    {
        $actorSlug = (string) (session('role_slug') ?? '');

        return in_array($actorSlug, ['super-admin', 'super_admin'], true)
            || (function_exists('can') && can('*'));
    }
}
