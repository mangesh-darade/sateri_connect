<?php

declare(strict_types=1);

/**
 * Deep multi-module / multi-screen health check.
 *
 * Covers: PHP syntax, DB connectivity + critical tables/columns,
 * routes→controller methods, view files, and key library methods.
 *
 * Run: php tests/DeepAllScreensHealthTest.php
 */

define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);
chdir(FCPATH);

require FCPATH . '../app/Config/Paths.php';
$paths = new \Config\Paths();
require $paths->systemDirectory . '/Boot.php';
\CodeIgniter\Boot::bootSpark($paths);

$pass = 0;
$fail = 0;
$warn = 0;
$failures = [];
$warnings = [];

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $failures;
    if ($ok) {
        $pass++;
        echo "[PASS] {$label}\n";
        return;
    }
    $fail++;
    $msg = $label . ($detail !== '' ? " — {$detail}" : '');
    $failures[] = $msg;
    echo "[FAIL] {$msg}\n";
}

function warn(string $label, string $detail = ''): void
{
    global $warn, $warnings;
    $warn++;
    $msg = $label . ($detail !== '' ? " — {$detail}" : '');
    $warnings[] = $msg;
    echo "[WARN] {$msg}\n";
}

function section(string $title): void
{
    echo "\n=== {$title} ===\n";
}

$root = dirname(FCPATH);
$php  = PHP_BINARY;

section('1) PHP SYNTAX (app Controllers / Libraries / Models / Commands / Config)');

