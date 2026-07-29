<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * php spark permissions:audit
 *
 * Scans code for permission slugs, seeds any missing rows, re-syncs role_permissions
 * for system roles, then runs inbox + workflow functional tests.
 */
class PermissionsAudit extends BaseCommand
{
    protected $group       = 'WhatsApp';
    protected $name        = 'permissions:audit';
    protected $description = 'Audit permission slugs vs DB, seed missing, run functional tests';

    public function run(array $params)
    {
        $pass = 0;
        $fail = 0;
        $check = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
            if ($ok) {
                $pass++;
                CLI::write("[PASS] {$label}", 'green');

                return;
            }
            $fail++;
            CLI::write('[FAIL] ' . $label . ($detail !== '' ? " — {$detail}" : ''), 'red');
        };

        CLI::write('=== Permissions Audit ===', 'yellow');

        $codeSlugs = $this->scanCodeSlugs();
        $seederSlugs = $this->seederSlugs();
        sort($codeSlugs);
        sort($seederSlugs);

        $missingInSeeder = array_values(array_diff($codeSlugs, $seederSlugs));
        $check(
            'all code slugs defined in PermissionSeeder',
            $missingInSeeder === [],
            $missingInSeeder === [] ? '' : implode(', ', $missingInSeeder)
        );

        CLI::write('Seeding permissions (idempotent insert + role matrix re-sync)...', 'white');
        $seeder = \Config\Database::seeder();
        $seeder->call('PermissionSeeder');

        $db = db_connect();
        $dbSlugs = array_column($db->table('permissions')->select('slug')->get()->getResultArray(), 'slug');
        sort($dbSlugs);

        $missingInDb = array_values(array_diff($seederSlugs, $dbSlugs));
        $check('seeder slugs present in DB', $missingInDb === [], implode(', ', $missingInDb));

        foreach (['sequences.view', 'sequences.create', 'sequences.edit', 'sequences.delete', 'guide.view'] as $slug) {
            $check("DB has {$slug}", in_array($slug, $dbSlugs, true));
        }

        $roleIds = array_column($db->table('roles')->get()->getResultArray(), 'id', 'slug');
        foreach (['super-admin', 'admin', 'manager'] as $role) {
            if (! isset($roleIds[$role])) {
                $check("role {$role} exists", false);
                continue;
            }
            $has = $db->table('role_permissions rp')
                ->join('permissions p', 'p.id = rp.permission_id')
                ->where('rp.role_id', $roleIds[$role])
                ->where('p.slug', 'sequences.view')
                ->countAllResults() > 0;
            $check("{$role} has sequences.view", $has);
        }

        if (isset($roleIds['agent'])) {
            $hasSeq = $db->table('role_permissions rp')
                ->join('permissions p', 'p.id = rp.permission_id')
                ->where('rp.role_id', $roleIds['agent'])
                ->where('p.slug', 'sequences.view')
                ->countAllResults() > 0;
            $hasCreate = $db->table('role_permissions rp')
                ->join('permissions p', 'p.id = rp.permission_id')
                ->where('rp.role_id', $roleIds['agent'])
                ->where('p.slug', 'sequences.create')
                ->countAllResults() > 0;
            $check('agent has sequences.view', $hasSeq);
            $check('agent lacks sequences.create', ! $hasCreate);
        }

        $seqCtrl = file_get_contents(ROOTPATH . 'app/Controllers/Sequences.php') ?: '';
        $guideCtrl = file_get_contents(ROOTPATH . 'app/Controllers/Guide.php') ?: '';
        $layout = file_get_contents(ROOTPATH . 'app/Views/layouts/main.php') ?: '';
        $check('Sequences uses sequences.* perms', str_contains($seqCtrl, "requirePermission('sequences.view')"));
        $check('Guide gated by guide.view', str_contains($guideCtrl, "requirePermission('guide.view')"));
        $check('nav Sequences uses sequences.view', str_contains($layout, "can('sequences.view')"));

        // Functional: create sequence via service (permission is controller-level)
        if ($db->tableExists('message_sequences')) {
            $svc = new \App\Libraries\SequenceService();
            $id = $svc->create('Perm Audit Seq ' . time(), [
                ['delay_minutes' => 0, 'message_type' => 'text', 'body_text' => 'Hello audit', 'template_name' => null, 'language' => 'en'],
            ], true, 'whatsapp', null);
            $row = $db->table('message_sequences')->where('id', $id)->get()->getRowArray();
            $check('SequenceService create works', is_array($row) && (int) $id > 0);
            if ($id > 0) {
                $db->table('sequence_enrollments')->where('sequence_id', $id)->delete();
                $db->table('sequence_steps')->where('sequence_id', $id)->delete();
                $db->table('message_sequences')->where('id', $id)->delete();
            }
        } else {
            $check('message_sequences table', false);
        }

        CLI::write('');
        CLI::write('=== Running inbox:test ===', 'yellow');
        $this->call('inbox:test');

        CLI::write('');
        CLI::write('=== Running workflow:test ===', 'yellow');
        $this->call('workflow:test');

        CLI::write('');
        CLI::write("Permissions audit checks: {$pass} pass, {$fail} fail", $fail === 0 ? 'green' : 'red');

        if ($fail > 0) {
            CLI::error('permissions:audit failed');
            return EXIT_ERROR;
        }

        return EXIT_SUCCESS;
    }

    /**
     * @return list<string>
     */
    protected function scanCodeSlugs(): array
    {
        $roots = [
            ROOTPATH . 'app/Controllers',
            ROOTPATH . 'app/Views',
            ROOTPATH . 'app/Helpers',
            ROOTPATH . 'app/Filters',
            ROOTPATH . 'app/Libraries',
        ];
        $found = [];
        $patterns = [
            "/requirePermission\\(\\s*['\"]([a-z0-9_.]+)['\"]\\s*\\)/",
            "/\\bcan\\(\\s*['\"]([a-z0-9_.]+)['\"]\\s*\\)/",
        ];

        foreach ($roots as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($it as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $src = (string) file_get_contents($file->getPathname());
                foreach ($patterns as $re) {
                    if (preg_match_all($re, $src, $m)) {
                        foreach ($m[1] as $slug) {
                            if ($slug === '*') {
                                continue;
                            }
                            $found[$slug] = true;
                        }
                    }
                }
            }
        }

        return array_keys($found);
    }

    /**
     * @return list<string>
     */
    protected function seederSlugs(): array
    {
        $src = (string) file_get_contents(ROOTPATH . 'app/Database/Seeds/PermissionSeeder.php');
        if (! preg_match_all("/'slug'\\s*=>\\s*'([a-z0-9_.]+)'/", $src, $m)) {
            return [];
        }

        return array_values(array_unique($m[1]));
    }
}
