<?php

declare(strict_types=1);

/**
 * Cheerio parity: attribute definitions + typed values, contact filters / bulk / export,
 * inbox quick replies + contact panel data, keyword direct actions.
 * Uses real DB rows with a unique prefix; everything is removed at the end. Nothing is sent to WhatsApp.
 *
 * Run: php tests/CheerioParityFeaturesTest.php
 */

define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);
chdir(FCPATH);

require FCPATH . '../app/Config/Paths.php';
$paths = new \Config\Paths();
require $paths->systemDirectory . '/Boot.php';
\CodeIgniter\Boot::bootSpark($paths);

use App\Libraries\ContactExportService;
use App\Libraries\ContactListFilter;
use App\Models\ContactModel;
use App\Models\KeywordModel;
use App\Models\TagModel;

helper('whatsapp');

$pass = 0;
$fail = 0;

function check(string $label, bool $condition, string $detail = ''): void
{
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "[PASS] {$label}\n";

        return;
    }
    $fail++;
    echo "[FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

/** @return string exception message ('' when no exception) */
function throws(callable $fn): string
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $e->getMessage();
    }

    return '';
}

echo "=== Cheerio Parity Features Test ===\n\n";

$db       = db_connect();
$attrs    = service('contactAttributes');
$qr       = service('quickReplies');
$contacts = model(ContactModel::class);
$tags     = model(TagModel::class);
$P        = 'zqa';               // attribute key prefix
$mobiles  = ['919000000881', '919000000882', '919000000883'];

$cleanup = static function () use ($db, $P, $mobiles): void {
    $ids = array_column($db->table('contacts')->select('id')->whereIn('mobile', $mobiles)->get()->getResultArray(), 'id');
    if ($ids !== []) {
        $db->table('contact_tags')->whereIn('contact_id', $ids)->delete();
        $db->table('message_queue')->whereIn('contact_id', $ids)->delete();
        $db->table('messages')->whereIn('contact_id', $ids)->delete();
        $db->table('contacts')->whereIn('id', $ids)->delete();
    }
    $db->table('contact_attributes')->like('attr_key', $P . '_', 'after')->delete();
    $db->table('quick_replies')->like('shortcut', $P . '-', 'after')->delete();
    $db->table('tags')->like('name', 'ZQA ', 'after')->delete();
    $db->table('keywords')->like('keyword', 'zqaparity', 'after')->delete();
};
$cleanup();

