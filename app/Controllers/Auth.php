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
        if ($this->session->get('platform_admin_id')) {
            return redirect()->to('/platform/clients');
        }

        if (strtolower($this->request->getMethod()) === 'post') {
            return $this->attemptLogin();
        }

        // Normal email/password login. Optional: ?tenant=_platform or ?tenant={clientKey}.
        $selectedKey    = strtolower(trim((string) ($this->request->getGet('tenant') ?? '')));
        $selectedTenant = null;

        if ($selectedKey !== '' && $selectedKey !== '_email' && $selectedKey !== '_platform'
            && \App\Libraries\MasterTenantRepository::masterConfigured()) {
            $selectedTenant = (new \App\Libraries\MasterTenantRepository())->findActiveTenant($selectedKey);
            if ($selectedTenant === null) {
                return redirect()->to('/login')->with('error', 'Client not found or inactive.');
            }
        }

        return view('auth/login', [
            'pageTitle'         => 'Login',
            'csrfName'          => csrf_token(),
            'csrfToken'         => csrf_hash(),
            'selectedTenantKey' => $selectedKey,
            'selectedTenant'    => $selectedTenant,
        ]);
    }

    public function signup(): string|ResponseInterface
    {
        if ($this->session->get('user_id')) {
            return redirect()->to('/dashboard');
        }

        if (strtolower($this->request->getMethod()) === 'post') {
            return $this->attemptSignup();
        }

        return view('auth/signup', [
            'pageTitle' => 'Sign Up',
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
        $postedTenant = strtolower(trim((string) $this->request->getPost('tenant_key')));

        // Platform super admin (master.platform_admins) — manages all clients.
        if (\App\Libraries\MasterTenantRepository::masterConfigured()) {
            $platformAdmin = (new \App\Libraries\MasterTenantRepository())->findPlatformAdminByEmail($email);
            if (
                is_array($platformAdmin)
                && strtolower((string) ($platformAdmin['status'] ?? '')) === 'active'
                && password_verify($password, (string) ($platformAdmin['password'] ?? ''))
            ) {
                $this->clearAttempts($key);
                $this->session->regenerate(true);
                $this->session->set([
                    'platform_admin_id'    => (int) ($platformAdmin['id'] ?? 0),
                    'platform_admin_email' => (string) ($platformAdmin['email'] ?? $email),
                    'platform_admin_name'  => (string) ($platformAdmin['name'] ?? 'Platform Super Admin'),
                    'logged_in'            => true,
                ]);
                (new ActivityLogger())->log('login', 'platform', 'Platform super admin logged in', [
                    'email' => $email,
                ]);

                return redirect()->to('/platform/clients')->with(
                    'success',
                    'Welcome, ' . ($platformAdmin['name'] ?? 'Platform Super Admin') . '!'
                );
            }
        }

        // Portal multi-client: prefer explicit client from list, else email → tenant index.
        $tenantKey = null;
        if (\App\Libraries\MasterTenantRepository::masterConfigured()) {
            $repo = new \App\Libraries\MasterTenantRepository();
            if ($postedTenant !== '') {
                if ($repo->findActiveTenant($postedTenant) === null) {
                    return redirect()->to('/login')->with('error', 'Client not found or inactive.');
                }
                $tenantKey = $postedTenant;
            } else {
                $tenantKey = $repo->findTenantKeyByEmail($email);
            }

            if ($tenantKey !== null) {
                if (! (new \App\Libraries\TenantConnection())->apply($tenantKey, 'login')) {
                    return redirect()->back()->withInput()->with(
                        'error',
                        'Unable to connect to your workspace. Contact support.'
                    );
                }
            }
        }

        $users = model(UserModel::class);
        $user  = $users->findByEmail($email);

        if ($user === null || ! password_verify($password, (string) ($user['password'] ?? ''))) {
            $this->incrementAttempts($key);

            return redirect()->back()->withInput()->with('error', 'Invalid email or password.');
        }

        if (($user['status'] ?? '') !== 'active') {
            return redirect()->back()->withInput()->with('error', 'Your account is inactive. Contact an administrator.');
        }

        if (! $this->isEmailVerified($user)) {
            $this->maybeResendVerificationEmail($user);

            return redirect()->to('/resend-verification?email=' . rawurlencode($email))->with(
                'warning',
                'Verify your email before signing in. We sent you a fresh verification link if needed.'
            );
        }

        $this->clearAttempts($key);
        $this->establishSession($user, $tenantKey ?? \App\Libraries\TenantContext::get());

        $users->update((int) $user['id'], ['last_login' => date('Y-m-d H:i:s')]);

        (new ActivityLogger())->log('login', 'auth', 'User logged in', ['user_id' => $user['id']]);

        $redirect = (string) ($this->session->getFlashdata('redirect_after_login') ?: '/dashboard');

        return redirect()->to($redirect)->with('success', 'Welcome back, ' . ($user['name'] ?? 'User') . '!');
    }

    /**
     * @param array<string, mixed> $user
     */
    protected function establishSession(array $user, ?string $tenantKey = null): void
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

        $tenantKey = $tenantKey ?? \App\Libraries\TenantContext::get();

        $this->session->regenerate(true);
        $sessionData = [
            'user_id'     => (int) $user['id'],
            'user_name'   => (string) ($user['name'] ?? ''),
            'user_email'  => (string) ($user['email'] ?? ''),
            'user_avatar' => $user['avatar'] ?? null,
            'role_id'     => $user['role_id'] ?? null,
            'role_name'   => $role['name'] ?? null,
            'role_slug'   => $role['slug'] ?? null,
            'permissions' => $permissions,
            'logged_in'   => true,
        ];
        if ($tenantKey !== null && $tenantKey !== '') {
            $sessionData['tenant_key'] = strtolower($tenantKey);
        }
        $this->session->set($sessionData);
    }

    protected function attemptSignup(): ResponseInterface
    {
        $rules = [
            'name'             => 'required|min_length[2]|max_length[150]',
            'email'            => 'required|valid_email|is_unique[users.email]',
            'password'         => 'required|min_length[8]',
            'password_confirm' => 'required|matches[password]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $role = $this->resolveSignupRole();
        if ($role === null) {
            return redirect()->back()->withInput()->with(
                'error',
                'Signup is not available right now. Please contact an administrator.'
            );
        }

        $users = model(UserModel::class);
        $token = bin2hex(random_bytes(32));
        $id    = $users->insert([
            'name'                    => (string) $this->request->getPost('name'),
            'email'                   => (string) $this->request->getPost('email'),
            'password'                => (string) $this->request->getPost('password'),
            'role_id'                 => (int) $role['id'],
            'status'                  => 'active',
            'email_verification_token'=> $token,
            'email_verification_sent_at' => date('Y-m-d H:i:s'),
            'email_verified_at'       => null,
        ]);

        if (! $id) {
            return redirect()->back()->withInput()->with('errors', $users->errors());
        }

        $user = $users->find((int) $id);
        if (is_array($user)) {
            $this->sendVerificationEmail($user);
        }

        (new ActivityLogger())->log('signup', 'auth', 'User self-registered', ['user_id' => $id]);

        return redirect()->to('/login')->with(
            'success',
            'Account created successfully. Please verify your email before signing in.'
        );
    }

    public function verifyEmail(string $token): ResponseInterface
    {
        $users = model(UserModel::class);
        $user  = $users->findByVerificationToken($token);

        if ($user === null) {
            return redirect()->to('/login')->with('error', 'Invalid or expired verification link.');
        }

        if (! $this->isEmailVerified($user)) {
            $users->update((int) $user['id'], [
                'email_verified_at'        => date('Y-m-d H:i:s'),
                'email_verification_token' => null,
            ]);

            (new ActivityLogger())->log('verify_email', 'auth', 'User verified email address', [
                'user_id' => $user['id'],
            ]);
        }

        return redirect()->to('/login')->with('success', 'Email verified successfully. You can now sign in.');
    }

    public function resendVerification(): string|ResponseInterface
    {
        if (strtolower($this->request->getMethod()) === 'post') {
            return $this->processResendVerification();
        }

        return view('auth/resend_verification', [
            'pageTitle' => 'Resend Verification',
            'csrfName'  => csrf_token(),
            'csrfToken' => csrf_hash(),
            'email'     => (string) ($this->request->getGet('email') ?? ''),
        ]);
    }

    public function logout(): ResponseInterface
    {
        if ($this->session->get('user_id') || $this->session->get('platform_admin_id')) {
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

    /**
     * @param array<string, mixed> $user
     */
    protected function sendVerificationEmail(array $user): void
    {
        $token = trim((string) ($user['email_verification_token'] ?? ''));
        $email = trim((string) ($user['email'] ?? ''));

        if ($token === '' || $email === '') {
            return;
        }

        $name = trim((string) ($user['name'] ?? 'there'));
        $link = site_url('verify-email/' . rawurlencode($token));

        try {
            $message = "Hello {$name},\n\n" .
                "Thanks for signing up. Click the link below to verify your email address:\n\n" .
                "{$link}\n\n" .
                "After verification you can sign in to your account.\n";

            $result = service('emailProvider')->send($email, 'Verify Your Email Address', $message);

            if (! ($result['ok'] ?? false)) {
                log_message('error', 'Verification email failed: {msg}', [
                    'msg' => $result['message'] ?? 'unknown',
                    'email' => $email,
                ]);
            }
        } catch (\Throwable $e) {
            log_message('error', 'Verification email exception: {msg}', ['msg' => $e->getMessage()]);
        }
    }

    protected function processResendVerification(): ResponseInterface
    {
        if (! $this->validate(['email' => 'required|valid_email'])) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $users = model(UserModel::class);
        $email = (string) $this->request->getPost('email');
        $user  = $users->findByEmail($email);

        if ($user === null) {
            return redirect()->to('/login')->with('info', 'If that email exists, a verification link has been sent.');
        }

        if ($this->isEmailVerified($user)) {
            return redirect()->to('/login')->with('success', 'This email is already verified. You can sign in.');
        }

        $token = bin2hex(random_bytes(32));
        $users->update((int) $user['id'], [
            'email_verification_token'   => $token,
            'email_verification_sent_at' => date('Y-m-d H:i:s'),
        ]);

        $freshUser = $users->find((int) $user['id']);
        if (is_array($freshUser)) {
            $this->sendVerificationEmail($freshUser);
        }

        return redirect()->to('/login')->with('success', 'Verification email sent. Please check your inbox.');
    }

    /**
     * @param array<string, mixed> $user
     */
    protected function isEmailVerified(array $user): bool
    {
        return trim((string) ($user['email_verified_at'] ?? '')) !== '';
    }

    /**
     * @param array<string, mixed> $user
     */
    protected function maybeResendVerificationEmail(array $user): void
    {
        $lastSent = trim((string) ($user['email_verification_sent_at'] ?? ''));
        if ($lastSent !== '' && strtotime($lastSent) !== false && (time() - (int) strtotime($lastSent)) < 300) {
            return;
        }

        $users = model(UserModel::class);
        $token = trim((string) ($user['email_verification_token'] ?? ''));
        if ($token === '') {
            $token = bin2hex(random_bytes(32));
        }

        $users->update((int) $user['id'], [
            'email_verification_token'   => $token,
            'email_verification_sent_at' => date('Y-m-d H:i:s'),
        ]);

        $freshUser = $users->find((int) $user['id']);
        if (is_array($freshUser)) {
            $this->sendVerificationEmail($freshUser);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolveSignupRole(): ?array
    {
        $roles = model(RoleModel::class);

        return $roles->findBySlug('agent')
            ?? $roles->findBySlug('manager')
            ?? $roles->findBySlug('admin')
            ?? $roles->first();
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
