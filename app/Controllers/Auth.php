<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\ActivityLogger;
use App\Models\PasswordResetModel;
use App\Models\RoleModel;
use App\Models\UserModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Authentication: login, logout, password reset.
 */
class Auth extends BaseController
{
    protected int $maxLoginAttempts = 5;
    protected int $lockoutSeconds   = 900; // 15 minutes

    public function login(): string|ResponseInterface
    {
        if ($this->session->get('user_id')) {
            return redirect()->to('/dashboard');
        }

        if (strtolower($this->request->getMethod()) === 'post') {
            return $this->attemptLogin();
        }

        return view('auth/login', [
            'pageTitle' => 'Login',
            'csrfName'  => csrf_token(),
            'csrfToken' => csrf_hash(),
        ]);
    }

    protected function attemptLogin(): ResponseInterface
    {
        $ip  = $this->request->getIPAddress() ?: 'unknown';
        $key = 'login_attempts_' . md5($ip);

        if ($this->isRateLimited($key)) {
            return redirect()->back()->withInput()->with(
                'error',
                'Too many login attempts. Please try again in 15 minutes.'
            );
        }

        $rules = [
            'email'    => 'required|valid_email',
            'password' => 'required|min_length[6]',
        ];

        if (! $this->validate($rules)) {
            $this->incrementAttempts($key);

            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $email    = (string) $this->request->getPost('email');
        $password = (string) $this->request->getPost('password');

        $users = model(UserModel::class);
        $user  = $users->findByEmail($email);

        if ($user === null || ! password_verify($password, (string) ($user['password'] ?? ''))) {
            $this->incrementAttempts($key);

            return redirect()->back()->withInput()->with('error', 'Invalid email or password.');
        }

        if (($user['status'] ?? '') !== 'active') {
            return redirect()->back()->withInput()->with('error', 'Your account is inactive. Contact an administrator.');
        }

        $this->clearAttempts($key);
        $this->establishSession($user);

        $users->update((int) $user['id'], ['last_login' => date('Y-m-d H:i:s')]);

        (new ActivityLogger())->log('login', 'auth', 'User logged in', ['user_id' => $user['id']]);

        $redirect = (string) ($this->session->getFlashdata('redirect_after_login') ?: '/dashboard');

        return redirect()->to($redirect)->with('success', 'Welcome back, ' . ($user['name'] ?? 'User') . '!');
    }

    /**
     * @param array<string, mixed> $user
     */
    protected function establishSession(array $user): void
    {
        $roleModel   = model(RoleModel::class);
        $role        = $roleModel->find((int) ($user['role_id'] ?? 0));
        $permissions = [];

        if ($role !== null) {
            $permRows = $roleModel->getPermissions((int) $role['id']);
            $permissions = array_values(array_filter(array_map(
                static fn (array $p): string => (string) ($p['slug'] ?? ''),
                $permRows
            )));

            if (in_array($role['slug'] ?? '', ['super-admin', 'super_admin'], true)) {
                $permissions = ['*'];
            }
        }

        $this->session->regenerate(true);
        $this->session->set([
            'user_id'     => (int) $user['id'],
            'user_name'   => (string) ($user['name'] ?? ''),
            'user_email'  => (string) ($user['email'] ?? ''),
            'user_avatar' => $user['avatar'] ?? null,
            'role_id'     => $user['role_id'] ?? null,
            'role_name'   => $role['name'] ?? null,
            'role_slug'   => $role['slug'] ?? null,
            'permissions' => $permissions,
            'logged_in'   => true,
        ]);
    }

    public function logout(): ResponseInterface
    {
        if ($this->session->get('user_id')) {
            (new ActivityLogger())->log('logout', 'auth', 'User logged out');
        }

        $this->session->destroy();

        return redirect()->to('/login')->with('success', 'You have been logged out.');
    }

    public function forgotPassword(): string|ResponseInterface
    {
        if (strtolower($this->request->getMethod()) === 'post') {
            return $this->sendResetLink();
        }

        return view('auth/forgot_password', [
            'pageTitle' => 'Forgot Password',
            'csrfName'  => csrf_token(),
            'csrfToken' => csrf_hash(),
        ]);
    }

    protected function sendResetLink(): ResponseInterface
    {
        if (! $this->validate(['email' => 'required|valid_email'])) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $email = (string) $this->request->getPost('email');
        $user  = model(UserModel::class)->findByEmail($email);

        // Always show success to avoid email enumeration
        if ($user !== null) {
            $token = model(PasswordResetModel::class)->createToken($email);
            $link  = site_url('reset-password/' . $token) . '?email=' . rawurlencode($email);

            $this->sendPasswordResetEmail($email, (string) ($user['name'] ?? ''), $link);
        }

        return redirect()->to('/login')->with(
            'success',
            'If that email exists in our system, a password reset link has been sent.'
        );
    }

    public function resetPassword(string $token): string|ResponseInterface
    {
        $email = (string) ($this->request->getGet('email') ?? $this->request->getPost('email') ?? '');

        if (strtolower($this->request->getMethod()) === 'post') {
            return $this->processReset($token);
        }

        $valid = $email !== '' && model(PasswordResetModel::class)->verifyToken($email, $token);

        return view('auth/reset_password', [
            'pageTitle' => 'Reset Password',
            'token'     => $token,
            'email'     => $email,
            'valid'     => $valid,
            'csrfName'  => csrf_token(),
            'csrfToken' => csrf_hash(),
        ]);
    }

    protected function processReset(string $token): ResponseInterface
    {
        $rules = [
            'email'            => 'required|valid_email',
            'password'         => 'required|min_length[8]',
            'password_confirm' => 'required|matches[password]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $email = (string) $this->request->getPost('email');
        $resets = model(PasswordResetModel::class);

        if (! $resets->verifyToken($email, $token)) {
            return redirect()->to('/forgot-password')->with('error', 'Invalid or expired reset token.');
        }

        $users = model(UserModel::class);
        $user  = $users->findByEmail($email);

        if ($user === null) {
            return redirect()->to('/forgot-password')->with('error', 'User not found.');
        }

        $users->update((int) $user['id'], [
            'password' => (string) $this->request->getPost('password'),
        ]);

        $resets->deleteByEmail($email);

        (new ActivityLogger())->log('password_reset', 'auth', 'Password reset completed', [
            'user_id' => $user['id'],
        ]);

        return redirect()->to('/login')->with('success', 'Password updated. You can now log in.');
    }

    protected function sendPasswordResetEmail(string $email, string $name, string $link): void
    {
        try {
            $message = "Hello {$name},\n\n" .
                "Click the link below to reset your password (valid for 60 minutes):\n\n" .
                "{$link}\n\n" .
                "If you did not request this, ignore this email.\n";

            $result = service('emailProvider')->send($email, 'Password Reset Request', $message);

            if (! ($result['ok'] ?? false)) {
                log_message('error', 'Password reset email failed: {msg}', [
                    'msg' => $result['message'] ?? 'unknown',
                ]);
            }
        } catch (\Throwable $e) {
            log_message('error', 'Password reset email exception: {msg}', ['msg' => $e->getMessage()]);
        }
    }

    protected function isRateLimited(string $key): bool
    {
        $cache = cache();
        $data  = $cache->get($key);

        if (! is_array($data)) {
            return false;
        }

        $attempts = (int) ($data['attempts'] ?? 0);
        $lockedAt = (int) ($data['locked_at'] ?? 0);

        if ($attempts >= $this->maxLoginAttempts) {
            if ($lockedAt > 0 && (time() - $lockedAt) < $this->lockoutSeconds) {
                return true;
            }
            $cache->delete($key);
        }

        return false;
    }

    protected function incrementAttempts(string $key): void
    {
        $cache    = cache();
        $data     = $cache->get($key);
        $attempts = is_array($data) ? ((int) ($data['attempts'] ?? 0) + 1) : 1;
        $payload  = [
            'attempts'  => $attempts,
            'locked_at' => $attempts >= $this->maxLoginAttempts ? time() : 0,
        ];

        $cache->save($key, $payload, $this->lockoutSeconds);
    }

    protected function clearAttempts(string $key): void
    {
        cache()->delete($key);
    }
}
