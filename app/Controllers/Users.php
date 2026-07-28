<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\ActivityLogger;
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

        $users = db_connect()->table('users u')
            ->select('u.id, u.name, u.email, u.phone, u.status, u.last_login, u.created_at, r.name AS role_name, r.slug AS role_slug')
            ->join('roles r', 'r.id = u.role_id', 'left')
            ->where('u.deleted_at', null)
            ->orderBy('u.name', 'ASC')
            ->get()
            ->getResultArray();

        return $this->render('users/index', [
            'pageTitle' => 'Users',
            'users'     => $users,
        ]);
    }

    public function create(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('users.create')) {
            return $denied;
        }

        return $this->render('users/form', [
            'pageTitle' => 'Create User',
            'user'      => null,
            'roles'     => model(RoleModel::class)->orderBy('name', 'ASC')->findAll(),
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
            'user'      => $user,
            'roles'     => model(RoleModel::class)->orderBy('name', 'ASC')->findAll(),
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
        if ($err = $this->assertAssignableRole($roleId)) {
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

        $model->update($id, $data);

        (new ActivityLogger())->log('update', 'users', 'User updated', ['user_id' => $id]);

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

        $model = model(UserModel::class);
        if ($model->find($id) === null) {
            return redirect()->to('/users')->with('error', 'User not found.');
        }

        $model->delete($id);
        (new ActivityLogger())->log('delete', 'users', 'User deleted', ['user_id' => $id]);

        if ($this->request->isAJAX()) {
            return $this->jsonResponse(true, null, 'User deleted.');
        }

        return redirect()->to('/users')->with('success', 'User deleted.');
    }

    /**
     * Non–super-admins cannot assign super-admin (or missing) roles.
     */
    protected function assertAssignableRole(int $roleId): ?string
    {
        $role = model(RoleModel::class)->find($roleId);
        if ($role === null) {
            return 'Selected role was not found.';
        }

        $slug = (string) ($role['slug'] ?? '');
        $actorSlug = (string) (session('role_slug') ?? '');
        $isSuper = in_array($actorSlug, ['super-admin', 'super_admin'], true)
            || (function_exists('can') && can('*'));

        if (in_array($slug, ['super-admin', 'super_admin'], true) && ! $isSuper) {
            return 'Only a super admin can assign the super-admin role.';
        }

        return null;
    }
}
