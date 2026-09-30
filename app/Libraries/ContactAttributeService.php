<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\ContactAttributeModel;
use App\Models\ContactModel;
use InvalidArgumentException;

/**
 * Contact attribute definitions (Cheerio-style "Attributes" settings) and typed value writes.
 * Values live in contacts.custom_fields (core keys like email go to their own column).
 */
class ContactAttributeService
{
    public const TYPES = [
        'text'     => 'Text',
        'number'   => 'Number',
        'date'     => 'Date',
        'dropdown' => 'Dropdown',
        'boolean'  => 'Yes / No',
    ];

    public const KEY_PATTERN = '/^[A-Za-z][A-Za-z0-9_]{0,49}$/';

    protected ?bool $ready = null;

    /** @var array<string, array<string, mixed>>|null */
    protected ?array $cache = null;

    public function tableReady(): bool
    {
        if ($this->ready === null) {
            try {
                $this->ready = db_connect()->tableExists('contact_attributes');
            } catch (\Throwable) {
                $this->ready = false;
            }
        }

        return $this->ready;
    }

    /**
     * Defined attributes keyed by attr_key (options decoded to a list).
     *
     * @return array<string, array<string, mixed>>
     */
    public function definitions(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $this->cache = [];
        if (! $this->tableReady()) {
            return $this->cache;
        }
        foreach (model(ContactAttributeModel::class)->orderBy('label', 'ASC')->findAll() as $row) {
            $row['options'] = $this->decodeOptions($row['options'] ?? null);
            $this->cache[(string) $row['attr_key']] = $row;
        }

        return $this->cache;
    }

    public function definition(string $key): ?array
    {
        return $this->definitions()[$key] ?? null;
    }

    /**
     * Definitions plus how many contacts have a value for each.
     *
     * @return list<array<string, mixed>>
     */
    public function listWithUsage(): array
    {
        $db   = db_connect();
        $rows = [];
        foreach ($this->definitions() as $key => $def) {
            $path = $db->escape('$."' . $key . '"');
            $def['usage'] = (int) $db->table('contacts')
                ->where('deleted_at', null)
                ->where("JSON_EXTRACT(custom_fields, {$path}) IS NOT NULL", null, false)
                ->where("JSON_UNQUOTE(JSON_EXTRACT(custom_fields, {$path})) <> ''", null, false)
                ->countAllResults();
            $def['used_in'] = $this->usedIn((string) $key);
            $rows[] = $def;
        }

        return $rows;
    }

    /**
     * Where an attribute key is referenced: workflows (trigger / update / condition / ask-question /
     * template variables / {{contact.key}} text), keywords (set-attribute actions) and campaigns (attr:key variables).
     *
     * @return array{workflows: list<array{id:int,name:string}>, keywords: list<array{id:int,name:string}>, campaigns: list<array{id:int,name:string}>}
     */
    public function usedIn(string $key): array
    {
        $out = ['workflows' => [], 'keywords' => [], 'campaigns' => []];
        if (! preg_match(self::KEY_PATTERN, $key)) {
            return $out;
        }
        $db = db_connect();

        try {
            $needles = ['"attribute":"' . $key . '"', '"save_as":"' . $key . '"', 'contact.' . $key . '}}', '"field":"contact.' . $key . '"'];
            $b = $db->table('automations a')->distinct()->select('a.id, a.name')
                ->join('automation_rules r', 'r.automation_id = a.id', 'left')
                ->groupStart();
            foreach ($needles as $n) {
                $b->orLike('r.config', $n, 'both', null, true);
            }
            $b->orLike('a.trigger_config', '"attribute":"' . $key . '"', 'both', null, true)->groupEnd();
            foreach ($b->orderBy('a.name', 'ASC')->get()->getResultArray() as $r) {
                $out['workflows'][] = ['id' => (int) $r['id'], 'name' => (string) $r['name']];
            }
        } catch (\Throwable) {
        }

        try {
            if ($db->tableExists('keywords')) {
                foreach ($db->table('keywords')->select('id, keyword')
                    ->like('response_payload', '"_actions"', 'both', null, true)
                    ->like('response_payload', '"' . $key . '":', 'both', null, true)
                    ->get()->getResultArray() as $r) {
                    $out['keywords'][] = ['id' => (int) $r['id'], 'name' => (string) $r['keyword']];
                }
            }
        } catch (\Throwable) {
        }

        try {
            if ($db->tableExists('campaigns')) {
                foreach ($db->table('campaigns')->select('id, name')
                    ->groupStart()->like('variables', '"attr:' . $key . '"', 'both', null, true)->orLike('variables', '{{' . $key . '}}', 'both', null, true)->groupEnd()
                    ->get()->getResultArray() as $r) {
                    $out['campaigns'][] = ['id' => (int) $r['id'], 'name' => (string) $r['name']];
                }
            }
        } catch (\Throwable) {
        }

        return $out;
    }

