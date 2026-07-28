<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\RoleModel;
use App\Models\UserModel;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use Throwable;

/**
 * Public installation wizard.
 */
class Install extends Controller
{
    /**
     * @var list<string>
     */
    protected $helpers = ['form', 'url'];

    /**
     * @var list<string>
     */
    protected array $steps = ['welcome', 'requirements', 'database', 'migrate', 'admin', 'cheerio', 'finish'];

    public function index(): string|ResponseInterface
    {
        if ($this->alreadyInstalled()) {
            return redirect()->to('/login')->with('info', 'Application is already installed.');
        }

        return view('install/welcome', [
            'pageTitle' => 'Install — Welcome',
            'step'      => 'welcome',
            'steps'     => $this->steps,
        ]);
    }

    public function requirements(): string|ResponseInterface
    {
        if ($this->alreadyInstalled()) {
            return redirect()->to('/login');
        }

        $checks = $this->runRequirementsCheck();
        $ok     = ! in_array(false, array_column($checks, 'pass'), true);

        return view('install/requirements', [
            'pageTitle' => 'Install — Requirements',
            'step'      => 'requirements',
            'steps'     => $this->steps,
            'checks'    => $checks,
            'allPassed' => $ok,
        ]);
    }

    public function database(): string|ResponseInterface
    {
        if ($this->alreadyInstalled()) {
            return redirect()->to('/login');
        }

        if (strtolower($this->request->getMethod()) === 'post') {
            return $this->saveDatabase();
        }

        return view('install/database', [
            'pageTitle' => 'Install — Database',
            'step'      => 'database',
            'steps'     => $this->steps,
            'csrfName'  => csrf_token(),
            'csrfToken' => csrf_hash(),
            'defaults'  => [
                'hostname' => 'localhost',
                'username' => 'root',
                'password' => '',
                'database' => 'apiwa',
                'DBDriver' => 'MySQLi',
                'port'     => '3306',
            ],
            'db'        => [
                'hostname' => 'localhost',
                'username' => 'root',
                'password' => '',
                'database' => 'apiwa',
                'port'     => '3306',
            ],
            'baseURL'   => rtrim(site_url(), '/') . '/',
        ]);
    }