$lintDirs = [
    'app/Controllers',
    'app/Libraries',
    'app/Models',
    'app/Commands',
    'app/Config',
    'app/Helpers',
];
$syntaxFail = 0;
foreach ($lintDirs as $rel) {
    $dir = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (! is_dir($dir)) {
        warn("missing dir {$rel}");
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        $out = [];
        $code = 0;
        exec(escapeshellarg($php) . ' -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
        if ($code !== 0) {
            $syntaxFail++;
            check('php -l ' . str_replace($root . DIRECTORY_SEPARATOR, '', $path), false, implode(' ', $out));
        }
    }
}
if ($syntaxFail === 0) {
    check('All scanned PHP files syntax OK', true);
}

section('2) VIEW SYNTAX (balanced PHP tags / no obvious parse bombs)');

$viewDir = $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Views';
$viewFail = 0;
$viewCount = 0;
if (is_dir($viewDir)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        $viewCount++;
        $path = $file->getPathname();
        $out = [];
        $code = 0;
        exec(escapeshellarg($php) . ' -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
        if ($code !== 0) {
            $viewFail++;
            check('view lint ' . str_replace($root . DIRECTORY_SEPARATOR, '', $path), false, implode(' ', $out));
        }
    }
}
check("Views linted ({$viewCount})", $viewFail === 0, $viewFail ? "{$viewFail} view syntax errors" : '');

section('3) DATABASE CONNECTIVITY + CRITICAL SCHEMA');

$dbOk = false;
try {
    $db = db_connect();
    $db->query('SELECT 1');
    $dbOk = true;
    check('DB connection', true);
} catch (Throwable $e) {
    check('DB connection', false, $e->getMessage());
}

$criticalTables = [
    'users', 'settings', 'contacts', 'conversations', 'messages',
    'templates', 'campaigns', 'campaign_contacts', 'message_queue',
    'media', 'tags', 'contact_tags', 'activity_logs',
    'automations', 'keywords', 'webhook_logs',
];
$optionalTables = [
    'email_html_campaigns', 'email_logs', 'internal_notes',
    'workflows', 'workflow_runs', 'notifications',
];

if ($dbOk) {
    foreach ($criticalTables as $table) {
        check("table {$table}", $db->tableExists($table));
    }
    foreach ($optionalTables as $table) {
        if (! $db->tableExists($table)) {
            warn("optional table missing: {$table}");
        } else {
            check("optional table {$table}", true);
        }
    }

    $columnChecks = [
        'campaigns' => ['name', 'status', 'message_type', 'template_id', 'payload', 'variables', 'scheduled_at', 'sent_count', 'failed_count'],
        'campaign_contacts' => ['campaign_id', 'contact_id', 'status', 'error_message', 'sent_at'],
        'templates' => ['name', 'language', 'status', 'header_type', 'header_content', 'body', 'raw_payload'],
        'contacts' => ['name', 'mobile', 'email', 'status'],
        'messages' => ['contact_id', 'direction', 'message_type', 'content', 'status'],
        'message_queue' => ['contact_id', 'campaign_id', 'message_type', 'payload', 'status'],
        'media' => ['filename', 'mime_type', 'path', 'wa_media_id', 'url'],
        'settings' => ['key', 'value'],
    ];
    foreach ($columnChecks as $table => $cols) {
        if (! $db->tableExists($table)) {
            continue;
        }
        foreach ($cols as $col) {
            check("{$table}.{$col}", $db->fieldExists($col, $table));
        }
    }

    // Smoke queries (no mutations)
    try {
        $db->table('contacts')->limit(1)->get()->getResultArray();
        check('SELECT contacts smoke', true);
    } catch (Throwable $e) {
        check('SELECT contacts smoke', false, $e->getMessage());
    }
    try {
        $db->table('templates')->where('status', 'APPROVED')->countAllResults();
        check('SELECT approved templates smoke', true);
    } catch (Throwable $e) {
        check('SELECT approved templates smoke', false, $e->getMessage());
    }
    try {
        $db->table('campaigns')->orderBy('id', 'DESC')->limit(1)->get()->getResultArray();
        check('SELECT campaigns smoke', true);
    } catch (Throwable $e) {
        check('SELECT campaigns smoke', false, $e->getMessage());
    }
}

section('4) ROUTES → CONTROLLER METHODS');

$routesFile = $root . '/app/Config/Routes.php';
$routesSrc = file_get_contents($routesFile) ?: '';
check('Routes.php readable', $routesSrc !== '');

// Map common screen modules to expected route fragments / controllers.
$screenMap = [
    'Dashboard'       => ["Dashboard::index", 'dashboard'],
    'Chat / Inbox'    => ["Chat::index", 'chat'],
    'Contacts'        => ["Contacts::index", 'contacts'],
    'Customer Groups' => ['customer-groups', 'CustomerGroups'],
    'Templates'       => ["Templates::index", 'templates'],
    'Campaigns'       => ["Campaigns::index", 'campaigns'],
    'Queue'           => ['queue', 'Queue'],
    'Automations'     => ['automations', 'Automations'],
    'Keywords'        => ['keywords', 'Keywords'],
    'Analytics'       => ["Analytics::index", 'analytics'],
    'Reports'         => ["Reports::index", 'reports'],
    'Settings'        => ["Settings::index", 'settings'],
    'Users'           => ["Users::index", 'users'],
    'Emails'          => ["Emails::index", 'emails'],
    'Email Manager'   => ['email-manager', 'EmailManager'],
    'Media'           => ['media', 'Media'],
    'Notifications'   => ['notifications', 'Notifications'],
];

foreach ($screenMap as $screen => $needles) {
    $ok = false;
    foreach ($needles as $n) {
        if (stripos($routesSrc, $n) !== false) {
            $ok = true;
            break;
        }
    }
    check("route for screen: {$screen}", $ok);
}

// Controller class existence + index/show methods where expected
$controllerChecks = [
    'Dashboard' => ['index'],
    'Chat' => ['index', 'send', 'conversations', 'messages'],
    'Contacts' => ['index', 'show', 'create'],
    'Templates' => ['index', 'show', 'create', 'preview', 'uploadHeaderMedia'],
    'Campaigns' => ['index', 'show', 'wizardData', 'wizardStore', 'wizardRun'],
    'Analytics' => ['index'],
    'Reports' => ['index'],
    'Settings' => ['index'],
    'Users' => ['index'],
    'Queue' => ['index'],
    'Automations' => ['index'],
    'Emails' => ['index'],
];
foreach ($controllerChecks as $class => $methods) {
    $fqcn = '\\App\\Controllers\\' . $class;
    if (! class_exists($fqcn)) {
        // try load
        $file = $root . '/app/Controllers/' . $class . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
    if (! class_exists($fqcn)) {
        check("controller {$class}", false, 'class missing');
        continue;
    }
    check("controller {$class} exists", true);
    foreach ($methods as $m) {
        check("{$class}::{$m}()", method_exists($fqcn, $m));
    }
}

section('5) KEY LIBRARIES / SERVICES');

$libChecks = [
    'App\\Libraries\\CheerioDirectAPI' => ['sendTemplate', 'sendBulkCampaign', 'getCampaignSummary', 'uploadMedia', 'sendText'],
    'App\\Libraries\\MetaCloudAPI' => ['sendTemplate', 'sendBulkCampaign', 'sendText'],
    'App\\Libraries\\WhatsAppCloudAPI' => ['getProvider', 'getDriver'],
    'App\\Libraries\\CampaignService' => ['start', 'create', 'previewAudience', 'queueRecipients'],
    'App\\Libraries\\QueueService' => ['processBatch'],
    'App\\Libraries\\SettingsService' => ['getWhatsAppProvider', 'isCheerioProvider'],
];
foreach ($libChecks as $class => $methods) {
    if (! class_exists($class)) {
        $parts = explode('\\', $class);
        $short = end($parts);
        $file = $root . '/app/Libraries/' . $short . '.php';
        if (! is_file($file) && str_contains($short, 'Email')) {
            // skip
        }
        if (is_file($file)) {
            require_once $file;
        }
        // nested
        if (! class_exists($class) && str_contains($class, 'Email\\')) {
            // ignore
        }
    }
    if (! class_exists($class)) {
        // try spark autoload via service
        try {
            if ($class === 'App\\Libraries\\WhatsAppCloudAPI') {
                service('whatsApp');
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
    check("library {$class}", class_exists($class));
    if (! class_exists($class)) {
        continue;
    }
    foreach ($methods as $m) {
        check("{$class}::{$m}", method_exists($class, $m));
    }
}

section('6) SCREEN VIEW FILES');

$viewScreens = [
    'dashboard/index.php' => 'Dashboard',
    'chat/index.php' => 'Chat',
    'contacts/index.php' => 'Contacts',
    'contacts/show.php' => 'Contact show',
    'templates/index.php' => 'Templates',
    'templates/show.php' => 'Template show',
    'templates/create.php' => 'Template create',
    'campaigns/index.php' => 'Campaigns',
    'campaigns/show.php' => 'Campaign show',
    'campaigns/_wizard.php' => 'Campaign wizard',
    'campaigns/form.php' => 'Campaign form',
    'analytics/index.php' => 'Analytics',
    'reports/index.php' => 'Reports',
    'settings/index.php' => 'Settings',
    'users/index.php' => 'Users',
    'queue/index.php' => 'Queue',
    'automations/index.php' => 'Automations',
    'customer_groups/index.php' => 'Customer groups',
    'layouts/main.php' => 'Main layout',
];
foreach ($viewScreens as $rel => $label) {
    $path = $root . '/app/Views/' . $rel;
    check("view {$label} ({$rel})", is_file($path));
}

section('7) JS ASSETS FOR SCREENS');

$jsAssets = [
    'public/assets/js/app.js' => 'APP core',
    'public/assets/js/chat.js' => 'Chat',
    'public/assets/js/campaigns.js' => 'Campaigns',
];
foreach ($jsAssets as $rel => $label) {
    $path = $root . '/' . $rel;
    check("JS {$label}", is_file($path) && filesize($path) > 100);
}

// Chat header-media upload wiring
$chatJs = file_get_contents($root . '/public/assets/js/chat.js') ?: '';
check('chat.js has header media upload', str_contains($chatJs, 'templates/header-media') && str_contains($chatJs, 'Uploaded'));
check('chat.js has sendTemplate FormData', str_contains($chatJs, 'header_media') && str_contains($chatJs, 'Chat.sendTemplate'));

$chatView = file_get_contents($root . '/app/Views/chat/index.php') ?: '';
check('chat view has header media wrap', str_contains($chatView, 'templateHeaderMediaWrap'));

section('8) PROVIDER / SETTINGS SMOKE');

try {
    $settings = new \App\Libraries\SettingsService();
    $provider = $settings->getWhatsAppProvider();
    check('whatsapp_provider readable', in_array($provider, ['cheerio', 'meta'], true), "got={$provider}");
    echo "    active provider: {$provider}\n";
    $wa = service('whatsApp');
    check('whatsApp service boots', $wa instanceof \App\Libraries\WhatsAppCloudAPI);
    check('whatsApp driver resolved', method_exists($wa->getDriver(), 'sendTemplate'));
} catch (Throwable $e) {
    check('provider/settings smoke', false, $e->getMessage());
}

section('9) FUNCTIONAL METHOD SMOKE (no live send)');

try {
    $meta = new \App\Libraries\MetaCloudAPI();
    $threw = false;
    try {
        $meta->sendBulkCampaign('x', 'y', 'en', [['to' => '91']]);
    } catch (Throwable $e) {
        $threw = str_contains($e->getMessage(), 'Cheerio-only');
    }
    check('Meta bulk campaign blocked', $threw);

    $svc = new \App\Libraries\CampaignService();
    $ref = new ReflectionClass($svc);
    check('CampaignService::shouldDispatchViaCheerioBulk', $ref->hasMethod('shouldDispatchViaCheerioBulk'));
    check('CampaignService::dispatchCheerioBulkCampaign', $ref->hasMethod('dispatchCheerioBulkCampaign'));

    $preview = $svc->previewAudience([], [], [], false);
    check('previewAudience empty safe', is_array($preview) && ($preview['total'] ?? -1) === 0);
} catch (Throwable $e) {
    check('functional method smoke', false, $e->getMessage());
}

section('10) RECENT LOG ERROR SCAN');

$logDir = $root . '/writable/logs';
$logHits = [];
if (is_dir($logDir)) {
    $logs = glob($logDir . '/log-*.log') ?: [];
    rsort($logs);
    $logs = array_slice($logs, 0, 2);
    foreach ($logs as $log) {
        $tail = '';
        $size = filesize($log);
        $fh = fopen($log, 'rb');
        if ($fh) {
            fseek($fh, max(0, $size - 80000));
            $tail = stream_get_contents($fh) ?: '';
            fclose($fh);
        }
        foreach (['DatabaseException', 'Parse error', 'Fatal error', 'Unknown column', 'SQLSTATE'] as $needle) {
            if (stripos($tail, $needle) !== false) {
                $logHits[] = basename($log) . ": {$needle}";
            }
        }
    }
}
if ($logHits === []) {
    check('recent logs: no DB/parse/fatal markers in last 80KB', true);
} else {
    foreach (array_unique($logHits) as $hit) {
        warn('log marker', $hit);
    }
    check('recent logs reviewed', true);
}

echo "\n==============================\n";
echo "PASS={$pass}  FAIL={$fail}  WARN={$warn}\n";
if ($failures !== []) {
    echo "\nFAILURES:\n- " . implode("\n- ", $failures) . "\n";
}
if ($warnings !== []) {
    echo "\nWARNINGS:\n- " . implode("\n- ", $warnings) . "\n";
}
echo "==============================\n";
exit($fail > 0 ? 1 : 0);