try {
    // ---------------- 1. Attribute definitions ----------------
    check('A1 tables exist', $attrs->tableReady() && $qr->tableReady());
    check('A2 bad key rejected', str_contains(throws(fn () => $attrs->saveDefinition(['attr_key' => '1 city', 'type' => 'text'])), 'start with a letter'));
    check('A3 built-in key rejected', str_contains(throws(fn () => $attrs->saveDefinition(['attr_key' => 'email', 'type' => 'text'])), 'built-in'));
    check('A4 dropdown needs options', str_contains(throws(fn () => $attrs->saveDefinition(['attr_key' => "{$P}_city", 'type' => 'dropdown', 'options' => ''])), 'dropdown option'));
    check('A5 invalid default rejected', str_contains(throws(fn () => $attrs->saveDefinition(['attr_key' => "{$P}_age", 'type' => 'number', 'default_value' => 'abc'])), 'Default value'));

    $city = $attrs->saveDefinition(['attr_key' => "{$P}_city", 'label' => 'ZQA City', 'type' => 'dropdown', 'options' => "Pune\nMumbai\nNashik", 'default_value' => 'pune']);
    $attrs->saveDefinition(['attr_key' => "{$P}_age", 'label' => 'ZQA Age', 'type' => 'number']);
    $attrs->saveDefinition(['attr_key' => "{$P}_visit", 'label' => 'ZQA Visit', 'type' => 'date']);
    $attrs->saveDefinition(['attr_key' => "{$P}_vip", 'label' => 'ZQA VIP', 'type' => 'boolean']);
    check('A6 dropdown saved with canonical default', ($city['default_value'] ?? '') === 'Pune' && $city['options'] === ['Pune', 'Mumbai', 'Nashik']);
    check('A7 duplicate key rejected', str_contains(throws(fn () => $attrs->saveDefinition(['attr_key' => "{$P}_city", 'type' => 'text'])), 'already exists'));
    $upd = $attrs->saveDefinition(['attr_key' => 'ignored', 'label' => 'ZQA City Name', 'type' => 'dropdown', 'options' => "Pune\nMumbai\nNashik\nNagpur", 'default_value' => 'Pune'], (int) $city['id']);
    check('A8 edit keeps key, updates options', $upd['attr_key'] === "{$P}_city" && in_array('Nagpur', $upd['options'], true));

    // ---------------- 2. Typed values ----------------
    check('T1 number normalised', $attrs->normalizeValue("{$P}_age", '1,200')['value'] === '1200');
    check('T2 number rejects text', ! $attrs->normalizeValue("{$P}_age", 'twelve')['ok']);
    check('T3 date d/m/Y → Y-m-d', $attrs->normalizeValue("{$P}_visit", '30/09/2026')['value'] === '2026-09-30');
    check('T4 date rejects nonsense', ! $attrs->normalizeValue("{$P}_visit", '31/02/2026')['ok']);
    check('T5 boolean haan → Yes', $attrs->normalizeValue("{$P}_vip", 'haan')['value'] === 'Yes');
    check('T6 dropdown canonical case', $attrs->normalizeValue("{$P}_city", 'mumbai')['value'] === 'Mumbai');
    check('T7 dropdown rejects other', str_contains($attrs->normalizeValue("{$P}_city", 'Delhi')['error'], 'Choose one of'));
    check('T8 undefined key accepts text', $attrs->normalizeValue('some_free_key', 'anything')['ok']);
    check('T9 core email validated', ! $attrs->normalizeValue('email', 'bad@')['ok']);

    // ---------------- 3. Contacts: defaults, set, bulk ----------------
    $c1 = (int) $contacts->insert(['name' => 'ZQA One', 'mobile' => $mobiles[0], 'status' => 'active']);
    $c2 = (int) $contacts->insert(['name' => 'ZQA Two', 'mobile' => $mobiles[1], 'status' => 'active', 'custom_fields' => ["{$P}_city" => 'Mumbai']]);
    $c3 = (int) $contacts->insert(['name' => 'ZQA Three', 'mobile' => $mobiles[2], 'status' => 'inactive']);
    $cf = static fn (int $id): array => (array) ($contacts->find($id)['custom_fields'] ?? []);
    check('C1 default applied on create', ($cf($c1)["{$P}_city"] ?? '') === 'Pune');
    check('C2 existing value not overwritten by default', ($cf($c2)["{$P}_city"] ?? '') === 'Mumbai');
    check('C2b default also on third contact, then cleared', ($cf($c3)["{$P}_city"] ?? '') === 'Pune' && $attrs->setContactValue($c3, "{$P}_city", '') === '');

    check('C3 set typed value', $attrs->setContactValue($c1, "{$P}_age", ' 42 ') === '42' && ($cf($c1)["{$P}_age"] ?? '') === '42');
    check('C4 invalid value refused with label', str_contains(throws(fn () => $attrs->setContactValue($c1, "{$P}_age", 'old')), 'ZQA Age'));
    check('C5 core email saved to column', $attrs->setContactValue($c1, 'email', 'one@example.com') === 'one@example.com' && $contacts->find($c1)['email'] === 'one@example.com');
    check('C6 mobile cannot be edited', str_contains(throws(fn () => $attrs->setContactValue($c1, 'mobile', '1')), 'cannot be changed'));
    $attrs->setContactValue($c1, "{$P}_age", '');
    check('C7 empty value clears attribute', ! array_key_exists("{$P}_age", $cf($c1)));

    check('C8 bulk set', $attrs->bulkSet([$c1, $c2, $c3], "{$P}_vip", 'yes') === 3 && ($cf($c3)["{$P}_vip"] ?? '') === 'Yes');
    check('C9 bulk invalid value → nothing changed', throws(fn () => $attrs->bulkSet([$c1, $c2], "{$P}_age", 'x')) !== '' && ! isset($cf($c1)["{$P}_age"]));
    check('C10 bulk needs contacts', str_contains(throws(fn () => $attrs->bulkSet([], "{$P}_vip", 'yes')), 'Select at least one'));

    $rows = $attrs->contactRows($contacts->find($c2));
    $byKey = array_column($rows, null, 'key');
    check('C11 contact panel rows include defined attrs', isset($byKey["{$P}_city"]) && $byKey["{$P}_city"]['type'] === 'dropdown' && $byKey["{$P}_city"]['value'] === 'Mumbai');
    check('C12 known keys include definitions', in_array("{$P}_visit", \App\Libraries\ContactAttributes::knownKeys(), true));

    // ---------------- 4. List filter + export ----------------
    $ids = static function (array $in) use ($db, $mobiles): array {
        $b = $db->table('contacts c')->select('c.id')->where('c.deleted_at', null)->whereIn('c.mobile', $mobiles);
        ContactListFilter::apply($b, ContactListFilter::fromInput($in));

        return array_map('intval', array_column($b->get()->getResultArray(), 'id'));
    };
    $same = static fn (array $a, array $b): bool => (sort($a) || true) && (sort($b) || true) && $a === $b;
    check('F1 attribute equals (case-insensitive)', $same($ids(['attr_key' => "{$P}_city", 'attr_op' => 'equals', 'attr_value' => 'MUMBAI']), [$c2]));
    check('F2 attribute not_equals', $same($ids(['attr_key' => "{$P}_city", 'attr_op' => 'not_equals', 'attr_value' => 'Mumbai']), [$c1, $c3]));
    check('F3 attribute contains', $same($ids(['attr_key' => "{$P}_city", 'attr_op' => 'contains', 'attr_value' => 'un']), [$c1]));
    check('F4 attribute is_empty', $same($ids(['attr_key' => "{$P}_city", 'attr_op' => 'is_empty']), [$c3]));
    check('F5 attribute not_empty', $same($ids(['attr_key' => "{$P}_city", 'attr_op' => 'not_empty']), [$c1, $c2]));
    check('F6 core column attribute', $same($ids(['attr_key' => 'email', 'attr_op' => 'starts_with', 'attr_value' => 'one@']), [$c1]));
    check('F7 invalid key ignored (no SQL injection)', count($ids(['attr_key' => "x') OR 1=1 --", 'attr_value' => 'a'])) === 3);
    check('F8 LIKE wildcard escaped', $ids(['attr_key' => "{$P}_city", 'attr_op' => 'contains', 'attr_value' => '%']) === []);

    $tag = $tags->findOrCreateByName('ZQA VIP group');
    $tags->attachContact((int) $tag['id'], $c2);
    check('F9 group filter', $same($ids(['tag_id' => $tag['id']]), [$c2]));
    check('F10 combined filters', $ids(['status' => 'inactive', 'attr_key' => "{$P}_vip", 'attr_value' => 'yes']) === [$c3]);

    $csv   = (new ContactExportService())->csv(ContactListFilter::fromInput(['search' => 'ZQA', 'attr_key' => "{$P}_city", 'attr_op' => 'not_empty']));
    $lines = array_values(array_filter(explode("\n", str_replace("\r", '', substr($csv, 3)))));
    $head  = str_getcsv($lines[0] ?? '', ',', '"', '');
    check('E1 export has UTF-8 BOM', str_starts_with($csv, "\xEF\xBB\xBF"));
    check('E2 export header has groups, consent, attributes', in_array('groups', $head, true) && in_array("{$P}_city", $head, true) && in_array("{$P}_vip", $head, true)
        && (! service('whatsAppConsent')->hasConsentColumns() || in_array('whatsapp_opt_in', $head, true)), implode(',', $head));
    check('E3 export honours filters', count($lines) === 3, (string) count($lines));
    $row2 = array_combine($head, str_getcsv($lines[2] ?? '', ',', '"', '')) ?: [];
    check('E4 export row values', ($row2['name'] ?? '') === 'ZQA Two' && ($row2["{$P}_city"] ?? '') === 'Mumbai' && str_contains($row2['groups'] ?? '', 'ZQA VIP group'), json_encode($row2));
    $attrs->setContactValue($c3, 'notes', '=HYPERLINK("x")');
    $csvAll = (new ContactExportService())->csv(ContactListFilter::fromInput(['search' => 'ZQA Three']));
    check('E5 formula injection neutralised', str_contains($csvAll, "'=HYPERLINK"));

    // ---------------- 5. Quick replies ----------------
    check('Q1 shortcut validated', str_contains(throws(fn () => $qr->save(['shortcut' => 'bad shortcut', 'message' => 'x'], null)), 'no spaces'));
    check('Q2 message required', str_contains(throws(fn () => $qr->save(['shortcut' => "{$P}-empty", 'message' => ' '], null)), 'Enter the reply'));
    $q = $qr->save(['shortcut' => "/{$P}-Price", 'message' => 'Hi {{name}}, city {{' . $P . '_city}} {{contact.email}}{{unknown}}.'], null);
    check('Q3 saved (slash stripped, lower-case, auto title)', $q['shortcut'] === "{$P}-price" && $q['title'] !== '');
    check('Q4 duplicate shortcut rejected', str_contains(throws(fn () => $qr->save(['shortcut' => "{$P}-price", 'message' => 'y'], null)), 'already used'));
    check('Q5 edit same shortcut allowed', $qr->save(['shortcut' => "{$P}-price", 'message' => 'Hi {{name}}, city {{' . $P . '_city}} {{contact.email}}{{unknown}}.'], (int) $q['id'])['id'] === (int) $q['id']);
    check('Q6 placeholders rendered for contact', $qr->render($q['message'], $contacts->find($c1)) === 'Hi ZQA One, city Pune one@example.com.', $qr->render($q['message'], $contacts->find($c1)));

    // ---------------- 6. Keyword direct actions ----------------
    $bot    = service('keywordBot');
    $parsed = $bot->parseActions(['kw_add_tags' => 'ZQA Lead, zqa lead ,ZQA Pune', 'kw_attr_key' => ["{$P}_city", "{$P}_age", '', 'bad key'], 'kw_attr_value' => ['nashik', 'abc', 'x', 'y']]);
    check('K1 invalid attribute values reported', count($parsed['errors']) === 2 && str_contains(implode(' ', $parsed['errors']), 'ZQA Age'), implode(' | ', $parsed['errors']));
    check('K2 tags de-duplicated, values normalised', $parsed['actions']['add_tags'] === ['ZQA Lead', 'ZQA Pune'] && $parsed['actions']['set_attributes'][0]['value'] === 'Nashik');

    $ok = $bot->parseActions(['kw_add_tags' => 'ZQA Lead', 'kw_attr_key' => ["{$P}_city", 'zqa_note'], 'kw_attr_value' => ['Nashik', 'said: {{message}}']]);
    $kwId = (int) model(KeywordModel::class)->insert([
        'keyword' => 'zqaparity', 'match_type' => 'exact', 'response_type' => 'none', 'response_content' => '',
        'response_payload' => json_encode(['type' => 'none', '_actions' => $ok['actions']]), 'is_active' => 1, 'menu_order' => 0,
    ]);
    $msgsBefore = $db->table('messages')->where('contact_id', $c3)->countAllResults();
    $res = $bot->matchAndReply($c3, 'ZQAparity');
    $c3cf = $cf($c3);
    check('K3 actions-only keyword matched without sending', ! empty($res['matched']) && $res['response'] === null
        && $db->table('messages')->where('contact_id', $c3)->countAllResults() === $msgsBefore);
    check('K4 keyword added contact to group (created)', in_array('ZQA Lead', array_column($tags->getForContact($c3), 'name'), true));
    check('K5 keyword set typed attribute', ($c3cf["{$P}_city"] ?? '') === 'Nashik');
    check('K6 {{message}} saved from customer text', ($c3cf['zqa_note'] ?? '') === 'said: ZQAparity', (string) ($c3cf['zqa_note'] ?? ''));

    // ---------------- 7. Workflow uses typed attributes ----------------
    $engine = service('automationEngine');
    $ctx    = ['contact_id' => $c1, 'contact' => $contacts->find($c1)];
    $engine->executeAction('set_attribute', ['attribute' => "{$P}_age", 'text' => 'not a number'], $ctx);
    check('W1 workflow invalid typed value not saved, marked failed', ! isset($cf($c1)["{$P}_age"]) && ! empty($ctx['_action_failed']));
    $ctx = ['contact_id' => $c1, 'contact' => $contacts->find($c1)];
    $engine->executeAction('set_attribute', ['attribute' => "{$P}_visit", 'text' => '01-10-2026'], $ctx);
    check('W2 workflow date normalised', ($cf($c1)["{$P}_visit"] ?? '') === '2026-10-01' && ($ctx["{$P}_visit"] ?? '') === '2026-10-01');
    $ctx = ['contact_id' => $c1, 'contact' => $contacts->find($c1)];
    $engine->executeAction('set_attribute', ['attribute' => 'Legacy Field', 'text' => 'kept'], $ctx);
    check('W3 legacy attribute names still saved', ($cf($c1)['Legacy Field'] ?? '') === 'kept');

    // ---------------- 7b. Builder graph end-to-end: update attribute → attribute condition ----------------
    $wf    = new \App\Libraries\WorkflowGraph();
    $graph = ['nodes' => [
        ['id' => 'trigger', 'type' => 'trigger', 'data' => ['trigger_type' => 'incoming_message']],
        ['id' => 'set', 'type' => 'action', 'data' => ['action_type' => 'set_attribute', 'attribute' => "{$P}_city", 'text' => 'mumbai']],
        ['id' => 'cond', 'type' => 'condition', 'data' => ['condition_type' => 'attribute_condition', 'attribute' => "{$P}_city", 'operator' => 'equals', 'value' => 'Mumbai']],
        ['id' => 'yes', 'type' => 'action', 'data' => ['action_type' => 'set_attribute', 'attribute' => 'zqa_note', 'text' => 'city={{contact.' . $P . '_city}}']],
        ['id' => 'no', 'type' => 'action', 'data' => ['action_type' => 'set_attribute', 'attribute' => 'zqa_note', 'text' => 'no']],
    ], 'edges' => [
        ['from' => 'trigger', 'to' => 'set', 'port' => 'out'],
        ['from' => 'set', 'to' => 'cond', 'port' => 'out'],
        ['from' => 'cond', 'to' => 'yes', 'port' => 'true'],
        ['from' => 'cond', 'to' => 'no', 'port' => 'false'],
    ]];
    check('G1 valid attribute graph passes save checks', $wf->validate($graph) === [], implode(' | ', $wf->validate($graph)));
    $autoId = (int) model(\App\Models\AutomationModel::class)->insert(['name' => 'ZQA attr flow', 'trigger_type' => 'incoming_message', 'is_active' => 0]);
    foreach ($wf->toRules($graph) as $rule) {
        model(\App\Models\AutomationRuleModel::class)->insert(['automation_id' => $autoId] + array_intersect_key($rule, array_flip(['step_order', 'rule_type', 'action_type', 'config', 'next_on_true', 'next_on_false'])));
    }
    $engine->runAutomation($autoId, ['contact_id' => $c3]);
    check('G2 trigger → Update attribute saved typed value', ($cf($c3)["{$P}_city"] ?? '') === 'Mumbai');
    check('G3 Attribute condition read it and took Yes path with {{contact.attr}}', ($cf($c3)['zqa_note'] ?? '') === 'city=Mumbai', (string) ($cf($c3)['zqa_note'] ?? ''));
    $attrs->setContactValue($c3, "{$P}_city", 'Pune');
    $db->table('automation_rules')->where('automation_id', $autoId)->where('action_type', 'set_attribute')->where('step_order', 1)->delete();
    $engine->runAutomation($autoId, ['contact_id' => $c3]);
    check('G4 condition false → No path', ($cf($c3)['zqa_note'] ?? '') === 'no', (string) ($cf($c3)['zqa_note'] ?? ''));
    $db->table('automation_rules')->where('automation_id', $autoId)->delete();
    $db->table('automations')->where('id', $autoId)->delete();

    $bad = ['nodes' => [
        ['id' => 's1', 'type' => 'action', 'data' => ['action_type' => 'set_attribute', 'attribute' => "{$P}_age", 'text' => 'abc']],
        ['id' => 's2', 'type' => 'action', 'data' => ['action_type' => 'set_attribute', 'attribute' => '', 'text' => 'x']],
        ['id' => 's3', 'type' => 'action', 'data' => ['action_type' => 'set_attribute', 'attribute' => "{$P}_age", 'text' => '{{answer}}']],
        ['id' => 'q', 'type' => 'action', 'data' => ['action_type' => 'ask_question', 'text' => 'City?', 'reply_type' => 'buttons', 'options' => "Pune\nDelhi", 'save_as' => "{$P}_city"]],
    ]];
    $errs = implode(' | ', $wf->validate($bad));
    check('G5 save blocks wrong fixed value for typed attribute', str_contains($errs, 'ZQA Age — Enter a number'), $errs);
    check('G6 save blocks Update attribute without attribute', str_contains($errs, 'choose which attribute'), $errs);
    check('G7 variable values allowed', substr_count($errs, 'ZQA Age') === 1, $errs);
    check('G8 ask question option not in dropdown blocked', str_contains($errs, 'option "Delhi" cannot be saved'), $errs);

    $nf = $attrs->normalizeFields(["{$P}_age" => '1,500', "{$P}_vip" => 'no', 'free_key' => 'x']);
    check('G9 contact form fields normalised', $nf['errors'] === [] && $nf['fields']["{$P}_age"] === '1500' && $nf['fields']["{$P}_vip"] === 'No');
    check('G10 contact form invalid field reported', str_contains(implode(' ', $attrs->normalizeFields(["{$P}_visit" => 'someday'])['errors']), 'ZQA Visit'));

    // ---------------- 7c. "Attribute updated" trigger ----------------
    $mkFlow = static function (string $name, array $cfg, array $action) use ($db): int {
        $id = (int) model(\App\Models\AutomationModel::class)->insert(['name' => $name, 'trigger_type' => 'attribute_updated', 'trigger_config' => $cfg, 'is_active' => 1, 'priority' => 1]);
        model(\App\Models\AutomationRuleModel::class)->insert(['automation_id' => $id, 'step_order' => 1, 'rule_type' => 'action', 'action_type' => 'set_attribute', 'config' => json_encode($action)]);

        return $id;
    };
    $trigFlow = $mkFlow('ZQA attr trigger', ['attribute' => "{$P}_city", 'attribute_value' => 'mumbai'], ['attribute' => 'zqa_note', 'text' => '{{old_value}}>{{attribute_value}}']);
    $attrs->setContactValue($c3, 'zqa_note', '');
    $attrs->setContactValue($c3, "{$P}_city", 'Mumbai');
    check('U1 attribute change to matching value fired workflow', ($cf($c3)['zqa_note'] ?? '') === 'Pune>Mumbai', (string) ($cf($c3)['zqa_note'] ?? ''));
    $attrs->setContactValue($c3, 'zqa_note', '');
    $attrs->setContactValue($c3, "{$P}_city", 'Mumbai');
    check('U2 same value again → no fire', ($cf($c3)['zqa_note'] ?? '') === '');
    $attrs->setContactValue($c3, "{$P}_city", 'Pune');
    check('U3 other value → value filter blocks', ($cf($c3)['zqa_note'] ?? '') === '');
    $attrs->setContactValue($c3, "{$P}_city", 'Mumbai');
    $attrs->setContactValue($c3, 'zqa_note', '');
    $attrs->setContactValue($c3, "{$P}_city", '');
    check('U4 clearing value does not match "Mumbai"', ($cf($c3)['zqa_note'] ?? '') === '');
    $attrs->notifyChanges($c3, ["{$P}_city" => 'Pune'], ["{$P}_city" => 'Mumbai']);
    check('U5 contact-form diff fires trigger too', ($cf($c3)['zqa_note'] ?? '') === 'Pune>Mumbai');
    $loopFlow = $mkFlow('ZQA attr loop', ['attribute' => 'zqa_loop'], ['attribute' => 'zqa_loop', 'text' => '{{old_value}}x']);
    $attrs->setContactValue($c3, 'zqa_loop', 'a');
    $loopVal = (string) ($cf($c3)['zqa_loop'] ?? '');
    check('U6 self-updating workflow stops (loop guard)', $loopVal !== '' && strlen($loopVal) <= 6, $loopVal);
    foreach ([$trigFlow, $loopFlow] as $fid) {
        $db->table('automation_rules')->where('automation_id', $fid)->delete();
        $db->table('automations')->where('id', $fid)->delete();
    }
    $attrs->setContactValue($c3, 'zqa_loop', '');
    $attrs->setContactValue($c3, 'zqa_note', '');

    // ---------------- 7d. Attributes linked into campaigns / workflow templates / usage ----------------
    $picker = $attrs->pickerFields();
    $pickCity = array_values(array_filter($picker, static fn ($f) => $f['value'] === "{$P}_city"))[0] ?? null;
    check('P1 picker lists defined attribute (group + options)', $pickCity !== null && $pickCity['group'] === 'Attributes' && in_array('Mumbai', $pickCity['options'], true));
    $attrs->setContactValue($c3, "{$P}_city", 'Pune');
    $row3 = $contacts->find($c3);
    check('P2 valueFor custom + core + case-insensitive', $attrs->valueFor($row3, "{$P}_city") === 'Pune'
        && $attrs->valueFor($row3, 'name') === 'ZQA Three' && $attrs->valueFor($row3, strtoupper("{$P}_CITY")) === 'Pune');
    $campaigns = service('campaignService');
    $matched = $campaigns->filterContactsByAttributes([$row3], [['name' => strtoupper("{$P}_city"), 'condition' => 'equals', 'value' => 'pune']]);
    check('P3 campaign audience filter matches defined attribute (any case)', count($matched) === 1);
    $rv = new ReflectionMethod($campaigns, 'resolveVariableValue');
    $rv->setAccessible(true);
    check('P4 campaign variable attr:key → contact value', $rv->invoke($campaigns, "attr:{$P}_city", $row3) === 'Pune');
    check('P5 campaign variable {{key}} → value, plain text stays text', $rv->invoke($campaigns, '{{' . $P . '_city}}', $row3) === 'Pune' && $rv->invoke($campaigns, 'Diwali sale', $row3) === 'Diwali sale');
    $comp = $campaigns->templateComponentsForContact('zqa_missing_tpl', 'en', ['1' => 'Pune', '2' => 'X'], $row3);
    check('P6 workflow template components built from values', ($comp[0]['type'] ?? '') === 'body' && ($comp[0]['parameters'][0]['text'] ?? '') === 'Pune' && count($comp[0]['parameters']) === 2);

    $fakeQueue = new class () extends \App\Libraries\QueueService {
        public array $sent = [];
        public function __construct() {}
        public function enqueue(int $contactId, string $messageType, array $payload, ?int $campaignId = null, int $priority = 5, ?string $scheduledAt = null): int
        {
            $this->sent[] = $payload;

            return 1;
        }
    };
    $eng2 = new \App\Libraries\AutomationEngine(null, null, null, $fakeQueue);
    $ctx  = ['contact_id' => $c3, 'contact' => $contacts->find($c3)];
    $eng2->executeAction('send_template', ['template_name' => 'zqa_missing_tpl', 'language' => 'en', 'variables' => ['1' => '{{contact.' . $P . '_city}}']], $ctx);
    check('P7 workflow Send template fills {{1}} from attribute', ($fakeQueue->sent[0]['components'][0]['parameters'][0]['text'] ?? '') === 'Pune', json_encode($fakeQueue->sent));
    $ctx = ['contact_id' => $c3, 'contact' => $contacts->find($c3)];
    $eng2->executeAction('send_template', ['template_name' => 'zqa_missing_tpl', 'language' => 'en', 'variables' => ['1' => '{{contact.zqa_nothing}}']], $ctx);
    check('P8 empty attribute value → template skipped, step marked failed', count($fakeQueue->sent) === 1 && ! empty($ctx['_action_failed']));

    $useId = (int) model(\App\Models\AutomationModel::class)->insert(['name' => 'ZQA uses city', 'trigger_type' => 'incoming_message', 'is_active' => 0]);
    model(\App\Models\AutomationRuleModel::class)->insert(['automation_id' => $useId, 'step_order' => 1, 'rule_type' => 'action', 'action_type' => 'set_attribute', 'config' => json_encode(['attribute' => "{$P}_city", 'text' => 'Pune'])]);
    $usage = $attrs->usedIn("{$P}_city");
    check('P9 Attributes page "Used in" finds workflow', in_array($useId, array_column($usage['workflows'], 'id'), true));
    $db->table('automation_rules')->where('automation_id', $useId)->delete();
    $db->table('automations')->where('id', $useId)->delete();
    check('P10 quick-reply/sequence renderer fills attributes', service('quickReplies')->render('Hi {{name}} from {{contact.' . $P . '_city}}', $contacts->find($c3)) === 'Hi ZQA Three from Pune');

    $tplId = (int) model(\App\Models\TemplateModel::class)->insert(['name' => 'zqa_var_tpl', 'language' => 'en', 'category' => 'UTILITY', 'body' => 'Hi {{1}}, city {{2}}', 'status' => 'APPROVED']);
    $wfg   = new \App\Libraries\WorkflowGraph();
    $tplNode = static fn (array $vars) => ['nodes' => [['id' => 'a', 'type' => 'action', 'data' => ['action_type' => 'send_template', 'template_name' => 'zqa_var_tpl', 'variables' => $vars]]]];
    $vErr = $wfg->validate($tplNode(['1' => '{{contact.name}}']));
    check('V1 workflow save blocks unmapped template variable', count($vErr) === 1 && str_contains($vErr[0], '{{2}}'), json_encode($vErr));
    check('V2 workflow save OK when all variables mapped', $wfg->validate($tplNode(['1' => '{{contact.name}}', '2' => 'Pune'])) === []);
    check('V3 workflow save needs a template', $wfg->validate(['nodes' => [['id' => 'a', 'data' => ['action_type' => 'send_template']]]]) !== []);
    $db->table('templates')->where('id', $tplId)->delete();

    $sel = view('partials/attribute_select', ['name' => 'kw_attr_key[]', 'selected' => "{$P}_city", 'attributeKeys' => ['legacy_key', 'mobile']], ['saveData' => false]);
    check('V4 attribute dropdown: defined attr selected, legacy key listed, mobile hidden',
        str_contains($sel, 'value="' . $P . '_city" selected') && str_contains($sel, 'value="legacy_key"') && ! str_contains($sel, 'value="mobile"'));

    $contacts->update($c3, ['custom_fields' => array_merge($cf($c3), ["{$P}_undef" => 'x'])]);
    $undef = $attrs->undefinedKeys();
    check('V5 Attributes page lists keys found on contacts but not defined', isset($undef["{$P}_undef"]) && ! isset($undef["{$P}_city"]), json_encode($undef));

    $M = static fn (string $msg, string $kw, string $type) => \App\Libraries\KeywordMatcher::matches($msg, $kw, $type);
    check('KM1 exact: "Hi!" matches hi, "hi mangesh" does not', $M('Hi!', 'hi', 'exact') && ! $M('hi mangesh', 'hi', 'exact'));
    check('KM2 contains: "hi mangesh" / "ok hi" match, "this" does not', $M('hi mangesh', 'hi', 'contains') && $M('ok hi there', 'hi', 'contains') && ! $M('this is', 'hi', 'contains'));
    check('KM3 starts with: "hi mangesh" yes, "hindi" / "ok hi" no', $M('hi mangesh', 'hi', 'starts_with') && ! $M('hindi', 'hi', 'starts_with') && ! $M('ok hi', 'hi', 'starts_with'));
    check('KM4 comma list: "hi, hello" matches "Hello"', $M('Hello', 'hi, hello', 'exact') && $M('price list please', 'rate, price list', 'contains'));
    $mt = new ReflectionMethod(\App\Libraries\AutomationEngine::class, 'matchesTriggerConfig');
    $mt->setAccessible(true);
    $engK = new \App\Libraries\AutomationEngine();
    check('KM5 workflow trigger exact vs contains', $mt->invoke($engK, ['keyword' => 'hi', 'content' => 'hi', 'match_type' => 'exact'], ['content' => 'Hi'])
        && ! $mt->invoke($engK, ['keyword' => 'hi', 'content' => 'hi', 'match_type' => 'exact'], ['content' => 'hi mangesh'])
        && $mt->invoke($engK, ['keyword' => 'hi', 'match_type' => 'contains'], ['content' => 'hi mangesh']));
    check('KM6 workflow trigger without match type = contains (old flows)', $mt->invoke($engK, ['keyword' => 'hi'], ['content' => 'hi mangesh']) && ! $mt->invoke($engK, ['keyword' => 'hi'], ['content' => 'this']));
    $kwM   = model(\App\Models\KeywordModel::class);
    $kwIds = [
        (int) $kwM->insert(['keyword' => 'zqahi', 'match_type' => 'exact', 'response_type' => 'text', 'response_content' => 'E', 'is_active' => 1]),
        (int) $kwM->insert(['keyword' => 'zqahey, zqayo', 'match_type' => 'contains', 'response_type' => 'text', 'response_content' => 'C', 'is_active' => 1]),
    ];
    $bot = new \App\Libraries\KeywordBot();
    $hit = static fn (string $t) => (int) ($bot->findMatch($t)['id'] ?? 0);
    check('KM7 Keywords: exact "zqahi" only whole message; contains list matches "yo zqayo mangesh"',
        (int) $hit('ZQAHI') === $kwIds[0] && (int) $hit('zqahi mangesh') !== $kwIds[0] && (int) $hit('yo zqayo mangesh') === $kwIds[1]);
    $db->table('keywords')->whereIn('id', $kwIds)->delete();

    // ---------------- 8. Delete definition keeps values ----------------
    check('D1 delete definition', $attrs->deleteDefinition((int) $city['id']) && $attrs->definition("{$P}_city") === null);
    check('D2 contact values kept after delete', ($cf($c2)["{$P}_city"] ?? '') === 'Mumbai');
} finally {
    $cleanup();
}

echo "\n=== Result: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