    protected function saveDatabase(): ResponseInterface
    {
        $rules = [
            'hostname' => 'required',
            'username' => 'required',
            'database' => 'required',
            'port'     => 'permit_empty|is_natural_no_zero',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $hostname = (string) $this->request->getPost('hostname');
        $username = (string) $this->request->getPost('username');
        $password = (string) $this->request->getPost('password');
        $database = (string) $this->request->getPost('database');
        $port     = (string) ($this->request->getPost('port') ?: '3306');
        $driver   = (string) ($this->request->getPost('DBDriver') ?: 'MySQLi');

        // Test connection
        try {
            $mysqli = @new \mysqli($hostname, $username, $password, '', (int) $port);
            if ($mysqli->connect_error) {
                return redirect()->back()->withInput()->with('error', 'Connection failed: ' . $mysqli->connect_error);
            }
            $mysqli->query('CREATE DATABASE IF NOT EXISTS `' . $mysqli->real_escape_string($database) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
            $mysqli->select_db($database);
            $mysqli->close();
        } catch (Throwable $e) {
            return redirect()->back()->withInput()->with('error', 'Connection failed: ' . $e->getMessage());
        }

        $envPath = ROOTPATH . '.env';
        // Prefer updating the existing .env so local keys (JWT, encryption) are preserved.
        if (is_file($envPath)) {
            $content = (string) file_get_contents($envPath);
        } else {
            // Prefer project .env.example over CodeIgniter's generic `env` template.
            $example = is_file(ROOTPATH . '.env.example')
                ? file_get_contents(ROOTPATH . '.env.example')
                : (is_file(ROOTPATH . 'env') ? file_get_contents(ROOTPATH . 'env') : '');
            $content = is_string($example) && $example !== '' ? $example : "CI_ENVIRONMENT = development\n\n";
        }

        $baseURL = (string) ($this->request->getPost('baseURL') ?: (rtrim(site_url(), '/') . '/'));
        if ($baseURL !== '' && ! str_ends_with($baseURL, '/')) {
            $baseURL .= '/';
        }

        $replacements = [
            'database.default.hostname' => $hostname,
            'database.default.database' => $database,
            'database.default.username' => $username,
            'database.default.password' => $password,
            'database.default.DBDriver' => $driver,
            'database.default.port'     => $port,
            'app.baseURL'               => $baseURL,
        ];

        foreach ($replacements as $key => $value) {
            $pattern = '/^#?\s*' . preg_quote($key, '/') . '\s*=.*$/m';
            $line    = $key . ' = ' . $this->envQuote($value);
            if (preg_match($pattern, $content)) {
                $content = preg_replace($pattern, $line, $content) ?? $content;
            } else {
                $content .= "\n" . $line;
            }
        }

        // Ensure encryption key
        if (! preg_match('/^encryption\.key\s*=\s*.+$/m', $content) || preg_match('/^#?\s*encryption\.key\s*=\s*$/m', $content)) {
            $key = 'hex2bin:' . bin2hex(random_bytes(32));
            if (preg_match('/^#?\s*encryption\.key\s*=.*$/m', $content)) {
                $content = preg_replace('/^#?\s*encryption\.key\s*=.*$/m', 'encryption.key = ' . $key, $content) ?? $content;
            } else {
                $content .= "\nencryption.key = " . $key;
            }
        }

        if (file_put_contents($envPath, $content) === false) {
            return redirect()->back()->withInput()->with('error', 'Unable to write .env file. Check permissions.');
        }

        session()->set('install_db_ok', true);

        return redirect()->to('/install/migrate')->with('success', 'Database configuration saved.');
    }

    public function migrate(): string|ResponseInterface
    {
        if ($this->alreadyInstalled()) {
            return redirect()->to('/login');
        }

        if (strtolower($this->request->getMethod()) === 'post') {
            return $this->runMigrate();
        }

        return view('install/migrate', [
            'pageTitle' => 'Install — Migrations',
            'step'      => 'migrate',
            'steps'     => $this->steps,
            'csrfName'  => csrf_token(),
            'csrfToken' => csrf_hash(),
        ]);
    }

    protected function runMigrate(): ResponseInterface
    {
        try {
            $migrate = service('migrations');
            $migrate->setNamespace('App');
            $migrate->latest();

            $seeder = Database::seeder();
            $seeder->call('DatabaseSeeder');

            session()->set('install_migrated', true);

            return redirect()->to('/install/admin')->with('success', 'Database migrated and seeded.');
        } catch (Throwable $e) {
            log_message('error', 'Install migrate failed: {msg}', ['msg' => $e->getMessage()]);

            return redirect()->back()->with('error', 'Migration failed: ' . $e->getMessage());
        }
    }

    public function admin(): string|ResponseInterface
    {
        if ($this->alreadyInstalled()) {
            return redirect()->to('/login');
        }

        if (strtolower($this->request->getMethod()) === 'post') {
            return $this->saveAdmin();
        }

        return view('install/admin', [
            'pageTitle' => 'Install — Admin Account',
            'step'      => 'admin',
            'steps'     => $this->steps,
            'csrfName'  => csrf_token(),
            'csrfToken' => csrf_hash(),
        ]);
    }

    protected function saveAdmin(): ResponseInterface
    {
        $rules = [
            'name'             => 'required|min_length[2]',
            'email'            => 'required|valid_email',
            'password'         => 'required|min_length[8]',
            'password_confirm' => 'required|matches[password]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        try {
            $role = model(RoleModel::class)->findBySlug('super-admin')
                ?? model(RoleModel::class)->findBySlug('super_admin')
                ?? model(RoleModel::class)->findBySlug('admin')
                ?? model(RoleModel::class)->first();

            if ($role === null) {
                return redirect()->back()->with('error', 'No roles found. Run migrations/seeds first.');
            }

            $users = model(UserModel::class);
            $email = (string) $this->request->getPost('email');
            $existing = $users->findByEmail($email);

            $data = [
                'name'     => $this->request->getPost('name'),
                'email'    => $email,
                'password' => $this->request->getPost('password'),
                'role_id'  => (int) $role['id'],
                'status'   => 'active',
            ];

            if ($existing !== null) {
                $users->update((int) $existing['id'], $data);
            } else {
                $users->insert($data);
            }

            session()->set('install_admin_ok', true);

            return redirect()->to('/install/cheerio')->with('success', 'Admin account created.');
        } catch (Throwable $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function cheerio(): string|ResponseInterface
    {
        if ($this->alreadyInstalled()) {
            return redirect()->to('/login');
        }

        if (strtolower($this->request->getMethod()) === 'post') {
            return $this->saveCheerio();
        }

        return view('install/cheerio', [
            'pageTitle' => 'Install — WhatsApp Provider',
            'step'      => 'cheerio',
            'steps'     => $this->steps,
            'csrfName'  => csrf_token(),
            'csrfToken' => csrf_hash(),
            'webhookUrl'=> site_url('webhooks'),
        ]);
    }

    /**
     * @deprecated Use cheerio()
     */
    public function meta(): string|ResponseInterface
    {
        return $this->cheerio();
    }

    protected function saveCheerio(): ResponseInterface
    {
        try {
            $settings = service('settingsService');
            $provider = strtolower(trim((string) $this->request->getPost('whatsapp_provider'))) ?: 'cheerio';
            if (! in_array($provider, ['cheerio', 'meta'], true)) {
                $provider = 'cheerio';
            }
            $settings->setWhatsAppProvider($provider);

            if ($provider === 'meta') {
                $settings->setMetaConfig([
                    'access_token'    => (string) $this->request->getPost('meta_access_token'),
                    'phone_number_id' => (string) $this->request->getPost('meta_phone_number_id'),
                    'waba_id'         => (string) $this->request->getPost('meta_waba_id'),
                    'api_version'     => (string) ($this->request->getPost('meta_api_version') ?: 'v21.0'),
                    'verify_token'    => (string) (
                        $this->request->getPost('meta_webhook_verify_token') ?: bin2hex(random_bytes(16))
                    ),
                    'app_secret'      => (string) $this->request->getPost('meta_webhook_secret'),
                ]);
            } else {
                $settings->setCheerioConfig([
                    'api_key'        => (string) $this->request->getPost('cheerio_api_key'),
                    'verify_token'   => (string) (
                        $this->request->getPost('cheerio_webhook_verify_token')
                        ?: bin2hex(random_bytes(16))
                    ),
                    'webhook_secret' => (string) $this->request->getPost('cheerio_webhook_secret'),
                ]);
            }

            $appName = (string) ($this->request->getPost('app_name') ?: 'WhatsApp Automation');
            $settings->set('app_name', $appName, 'general');
            $settings->set('app_timezone', (string) ($this->request->getPost('app_timezone') ?: 'UTC'), 'general');

            session()->set('install_cheerio_ok', true);

            $skip = (string) $this->request->getPost('skip') === '1';
            if ($skip) {
                return redirect()->to('/install/finish');
            }

            return redirect()->to('/install/finish')->with(
                'success',
                ($provider === 'meta' ? 'Meta' : 'Cheerio') . ' API settings saved.'
            );
        } catch (Throwable $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * @deprecated Use saveCheerio()
     */
    protected function saveMeta(): ResponseInterface
    {
        return $this->saveCheerio();
    }

    public function finish(): string|ResponseInterface
    {
        if ($this->alreadyInstalled()) {
            return redirect()->to('/login');
        }

        if (strtolower($this->request->getMethod()) === 'post') {
            try {
                service('settingsService')->set('app_installed', '1', 'general');
                $this->writeInstallLock();
                session()->remove(['install_db_ok', 'install_migrated', 'install_admin_ok', 'install_cheerio_ok', 'install_meta_ok']);

                return redirect()->to('/login')->with('success', 'Installation complete. Please log in.');
            } catch (Throwable $e) {
                return redirect()->back()->with('error', $e->getMessage());
            }
        }

        return view('install/finish', [
            'pageTitle' => 'Install — Finish',
            'step'      => 'finish',
            'steps'     => $this->steps,
            'csrfName'  => csrf_token(),
            'csrfToken' => csrf_hash(),
        ]);
    }

    /**
     * @return list<array{label:string,pass:bool,detail:string}>
     */
    protected function runRequirementsCheck(): array
    {
        $checks = [];

        $checks[] = [
            'label'  => 'PHP version >= 8.2',
            'pass'   => version_compare(PHP_VERSION, '8.2.0', '>='),
            'detail' => 'Current: ' . PHP_VERSION,
        ];

        foreach (['intl', 'json', 'mbstring', 'curl', 'openssl', 'mysqli'] as $ext) {
            $checks[] = [
                'label'  => "PHP extension: {$ext}",
                'pass'   => extension_loaded($ext),
                'detail' => extension_loaded($ext) ? 'Loaded' : 'Missing',
            ];
        }

        $writable = [
            WRITEPATH,
            WRITEPATH . 'cache',
            WRITEPATH . 'logs',
            WRITEPATH . 'session',
            WRITEPATH . 'uploads',
            ROOTPATH,
        ];

        foreach ($writable as $path) {
            if (! is_dir($path)) {
                @mkdir($path, 0755, true);
            }
            $checks[] = [
                'label'  => 'Writable: ' . $path,
                'pass'   => is_dir($path) && is_writable($path),
                'detail' => is_writable($path) ? 'OK' : 'Not writable',
            ];
        }

        return $checks;
    }

    protected function alreadyInstalled(): bool
    {
        // Filesystem lock — fail closed even if DB is down (prevents re-install attacks)
        if (is_file(WRITEPATH . 'install.lock')) {
            return true;
        }

        try {
            return service('settingsService')->isInstalled();
        } catch (Throwable $e) {
            return false;
        }
    }

    protected function writeInstallLock(): void
    {
        $path = WRITEPATH . 'install.lock';
        $body = "installed_at=" . date('c') . "\n" . 'host=' . ($_SERVER['HTTP_HOST'] ?? 'cli') . "\n";
        @file_put_contents($path, $body);
    }

    protected function envQuote(string $value): string
    {
        if ($value === '' || preg_match('/[\s#"\']/', $value)) {
            return '"' . str_replace('"', '\\"', $value) . '"';
        }

        return $value;
    }
}
