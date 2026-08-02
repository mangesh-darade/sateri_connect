<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\ActivityLogger;
use App\Libraries\ContactAttributes;
use App\Models\ContactModel;
use App\Models\InternalNoteModel;
use App\Models\MessageModel;
use App\Models\TagModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Contact management with DataTables, CSV import/export, tags, duplicates.
 */
class Contacts extends BaseController
{
    public function index(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('contacts.view')) {
            return $denied;
        }

        if ($this->request->isAJAX() || $this->request->getGet('datatable') === '1') {
            return $this->datatable();
        }

        return $this->render('contacts/index', [
            'pageTitle' => 'Contacts',
            'tags'      => model(TagModel::class)->orderBy('name', 'ASC')->findAll(),
        ]);
    }

    protected function datatable(): ResponseInterface
    {
        $draw       = (int) ($this->request->getGet('draw') ?? 1);
        $start      = (int) ($this->request->getGet('start') ?? 0);
        $length     = (int) ($this->request->getGet('length') ?? 25);
        $search     = (string) ($this->request->getGet('search')['value'] ?? $this->request->getGet('search') ?? '');
        $status     = (string) ($this->request->getGet('status') ?? '');
        $tagId      = (int) ($this->request->getGet('tag_id') ?? 0);
        $assignedTo = (int) ($this->request->getGet('assigned_to') ?? 0);

        $length = max(1, min(500, $length));

        $db = db_connect();

        $total = $db->table('contacts')->where('deleted_at', null)->countAllResults();

        $applyFilters = static function ($builder) use ($search, $status, $tagId, $assignedTo) {
            if ($search !== '') {
                $builder->groupStart()
                    ->like('c.name', $search)
                    ->orLike('c.mobile', $search)
                    ->orLike('c.email', $search)
                    ->groupEnd();
            }
            if ($status !== '') {
                $builder->where('c.status', $status);
            }
            if ($tagId > 0) {
                $builder->where('ct.tag_id', $tagId);
            }
            if ($assignedTo > 0) {
                $builder->where('c.assigned_to', $assignedTo);
            }

            return $builder;
        };

        $countBuilder = $db->table('contacts c')
            ->select('c.id')
            ->join('contact_tags ct', 'ct.contact_id = c.id', 'left')
            ->where('c.deleted_at', null);
        $applyFilters($countBuilder);
        $recordsFiltered = (int) $countBuilder->distinct()->countAllResults();

        $builder = $db->table('contacts c')
            ->select('c.*, GROUP_CONCAT(DISTINCT CONCAT(t.name, "\x1f", IFNULL(t.color, "#667085")) ORDER BY t.name SEPARATOR "\x1e") AS tags_raw')
            ->join('contact_tags ct', 'ct.contact_id = c.id', 'left')
            ->join('tags t', 't.id = ct.tag_id', 'left')
            ->where('c.deleted_at', null)
            ->groupBy('c.id');
        $applyFilters($builder);

        // Match contacts.js column indices: id, name, mobile, email, tags, status, last_message_at, actions
        $orderCol = (int) ($this->request->getGet('order')[0]['column'] ?? 0);
        $orderDir = strtolower((string) ($this->request->getGet('order')[0]['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        $columns  = [
            0 => 'c.id',
            1 => 'c.name',
            2 => 'c.mobile',
            3 => 'c.email',
            5 => 'c.status',
            6 => 'c.last_message_at',
        ];
        $orderBy = $columns[$orderCol] ?? 'c.id';

        // Default / activity sort: newest contacts first (NULL last_message_at no longer hides them).
        if ($orderBy === 'c.last_message_at') {
            $builder->orderBy('c.id', $orderDir);
        } else {
            $builder->orderBy($orderBy, $orderDir)->orderBy('c.id', 'DESC');
        }

        $rows = $builder
            ->limit($length, $start)
            ->get()
            ->getResultArray();

        foreach ($rows as &$row) {
            $tags = [];
            $raw  = (string) ($row['tags_raw'] ?? '');
            if ($raw !== '') {
                foreach (explode("\x1e", $raw) as $part) {
                    if ($part === '') {
                        continue;
                    }
                    $bits = explode("\x1f", $part, 2);
                    $tags[] = [
                        'name'  => $bits[0],
                        'color' => $bits[1] ?? '#667085',
                    ];
                }
            }
            $row['tags'] = $tags;
            unset($row['tags_raw']);

            $cf = $row['custom_fields'] ?? null;
            if (is_string($cf) && $cf !== '') {
                $decoded = json_decode($cf, true);
                $cf = is_array($decoded) ? $decoded : [];
            }
            if (! is_array($cf)) {
                $cf = [];
            }
            $clean = [];
            foreach ($cf as $key => $value) {
                $key = trim((string) $key);
                if ($key === '' || str_starts_with($key, '_')) {
                    continue;
                }
                $clean[$key] = is_scalar($value) || $value === null
                    ? $value
                    : json_encode($value);
            }
            $row['custom_fields'] = $clean;

            foreach (['last_message_at', 'last_reply_at', 'created_at', 'updated_at', 'birthday'] as $dtKey) {
                if (! empty($row[$dtKey])) {
                    $row[$dtKey . '_display'] = format_app_datetime($row[$dtKey]);
                }
            }
        }
        unset($row);

        return $this->response->setJSON([
            'draw'            => $draw,
            'recordsTotal'    => $total,
            'recordsFiltered' => $recordsFiltered,
            'data'            => $rows,
        ]);
    }

    public function create(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('contacts.create')) {
            return $denied;
        }

        return $this->render('contacts/form', [
            'pageTitle' => 'Create Contact',
            'contact'   => null,
            'tags'      => model(TagModel::class)->orderBy('name', 'ASC')->findAll(),
            'selectedTags' => [],
            'attributeKeys' => ContactAttributes::knownKeys(),
        ]);
    }

    public function store(): ResponseInterface
    {
        if ($denied = $this->requirePermission('contacts.create')) {
            return $denied;
        }

        $rules = [
            'name'   => 'permit_empty|max_length[150]',
            'mobile' => 'required|max_length[30]',
            'email'  => 'permit_empty|valid_email|max_length[191]',
            'status' => 'permit_empty|in_list[active,inactive,blocked]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $mobile = normalize_phone((string) $this->request->getPost('mobile'));
        $model  = model(ContactModel::class);

        if ($model->findByMobile($mobile) !== null) {
            return redirect()->back()->withInput()->with('error', 'A contact with this mobile number already exists.');
        }

        $id = $model->insert([
            'name'          => $this->request->getPost('name'),
            'mobile'        => $mobile,
            'email'         => $this->request->getPost('email') ?: null,
            'country'       => $this->request->getPost('country') ?: null,
            'notes'         => $this->request->getPost('notes') ?: null,
            'status'        => $this->request->getPost('status') ?: 'active',
            'birthday'      => $this->request->getPost('birthday') ?: null,
            'assigned_to'   => $this->request->getPost('assigned_to') ?: null,
            'custom_fields' => $this->parseCustomFields(),
        ]);

        if (! $id) {
            return redirect()->back()->withInput()->with('errors', $model->errors());
        }

        $tagIds = $this->request->getPost('tag_ids') ?? $this->request->getPost('tags') ?? [];
        $tagIds = array_map('intval', (array) $tagIds);
        $model->syncTags((int) $id, $tagIds);

        (new ActivityLogger())->log('create', 'contacts', 'Contact created', ['contact_id' => $id]);

        try {
            $contact = $model->find((int) $id);
            service('automationEngine')->processTrigger('contact_created', [
                'contact_id' => (int) $id,
                'contact'    => $contact,
                'source'     => 'panel',
            ]);
            foreach ($tagIds as $tagId) {
                if ($tagId > 0) {
                    service('automationEngine')->processTrigger('tag_added', [
                        'contact_id' => (int) $id,
                        'contact'    => $contact,
                        'tag_id'     => $tagId,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            log_message('error', 'Contact create automation error: {msg}', ['msg' => $e->getMessage()]);
        }

        return redirect()->to('/contacts/' . $id)->with('success', 'Contact created.');
    }

    public function show(int $id): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('contacts.view')) {
            return $denied;
        }

        $contact = model(ContactModel::class)->getWithTags($id);
        if ($contact === null) {
            return redirect()->to('/contacts')->with('error', 'Contact not found.');
        }

        $messageModel  = model(MessageModel::class);
        $messagesTotal = $messageModel->where('contact_id', $id)->countAllResults();
        $messages      = $messageModel
            ->where('contact_id', $id)
            ->orderBy('id', 'DESC')
            ->findAll(100);
        // Newest first in query; show chronological (oldest → newest) like chat history.
        $messages = array_reverse($messages);

        $notes = [];
        try {
            $notes = model(InternalNoteModel::class)->getForContact($id);
        } catch (\Throwable $e) {
            log_message('warning', 'Contact notes load failed: {msg}', ['msg' => $e->getMessage()]);
        }

        return $this->render('contacts/show', [
            'pageTitle'      => 'Contact: ' . ($contact['name'] ?: $contact['mobile']),
            'contact'        => $contact,
            'messages'       => $messages,
            'notes'          => $notes,
            'messages_total' => $messagesTotal,
        ]);
    }

    public function edit(int $id): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('contacts.edit')) {
            return $denied;
        }

        $contact = model(ContactModel::class)->getWithTags($id);
        if ($contact === null) {
            return redirect()->to('/contacts')->with('error', 'Contact not found.');
        }

        $selectedTags = array_map(
            static fn (array $t): int => (int) $t['id'],
            $contact['tags'] ?? []
        );

        return $this->render('contacts/form', [
            'pageTitle'    => 'Edit Contact',
            'contact'      => $contact,
            'tags'         => model(TagModel::class)->orderBy('name', 'ASC')->findAll(),
            'selectedTags' => $selectedTags,
            'attributeKeys' => ContactAttributes::knownKeys(),
        ]);
    }

    public function update(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('contacts.edit')) {
            return $denied;
        }

        $model   = model(ContactModel::class);
        $contact = $model->find($id);

        if ($contact === null) {
            return redirect()->to('/contacts')->with('error', 'Contact not found.');
        }

        $rules = [
            'name'   => 'permit_empty|max_length[150]',
            'mobile' => 'required|max_length[30]',
            'email'  => 'permit_empty|valid_email|max_length[191]',
            'status' => 'permit_empty|in_list[active,inactive,blocked]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $mobile = normalize_phone((string) $this->request->getPost('mobile'));
        if ($mobile === '') {
            return redirect()->back()->withInput()->with('error', 'Mobile number is required.');
        }

        $dup = $model->where('mobile', $mobile)->where('id !=', $id)->first();
        if ($dup !== null) {
            return redirect()->back()->withInput()->with('error', 'A contact with this mobile number already exists.');
        }

        $ok = $model->update($id, [
            'name'          => $this->request->getPost('name'),
            'mobile'        => $mobile,
            'email'         => $this->request->getPost('email') ?: null,
            'country'       => $this->request->getPost('country') ?: null,
            'notes'         => $this->request->getPost('notes') ?: null,
            'status'        => $this->request->getPost('status') ?: 'active',
            'birthday'      => $this->request->getPost('birthday') ?: null,
            'assigned_to'   => $this->request->getPost('assigned_to') ?: null,
            'custom_fields' => $this->parseCustomFields(),
        ]);

        if (! $ok) {
            return redirect()->back()->withInput()->with('errors', $model->errors());
        }

        $tagIds = $this->request->getPost('tag_ids') ?? $this->request->getPost('tags') ?? [];
        $tagIds = array_map('intval', (array) $tagIds);
        $model->syncTags($id, $tagIds);

        (new ActivityLogger())->log('update', 'contacts', 'Contact updated', ['contact_id' => $id]);

        return redirect()->to('/contacts/' . $id)->with('success', 'Contact updated.');
    }

    public function delete(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('contacts.delete')) {
            return $denied;
        }

        $model = model(ContactModel::class);
        if ($model->find($id) === null) {
            return $this->request->isAJAX()
                ? $this->jsonResponse(false, null, 'Contact not found.', [], 404)
                : redirect()->to('/contacts')->with('error', 'Contact not found.');
        }

        $model->delete($id);
        (new ActivityLogger())->log('delete', 'contacts', 'Contact deleted', ['contact_id' => $id]);

        if ($this->request->isAJAX()) {
            return $this->jsonResponse(true, null, 'Contact deleted.');
        }

        return redirect()->to('/contacts')->with('success', 'Contact deleted.');
    }

    public function bulkDelete(): ResponseInterface
    {
        if ($denied = $this->requirePermission('contacts.delete')) {
            return $denied;
        }

        $ids = $this->request->getPost('ids') ?? $this->request->getJSON(true)['ids'] ?? [];
        if (! is_array($ids) || $ids === []) {
            return $this->jsonResponse(false, null, 'No contacts selected.', [], 422);
        }

        $ids = array_map('intval', $ids);
        model(ContactModel::class)->whereIn('id', $ids)->delete();

        (new ActivityLogger())->log('bulk_delete', 'contacts', 'Contacts bulk deleted', ['ids' => $ids]);

        return $this->jsonResponse(true, ['deleted' => count($ids)], 'Contacts deleted.');
    }

    public function bulkTags(): ResponseInterface
    {
        if ($denied = $this->requirePermission('contacts.edit')) {
            return $denied;
        }

        $input  = $this->request->getJSON(true) ?: $this->request->getPost();
        $ids    = array_map('intval', (array) ($input['ids'] ?? []));
        $tagIds = array_map('intval', (array) ($input['tag_ids'] ?? []));
        $mode   = (string) ($input['mode'] ?? 'add'); // add|replace|remove

        if ($ids === [] || $tagIds === []) {
            return $this->jsonResponse(false, null, 'Contacts and tags are required.', [], 422);
        }

        $model = model(ContactModel::class);
        $db    = db_connect();

        foreach ($ids as $contactId) {
            if ($mode === 'replace') {
                $model->syncTags($contactId, $tagIds);
                continue;
            }

            if ($mode === 'remove') {
                $db->table('contact_tags')
                    ->where('contact_id', $contactId)
                    ->whereIn('tag_id', $tagIds)
                    ->delete();
                continue;
            }

            // add
            foreach ($tagIds as $tagId) {
                $exists = $db->table('contact_tags')
                    ->where('contact_id', $contactId)
                    ->where('tag_id', $tagId)
                    ->countAllResults();
                if ($exists === 0) {
                    $db->table('contact_tags')->insert([
                        'contact_id' => $contactId,
                        'tag_id'     => $tagId,
                    ]);
                }
            }
        }

        return $this->jsonResponse(true, null, 'Tags updated for selected contacts.');
    }

    public function importCsv(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('contacts.import')) {
            return $denied;
        }

        return $this->render('contacts/import', [
            'pageTitle' => 'Import Contacts',
            'groups'    => model(TagModel::class)->orderBy('name', 'ASC')->findAll(),
        ]);
    }

    public function importPreview(): ResponseInterface
    {
        if ($denied = $this->requirePermission('contacts.import')) {
            return $denied;
        }

        $file = $this->request->getFile('file') ?? $this->request->getFile('csv_file');
        if ($file === null || ! $file->isValid()) {
            return $this->jsonResponse(false, null, 'Please upload a valid CSV file.', [], 422);
        }

        try {
            $preview = (new \App\Libraries\ContactImportService())->parseUpload(
                $file->getTempName(),
                $file->getClientName()
            );
        } catch (\Throwable $e) {
            return $this->jsonResponse(false, null, $e->getMessage(), [], 422);
        }

        return $this->jsonResponse(true, $preview, 'Map CSV columns to CRM fields, then import.');
    }

    public function importCommit(): ResponseInterface
    {
        if ($denied = $this->requirePermission('contacts.import')) {
            return $denied;
        }

        $token = trim((string) $this->request->getPost('token'));
        $tagId = (int) ($this->request->getPost('group_id') ?? $this->request->getPost('tag_id') ?? 0);
        $skipDuplicates = $this->request->getPost('skip_duplicates') !== null
            && $this->request->getPost('skip_duplicates') !== '0'
            && $this->request->getPost('skip_duplicates') !== '';

        $mappingRaw = $this->request->getPost('mapping');
        if (is_string($mappingRaw)) {
            $decoded = json_decode($mappingRaw, true);
            $mapping = is_array($decoded) ? $decoded : [];
        } elseif (is_array($mappingRaw)) {
            $mapping = $mappingRaw;
        } else {
            $mapping = [];
        }

        /** @var array<string, string> $mapping */
        $mapping = array_map(static fn ($v) => (string) $v, $mapping);

        try {
            $result = (new \App\Libraries\ContactImportService())->commit(
                $token,
                $mapping,
                $tagId > 0 ? $tagId : null,
                $skipDuplicates
            );
        } catch (\Throwable $e) {
            return $this->jsonResponse(false, null, $e->getMessage(), [], 422);
        }

        (new ActivityLogger())->log('import', 'contacts', "Imported {$result['imported']} contacts", $result);

        $msg = "Imported {$result['imported']} contact(s)";
        if (($result['updated'] ?? 0) > 0) {
            $msg .= ", updated {$result['updated']}";
        }
        $msg .= ", skipped {$result['skipped']}.";
        if (($result['custom_fields_created'] ?? []) !== []) {
            $msg .= ' New CRM fields: ' . implode(', ', $result['custom_fields_created']) . '.';
        }
        if (($result['errors'] ?? []) !== []) {
            $msg .= ' Errors: ' . implode('; ', array_slice($result['errors'], 0, 5));
        }

        return $this->jsonResponse(true, array_merge($result, [
            'redirect' => site_url('contacts'),
        ]), $msg);
    }

    public function exportCsv(): ResponseInterface
    {
        if ($denied = $this->requirePermission('contacts.export')) {
            return $denied;
        }

        if ($this->request->getGet('sample') === '1') {
            $csv = "name,mobile,email,country,notes,tags\n"
                . "Sample Contact,919999999999,sample@example.com,IN,Notes here,\"vip,lead\"\n";

            return $this->response
                ->setHeader('Content-Type', 'text/csv')
                ->setHeader('Content-Disposition', 'attachment; filename="contacts_sample.csv"')
                ->setBody($csv);
        }

        $contacts = model(ContactModel::class)
            ->where('deleted_at', null)
            ->orderBy('id', 'ASC')
            ->findAll();

        $lines = ["id,name,mobile,email,country,status,created_at"];
        foreach ($contacts as $c) {
            $lines[] = implode(',', [
                $c['id'],
                '"' . str_replace('"', '""', (string) ($c['name'] ?? '')) . '"',
                $c['mobile'] ?? '',
                $c['email'] ?? '',
                $c['country'] ?? '',
                $c['status'] ?? '',
                $c['created_at'] ?? '',
            ]);
        }

        $csv = implode("\n", $lines);

        return $this->response
            ->setHeader('Content-Type', 'text/csv')
            ->setHeader('Content-Disposition', 'attachment; filename="contacts_' . date('Ymd_His') . '.csv"')
            ->setBody($csv);
    }

    public function search(): ResponseInterface
    {
        if ($denied = $this->requirePermission('contacts.view')) {
            return $denied;
        }

        $term  = (string) ($this->request->getGet('q') ?? '');
        $limit = max(1, min(100, (int) ($this->request->getGet('limit') ?? 20)));
        $rows  = model(ContactModel::class)->search($term, $limit);

        return $this->jsonResponse(true, $rows);
    }

    public function detectDuplicates(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('contacts.view')) {
            return $denied;
        }

        $db = db_connect();
        $dupes = $db->query("
            SELECT mobile, COUNT(*) AS cnt, GROUP_CONCAT(id ORDER BY id) AS ids,
                   GROUP_CONCAT(IFNULL(name, '') ORDER BY id SEPARATOR ' | ') AS names
            FROM contacts
            WHERE deleted_at IS NULL
            GROUP BY mobile
            HAVING cnt > 1
            ORDER BY cnt DESC
        ")->getResultArray();

        if ($this->request->isAJAX()) {
            return $this->jsonResponse(true, $dupes);
        }

        return $this->render('contacts/duplicates', [
            'pageTitle'  => 'Duplicate Contacts',
            'duplicates' => $dupes,
        ]);
    }

    /**
     * Pull contacts from the active provider into local contacts table.
     * Cheerio: Direct API contact directory.
     * Meta: Graph has no directory — uses Cheerio API key (if saved) for CRM sync while messaging stays on Meta.
     */
    public function syncFromCheerio(): ResponseInterface
    {
        if ($denied = $this->requirePermission('contacts.import')) {
            return $denied;
        }

        $settings = service('settingsService');
        $provider = $settings->getWhatsAppProvider();
        $label    = $provider === 'meta' ? 'Meta' : 'Cheerio';

        try {
            $stats = (new \App\Libraries\CheerioSyncService())->syncContacts();
            $source = (string) ($stats['source'] ?? $provider);
            $msg = sprintf(
                '%s contacts: %d created, %d updated%s.',
                $source === 'cheerio' || $source === 'cheerio_directory' ? 'Cheerio' : ucfirst($source),
                $stats['created'],
                $stats['updated'],
                $stats['skipped'] ? ', ' . $stats['skipped'] . ' skipped' : ''
            );
            if ($provider === 'meta' && ($source === 'cheerio' || $source === 'cheerio_directory')) {
                $msg .= ' (messaging stays on Meta)';
            }

            if ($this->request->isAJAX()) {
                return $this->jsonResponse(true, $stats, $msg);
            }

            return redirect()->to('/contacts')->with('success', $msg);
        } catch (\Throwable $e) {
            log_message('error', 'Contact sync failed ({p}): {msg}', [
                'p'   => $label,
                'msg' => $e->getMessage(),
            ]);

            if ($this->request->isAJAX()) {
                return $this->jsonResponse(false, null, $e->getMessage(), [], 500);
            }

            return redirect()->to('/contacts')->with('error', $e->getMessage());
        }
    }
    protected function parseCustomFields(): ?array
    {
        $keys   = $this->request->getPost('attr_key');
        $values = $this->request->getPost('attr_value');
        if (is_array($keys) && is_array($values)) {
            $out = [];
            foreach ($keys as $i => $key) {
                $key = trim((string) $key);
                if ($key === '' || str_starts_with($key, '_')) {
                    continue;
                }
                $out[$key] = isset($values[$i]) ? (string) $values[$i] : '';
            }

            return $out;
        }

        $raw = $this->request->getPost('custom_fields');
        if (is_array($raw)) {
            $out = [];
            foreach ($raw as $key => $value) {
                $key = trim((string) $key);
                if ($key === '' || str_starts_with($key, '_')) {
                    continue;
                }
                $out[$key] = is_scalar($value) ? (string) $value : json_encode($value);
            }

            return $out;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : null;
        }

        return [];
    }
}
