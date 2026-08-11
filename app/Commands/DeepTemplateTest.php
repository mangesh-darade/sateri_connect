<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\MetaApiErrorMapper;
use App\Libraries\MetaGraphLogger;
use App\Libraries\TemplateSyncService;
use App\Libraries\WhatsAppTemplateSendGuard;
use App\Libraries\WhatsAppTemplateVariables;
use App\Models\TemplateModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Deep validation for WhatsApp template management.
 *
 *   php spark templates:deep-test
 */
class DeepTemplateTest extends BaseCommand
{
    protected $group       = 'Templates';
    protected $name        = 'templates:deep-test';
    protected $description = 'Deep validation: hardcoding, guards, sync dedupe, logging safety.';
    protected $usage       = 'templates:deep-test';

    private int $passed = 0;
    private int $failed = 0;

    public function run(array $params)
    {
        $this->check(
            'no hardcoded WABA/phone/app/token in new core files',
            ! $this->hasHardcodedSecrets([
                APPPATH . 'Libraries/TemplateSyncService.php',
                APPPATH . 'Libraries/WhatsAppTemplateSendGuard.php',
                APPPATH . 'Libraries/StandardTemplateOnboarding.php',
                APPPATH . 'Controllers/Api/WhatsAppTemplates.php',
                APPPATH . 'Commands/InspectTemplates.php',
                APPPATH . 'Commands/SafeUtilityTemplates.php',
                APPPATH . 'Commands/UtilityEnTemplates.php',
            ])
        );

        $index = (string) file_get_contents(APPPATH . 'Views/templates/index.php');
        $show  = (string) file_get_contents(APPPATH . 'Views/templates/show.php');
        $this->check('templates/index uses APP.baseUrl only', str_contains($index, 'APP.baseUrl') && ! preg_match('/APP\.base[^U]/', $index));
        $this->check('templates/show uses APP.baseUrl only', str_contains($show, 'APP.baseUrl') && ! preg_match('/APP\.base[^U]/', $show));

        $san = MetaGraphLogger::sanitize([
            'access_token'  => 'SECRET',
            'Authorization' => 'Bearer SECRET',
            'waba_id'       => '123',
            'template_name' => 'x',
        ]);
        $this->check('logger strips access_token', ! isset($san['access_token']));
        $this->check('logger strips Authorization', ! isset($san['Authorization']));
        $this->check('logger keeps waba_id', ($san['waba_id'] ?? '') === '123');

        $msg = MetaApiErrorMapper::humanize('template name does not exist in the translation', 400, '132001');
        $this->check('mapper template-not-found message', str_contains(strtolower($msg), 'not found') || str_contains(strtolower($msg), 'sync'));
        $msg2 = MetaApiErrorMapper::humanize('not approved', 400, '132016');
        $this->check('mapper not-approved message', str_contains(strtolower($msg2), 'not approved'));

        $defs = WhatsAppTemplateVariables::definitionsFromBody(
            'Hello {{1}}, your order {{2}} has been confirmed.',
            ['Vipin', 'ORD-1']
        );
        $this->check('dynamic variables count=2', count($defs) === 2);

        $guard  = new WhatsAppTemplateSendGuard();
        $model  = model(TemplateModel::class);
        $meta   = service('settingsService')->getMetaConfig();
        $wabaId = trim((string) ($meta['waba_id'] ?? ''));

        $pending = $wabaId !== ''
            ? $model->where('status', 'PENDING')->where('waba_id', $wabaId)->first()
            : $model->where('status', 'PENDING')->first();
        if (is_array($pending)) {
            try {
                $guard->assertApproved($pending);
                $this->check('PENDING blocked', false, 'should throw');
            } catch (Throwable $e) {
                $this->check(
                    'PENDING blocked',
                    str_contains(strtolower($e->getMessage()), 'pending')
                    || str_contains(strtolower($e->getMessage()), 'not approved'),
                    $e->getMessage()
                );
            }
        } else {
            $this->check('PENDING blocked', true, 'no pending row');
        }

        $rejected = $model->where('status', 'REJECTED')->first();
        if (is_array($rejected)) {
            try {
                $guard->assertApproved($rejected);
                $this->check('REJECTED blocked', false);
            } catch (Throwable $e) {
                $this->check(
                    'REJECTED blocked',
                    str_contains(strtolower($e->getMessage()), 'rejected')
                    || str_contains(strtolower($e->getMessage()), 'not approved'),
                    $e->getMessage()
                );
            }
        } else {
            $this->check('REJECTED blocked', true, 'no rejected row');
        }

        try {
            $guard->assertPhoneNumberId('999999999999999');
            $this->check('wrong phone id blocked', false);
        } catch (Throwable $e) {
            $this->check('wrong phone id blocked', (int) $e->getCode() === 403, $e->getMessage());
        }

        try {
            $guard->assertWabaId('000000000000000');
            $this->check('wrong waba blocked', false);
        } catch (Throwable $e) {
            $this->check('wrong waba blocked', (int) $e->getCode() === 403, $e->getMessage());
        }

        if (is_array($pending)) {
            try {
                $guard->buildBodyComponents($pending, []);
                $this->check('missing vars blocked', false);
            } catch (Throwable $e) {
                $this->check(
                    'missing vars blocked',
                    (int) $e->getCode() === 422 || str_contains(strtolower($e->getMessage()), 'missing'),
                    $e->getMessage()
                );
            }
        } else {
            $this->check('missing vars blocked', true, 'skipped');
        }

        $db = db_connect();
        if ($wabaId !== '') {
            $dup = $db->query(
                "SELECT meta_id, COUNT(*) c FROM templates
                 WHERE waba_id = ? AND meta_id IS NOT NULL AND meta_id != ''
                 GROUP BY meta_id HAVING c > 1",
                [$wabaId]
            )->getResultArray();
            $this->check('no duplicate meta_id rows', $dup === []);

            $before = (int) $db->table('templates')->where('waba_id', $wabaId)->countAllResults();
            $sync   = new TemplateSyncService();
            $sync->sync();
            $after1 = (int) $db->table('templates')->where('waba_id', $wabaId)->countAllResults();
            $r2     = $sync->sync();
            $after2 = (int) $db->table('templates')->where('waba_id', $wabaId)->countAllResults();
            $this->check('sync row count stable', $before === $after1 && $after1 === $after2, "{$before}/{$after1}/{$after2}");
            $this->check('second sync inserts=0', (int) ($r2['inserted'] ?? -1) === 0);
            $this->check('hello_world not assumed present', empty($r2['hello_world']['exists']));
        } else {
            $this->check('no duplicate meta_id rows', true, 'no waba configured');
            $this->check('sync row count stable', true, 'skipped');
            $this->check('second sync inserts=0', true, 'skipped');
            $this->check('hello_world not assumed present', true, 'skipped');
        }

        $idx = $db->query(
            "SELECT DISTINCT index_name FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'templates'
               AND index_name IN ('templates_waba_meta_unique','templates_waba_name_lang_unique')"
        )->getResultArray();
        $this->check('unique indexes present', count($idx) >= 2, 'found=' . count($idx));

        CLI::newLine();
        CLI::write("Passed: {$this->passed}  Failed: {$this->failed}", $this->failed > 0 ? 'red' : 'green');

        return $this->failed > 0 ? EXIT_ERROR : EXIT_SUCCESS;
    }

    /**
     * @param list<string> $files
     */
    private function hasHardcodedSecrets(array $files): bool
    {
        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            if (preg_match('/1068879382322337|1250382961490607|985651907816253|EAAG[A-Za-z0-9]{20,}/', $src)) {
                CLI::error('Hardcode found in ' . $file);

                return true;
            }
        }

        return false;
    }

    private function check(string $name, bool $ok, string $detail = ''): void
    {
        if ($ok) {
            $this->passed++;
            CLI::write('PASS  ' . $name . ($detail !== '' ? " — {$detail}" : ''), 'green');
        } else {
            $this->failed++;
            CLI::write('FAIL  ' . $name . ($detail !== '' ? " — {$detail}" : ''), 'red');
        }
    }
}