    /**
     * Create or update a definition. Throws InvalidArgumentException with a user-facing message.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function saveDefinition(array $input, ?int $id = null): array
    {
        if (! $this->tableReady()) {
            throw new InvalidArgumentException('Attributes table is missing. Run "php spark migrate" first.');
        }
        $model    = model(ContactAttributeModel::class);
        $existing = $id !== null ? $model->find($id) : null;
        if ($id !== null && ! is_array($existing)) {
            throw new InvalidArgumentException('Attribute not found.');
        }

        $key   = $existing['attr_key'] ?? trim((string) ($input['attr_key'] ?? ''));
        $label = trim((string) ($input['label'] ?? ''));
        $type  = strtolower(trim((string) ($input['type'] ?? 'text')));

        if ($existing === null) {
            if (! preg_match(self::KEY_PATTERN, $key)) {
                throw new InvalidArgumentException('Attribute key must start with a letter and use only letters, numbers and _ (max 50).');
            }
            if (in_array(strtolower($key), ContactAttributes::coreKeys(), true)) {
                throw new InvalidArgumentException("\"{$key}\" is a built-in contact field — no need to add it.");
            }
            if ($model->where('attr_key', $key)->first() !== null) {
                throw new InvalidArgumentException("Attribute \"{$key}\" already exists.");
            }
        }
        if ($label === '') {
            $label = ucwords(str_replace('_', ' ', $key));
        }
        if (mb_strlen($label) > 100) {
            throw new InvalidArgumentException('Label is too long (max 100 characters).');
        }
        if (! isset(self::TYPES[$type])) {
            throw new InvalidArgumentException('Choose a valid type: ' . implode(', ', array_keys(self::TYPES)) . '.');
        }

        $options = $type === 'dropdown' ? $this->decodeOptions($input['options'] ?? []) : [];
        if ($type === 'dropdown' && $options === []) {
            throw new InvalidArgumentException('Add at least one dropdown option (one per line).');
        }

        $row = ['attr_key' => $key, 'label' => $label, 'type' => $type, 'options' => $options, 'default_value' => null];

        $default = trim((string) ($input['default_value'] ?? ''));
        if ($default !== '') {
            $check = $this->checkValue($row, $default);
            if (! $check['ok']) {
                throw new InvalidArgumentException('Default value: ' . $check['error']);
            }
            $row['default_value'] = $check['value'];
        }

        $data = $row;
        $data['options'] = $options !== [] ? json_encode($options, JSON_UNESCAPED_UNICODE) : null;
        if ($existing !== null) {
            unset($data['attr_key']);
            $model->update($id, $data);
        } else {
            $id = (int) $model->insert($data);
        }
        $this->cache = null;

        return ['id' => $id] + $row;
    }

    public function deleteDefinition(int $id): bool
    {
        if (! $this->tableReady()) {
            return false;
        }
        $this->cache = null;

        return (bool) model(ContactAttributeModel::class)->delete($id);
    }

    /**
     * Validate + normalise a value for an attribute key. Undefined keys accept any text;
     * core email must be a valid address. Empty value = clear (always ok).
     *
     * @return array{ok: bool, value: string, error: string}
     */
    public function normalizeValue(string $key, string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return ['ok' => true, 'value' => '', 'error' => ''];
        }
        if ($key === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'value' => $value, 'error' => 'Enter a valid email address.'];
        }
        if ($key === 'status' && ! in_array(strtolower($value), ['active', 'inactive', 'blocked'], true)) {
            return ['ok' => false, 'value' => $value, 'error' => 'Status must be active, inactive or blocked.'];
        }
        $def = $this->definition($key);

        return $def !== null ? $this->checkValue($def, $value) : ['ok' => true, 'value' => $value, 'error' => ''];
    }

    /**
     * Check a whole custom_fields map (contact form). Values come back normalised; errors are per label.
     *
     * @param array<string, mixed> $fields
     *
     * @return array{fields: array<string, mixed>, errors: list<string>}
     */
    public function normalizeFields(array $fields): array
    {
        $errors = [];
        foreach ($fields as $key => $value) {
            if (! is_scalar($value) && $value !== null) {
                continue;
            }
            $check = $this->normalizeValue((string) $key, (string) $value);
            if ($check['ok']) {
                $fields[$key] = $check['value'];
            } else {
                $errors[] = $this->label((string) $key) . ': ' . $check['error'];
            }
        }

        return ['fields' => $fields, 'errors' => $errors];
    }

    /**
     * Write one attribute on a contact. Core keys update their column; others merge into custom_fields.
     * Empty value removes the custom attribute. Returns the stored (normalised) value.
     */
    public function setContactValue(int $contactId, string $key, string $value): string
    {
        $key = trim($key);
        if ($contactId <= 0 || ! preg_match(self::KEY_PATTERN, $key)) {
            throw new InvalidArgumentException('Invalid attribute name.');
        }
        if ($key === 'mobile') {
            throw new InvalidArgumentException('Mobile number cannot be changed here.');
        }
        $check = $this->normalizeValue($key, $value);
        if (! $check['ok']) {
            throw new InvalidArgumentException($this->label($key) . ': ' . $check['error']);
        }

        $contacts = model(ContactModel::class);
        $contact  = $contacts->find($contactId);
        if (! is_array($contact)) {
            throw new InvalidArgumentException('Contact not found.');
        }

        if (in_array($key, ContactAttributes::coreKeys(), true)) {
            $old = $this->scalar($contact[$key] ?? '');
            $contacts->update($contactId, [$key => $check['value'] !== '' ? $check['value'] : null]);
        } else {
            $fields = $this->customFields($contact);
            $old    = $this->scalar($fields[$key] ?? '');
            if ($check['value'] === '') {
                unset($fields[$key]);
            } else {
                $fields[$key] = $check['value'];
            }
            $contacts->update($contactId, ['custom_fields' => $fields]);
        }

        $this->notifyChanges($contactId, [$key => $old], [$key => $check['value']]);

        return $check['value'];
    }

    /**
     * Fire the "Attribute updated" automation trigger for every key whose value changed.
     * Nested workflow updates are capped so two flows cannot ping-pong forever.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    public function notifyChanges(int $contactId, array $before, array $after): void
    {
        static $depth = 0;
        if ($contactId <= 0 || $depth >= 3) {
            return;
        }
        $depth++;
        try {
            foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
                $key = (string) $key;
                $old = $this->scalar($before[$key] ?? '');
                $new = $this->scalar($after[$key] ?? '');
                if ($key === '' || str_starts_with($key, '_') || $old === $new) {
                    continue;
                }
                try {
                    service('automationEngine')->processTrigger('attribute_updated', [
                        'contact_id'      => $contactId,
                        'attribute'       => $key,
                        'attribute_value' => $new,
                        'old_value'       => $old,
                    ]);
                } catch (\Throwable $e) {
                    log_message('error', 'attribute_updated trigger failed: ' . $e->getMessage());
                }
            }
        } finally {
            $depth--;
        }
    }

    /**
     * Same value for many contacts. Returns how many were updated.
     *
     * @param list<int> $contactIds
     */
    public function bulkSet(array $contactIds, string $key, string $value): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $contactIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            throw new InvalidArgumentException('Select at least one contact.');
        }
        if (in_array($key, ['mobile', 'name', 'notes'], true)) {
            throw new InvalidArgumentException('This field cannot be bulk updated.');
        }
        // Validate once up-front so nothing is half-applied on a bad value.
        $check = $this->normalizeValue($key, $value);
        if (! $check['ok'] || ! preg_match(self::KEY_PATTERN, $key)) {
            throw new InvalidArgumentException($this->label($key) . ': ' . ($check['error'] ?: 'invalid attribute name.'));
        }
        $count = 0;
        foreach ($ids as $id) {
            try {
                $this->setContactValue($id, $key, $check['value']);
                $count++;
            } catch (InvalidArgumentException) {
                // contact deleted meanwhile — skip
            }
        }

        return $count;
    }

    /**
     * Fill defined defaults for keys the contact does not have yet (used on contact create).
     *
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    public function withDefaults(array $fields): array
    {
        foreach ($this->definitions() as $key => $def) {
            $default = (string) ($def['default_value'] ?? '');
            if ($default !== '' && (! array_key_exists($key, $fields) || $fields[$key] === '' || $fields[$key] === null)) {
                $fields[$key] = $default;
            }
        }

        return $fields;
    }

    /**
     * Rows for a contact editor: defined attributes first, then other custom keys the contact has.
     *
     * @param array<string, mixed> $contact
     *
     * @return list<array{key: string, label: string, type: string, options: list<string>, value: string, defined: bool}>
     */
    public function contactRows(array $contact): array
    {
        $fields = $this->customFields($contact);
        $rows   = [];
        foreach ($this->definitions() as $key => $def) {
            $rows[] = [
                'key' => $key, 'label' => (string) $def['label'], 'type' => (string) $def['type'],
                'options' => $def['options'], 'value' => $this->scalar($fields[$key] ?? ''), 'defined' => true,
            ];
            unset($fields[$key]);
        }
        foreach ($fields as $key => $value) {
            $key = (string) $key;
            if ($key === '' || str_starts_with($key, '_')) {
                continue;
            }
            $rows[] = ['key' => $key, 'label' => ucwords(str_replace('_', ' ', $key)), 'type' => 'text', 'options' => [], 'value' => $this->scalar($value), 'defined' => false];
        }

        return $rows;
    }

    public function label(string $key): string
    {
        $def = $this->definition($key);

        return $def !== null ? (string) $def['label'] : ucwords(str_replace('_', ' ', $key));
    }

    /**
     * Options for attribute pickers (campaign audience, template variables…):
     * core contact fields first, then attributes defined on the Attributes page.
     *
     * @return list<array{value: string, label: string, type: string, options: list<string>, group: string}>
     */
    public function pickerFields(): array
    {
        $out = [];
        foreach (['name' => 'Name', 'mobile' => 'Phone', 'email' => 'Email', 'status' => 'Status', 'country' => 'Country', 'birthday' => 'Birthday'] as $key => $label) {
            $out[] = [
                'value'   => $key,
                'label'   => $label,
                'type'    => $key === 'birthday' ? 'date' : ($key === 'status' ? 'dropdown' : 'text'),
                'options' => $key === 'status' ? ['active', 'inactive', 'blocked'] : [],
                'group'   => 'Contact',
            ];
        }
        foreach ($this->definitions() as $key => $def) {
            $out[] = [
                'value'   => (string) $key,
                'label'   => (string) $def['label'],
                'type'    => (string) $def['type'],
                'options' => $def['type'] === 'boolean' ? ['Yes', 'No'] : (array) $def['options'],
                'group'   => 'Attributes',
            ];
        }

        return $out;
    }

    /**
     * Current value of a core field or custom attribute for a contact row ('' when missing).
     *
     * @param array<string, mixed> $contact
     */
    public function valueFor(array $contact, string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            return '';
        }
        if ($key === 'phone') {
            $key = 'mobile';
        }
        if (in_array($key, ContactAttributes::coreKeys(), true)) {
            return $this->scalar($contact[$key] ?? '');
        }
        $fields = $this->customFields($contact);
        if (array_key_exists($key, $fields)) {
            return $this->scalar($fields[$key]);
        }
        $lower = array_change_key_case($fields, CASE_LOWER);

        return $this->scalar($lower[strtolower($key)] ?? '');
    }

    /**
     * @param array<string, mixed> $def
     *
     * @return array{ok: bool, value: string, error: string}
     */
    protected function checkValue(array $def, string $value): array
    {
        $value = trim($value);
        switch ((string) ($def['type'] ?? 'text')) {
            case 'number':
                $n = str_replace([',', ' '], '', $value);
                if (! is_numeric($n)) {
                    return ['ok' => false, 'value' => $value, 'error' => 'Enter a number.'];
                }

                return ['ok' => true, 'value' => (string) ($n + 0), 'error' => ''];

            case 'date':
                $formats = ['Y-m-d', 'd-m-Y', 'd/m/Y', 'd.m.Y', 'Y/m/d', 'j M Y', 'M j, Y', 'd M Y'];
                foreach ($formats as $format) {
                    $d = \DateTime::createFromFormat('!' . $format, $value);
                    if ($d !== false && $d->format($format) === $value) {
                        return ['ok' => true, 'value' => $d->format('Y-m-d'), 'error' => ''];
                    }
                }

                return ['ok' => false, 'value' => $value, 'error' => 'Enter a date like 2026-09-30 or 30/09/2026.'];

            case 'boolean':
                $v = strtolower($value);
                if (in_array($v, ['yes', 'y', 'true', '1', 'ho', 'haan', 'ha'], true)) {
                    return ['ok' => true, 'value' => 'Yes', 'error' => ''];
                }
                if (in_array($v, ['no', 'n', 'false', '0', 'nahi', 'nako'], true)) {
                    return ['ok' => true, 'value' => 'No', 'error' => ''];
                }

                return ['ok' => false, 'value' => $value, 'error' => 'Answer Yes or No.'];

            case 'dropdown':
                foreach ((array) ($def['options'] ?? []) as $option) {
                    if (strcasecmp((string) $option, $value) === 0) {
                        return ['ok' => true, 'value' => (string) $option, 'error' => ''];
                    }
                }

                return ['ok' => false, 'value' => $value, 'error' => 'Choose one of: ' . implode(', ', (array) ($def['options'] ?? [])) . '.'];

            default:
                if (mb_strlen($value) > 1000) {
                    return ['ok' => false, 'value' => $value, 'error' => 'Text is too long (max 1000 characters).'];
                }

                return ['ok' => true, 'value' => $value, 'error' => ''];
        }
    }

    /**
     * @return list<string>
     */
    protected function decodeOptions(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw     = is_array($decoded) ? $decoded : (preg_split('/\r\n|\r|\n|,/', $raw) ?: []);
        }
        $out = [];
        foreach ((array) $raw as $option) {
            $option = trim((string) $option);
            if ($option !== '' && ! in_array($option, $out, true)) {
                $out[] = mb_substr($option, 0, 100);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $contact
     *
     * @return array<string, mixed>
     */
    protected function customFields(array $contact): array
    {
        $raw = $contact['custom_fields'] ?? [];
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        return is_array($raw) ? $raw : [];
    }

    protected function scalar(mixed $value): string
    {
        return is_scalar($value) || $value === null ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE);
    }
}
