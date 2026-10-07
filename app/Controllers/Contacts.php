<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\ActivityLogger;
use App\Libraries\ContactAttributeService;
use App\Libraries\ContactAttributes;
use App\Libraries\ContactExportService;
use App\Libraries\ContactListFilter;
use App\Models\ContactModel;
use App\Models\InternalNoteModel;
use App\Models\MessageModel;
use App\Models\TagModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Contact management with DataTables, CSV/XLSX import/export, tags, duplicates.
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

        $db = db_connect();
        $totalContacts = (int) $db->table('contacts')->where('deleted_at', null)->countAllResults();
        $optedInCount  = (int) $db->table('contacts')->where('deleted_at', null)->where('wa_opt_in', 1)->countAllResults();
        $activeCount   = (int) $db->table('contacts')->where('deleted_at', null)->where('status', 'active')->countAllResults();
        $tags          = model(TagModel::class)->orderBy('name', 'ASC')->findAll();
        $agents        = model(\App\Models\UserModel::class)->where('status', 'active')->orderBy('name', 'ASC')->findAll();

        return $this->render('contacts/index', [
            'pageTitle'      => 'Contacts',
            'tags'           => $tags,
            'agents'         => $agents,
            'stats'          => [
                'total'    => $totalContacts,
                'opted_in' => $optedInCount,
                'active'   => $activeCount,
                'groups'   => count($tags),
            ],
            'attributeKeys'  => ContactAttributes::knownKeys(),
            'attributeDefs'  => service('contactAttributes')->definitions(),
            'attrColumns'    => service('contactAttributes')->listColumns(),
            'attributeOps'   => ContactListFilter::ATTRIBUTE_OPS,
        ]);
    }

    protected function datatable(): ResponseInterface
    {
        $draw    = (int) ($this->request->getGet('draw') ?? 1);
        $start   = (int) ($this->request->getGet('start') ?? 0);
        $length  = (int) ($this->request->getGet('length') ?? 25);
        $filters = ContactListFilter::fromInput($this->request->getGet() ?? []);

        $length = max(1, min(500, $length));

        $db = db_connect();

        $total = $db->table('contacts')->where('deleted_at', null)->countAllResults();

        $applyFilters = static fn ($builder) => ContactListFilter::apply($builder, $filters);

        $countBuilder = $db->table('contacts c')
            ->select('c.id')
            ->where('c.deleted_at', null);
        $applyFilters($countBuilder);
        $recordsFiltered = (int) $countBuilder->countAllResults();

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
        // Attribute columns send their key as the DataTables column name.
        $attrKey = (string) ($this->request->getGet('columns')[$orderCol]['name'] ?? '');
        if ($orderCol >= 7 && preg_match(ContactAttributeService::KEY_PATTERN, $attrKey) === 1) {
            $columns[$orderCol] = "JSON_UNQUOTE(JSON_EXTRACT(c.custom_fields, '$.\"{$attrKey}\"'))";
        }
        $orderBy = $columns[$orderCol] ?? 'c.id';

        // Default / activity sort: newest contacts first (NULL last_message_at no longer hides them).
        if ($orderBy === 'c.last_message_at') {
            $builder->orderBy('c.id', $orderDir);
        } else {
            $builder->orderBy($orderBy, $orderDir, ! str_starts_with($orderBy, 'JSON_'))->orderBy('c.id', 'DESC');
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
            'pageTitle'    => 'Create Contact',
            'contact'      => null,
            'countries'    => countries_list(),
            'tags'         => model(TagModel::class)->orderBy('name', 'ASC')->findAll(),
            'selectedTags' => [],
            'attributeKeys' => ContactAttributes::knownKeys(),
            'attributeDefs' => service('contactAttributes')->definitions(),
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

        if ($consentError = $this->consentFormError()) {
            return redirect()->back()->withInput()->with('error', $consentError);
        }

        $rawMobile   = (string) $this->request->getPost('mobile');
        $countryCode = (string) ($this->request->getPost('country_code') ?: $this->request->getPost('dial_code') ?: '');
        $validated   = validate_phone_with_country($rawMobile, $countryCode);

        if (! $validated['valid']) {
            return redirect()->back()->withInput()->with('error', $validated['error']);
        }

        $mobile = $validated['phone'];
        $model  = model(ContactModel::class);

        if ($model->findByMobile($mobile) !== null) {
            return redirect()->back()->withInput()->with('error', 'A contact with this mobile number already exists.');
        }
        $customFields = $this->validatedCustomFields();
        if (is_string($customFields)) {
            return redirect()->back()->withInput()->with('error', $customFields);
        }

        $countryName = $this->request->getPost('country') ?: ($validated['country']['name'] ?? 'India');

        $id = $model->insert([
            'name'          => $this->request->getPost('name'),
            'mobile'        => $mobile,
            'email'         => $this->request->getPost('email') ?: null,
            'country'       => $countryName,
            'notes'         => $this->request->getPost('notes') ?: null,
            'status'        => $this->request->getPost('status') ?: 'active',
            'birthday'      => $this->request->getPost('birthday') ?: null,
            'assigned_to'   => $this->request->getPost('assigned_to') ?: null,
            'custom_fields' => $customFields,
        ]);

        if (! $id) {
            return redirect()->back()->withInput()->with('errors', $model->errors());
        }

        service('whatsAppConsent')->applyOperatorConsent(
            (int) $id,
            (bool) $this->request->getPost('wa_opt_in'),
            (string) $this->request->getPost('wa_opt_in_source')
        );
        $consentNote = service('whatsAppConsent')->describeConsentRequest(
            service('whatsAppConsent')->requestConsentIfPending($model->find((int) $id) ?? [])
        );

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

        return redirect()->to('/contacts/' . $id)->with('success', trim('Contact created. ' . $consentNote));
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

        $assigned = ! empty($contact['assigned_to']) ? model(\App\Models\UserModel::class)->find((int) $contact['assigned_to']) : null;

        return $this->render('contacts/show', [
            'pageTitle'      => 'Contact: ' . ($contact['name'] ?: $contact['mobile']),
            'contact'        => $contact,
            'messages'       => $messages,
            'notes'          => $notes,
            'messages_total' => $messagesTotal,
            'attributeRows'  => service('contactAttributes')->contactRows($contact),
            'assignedName'   => is_array($assigned) ? (string) ($assigned['name'] ?? '') : '',
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
            'countries'    => countries_list(),
            'tags'         => model(TagModel::class)->orderBy('name', 'ASC')->findAll(),
            'selectedTags' => $selectedTags,
            'attributeKeys' => ContactAttributes::knownKeys(),
            'attributeDefs' => service('contactAttributes')->definitions(),
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

        if ($consentError = $this->consentFormError()) {
            return redirect()->back()->withInput()->with('error', $consentError);
        }

        $rawMobile   = (string) $this->request->getPost('mobile');
        $countryCode = (string) ($this->request->getPost('country_code') ?: $this->request->getPost('dial_code') ?: '');
        $validated   = validate_phone_with_country($rawMobile, $countryCode);

        if (! $validated['valid']) {
            return redirect()->back()->withInput()->with('error', $validated['error']);
        }

        $mobile = $validated['phone'];
        $dup = $model->where('mobile', $mobile)->where('id !=', $id)->first();
        if ($dup !== null) {
            return redirect()->back()->withInput()->with('error', 'A contact with this mobile number already exists.');
        }
        $customFields = $this->validatedCustomFields();
        if (is_string($customFields)) {
            return redirect()->back()->withInput()->with('error', $customFields);
        }

        $countryName = $this->request->getPost('country') ?: ($validated['country']['name'] ?? ($contact['country'] ?? 'India'));

        $fieldsToUpdate = [
            'name'          => (string) ($this->request->getPost('name') ?: ''),
            'mobile'        => $mobile,
            'email'         => (string) ($this->request->getPost('email') ?: ''),
            'country'       => (string) $countryName,
            'notes'         => (string) ($this->request->getPost('notes') ?: ''),
            'status'        => (string) ($this->request->getPost('status') ?: 'active'),
            'birthday'      => (string) ($this->request->getPost('birthday') ?: ''),
            'assigned_to'   => (string) ($this->request->getPost('assigned_to') ?: ''),
        ];

        $changes = [];
        foreach ($fieldsToUpdate as $col => $newVal) {
            $oldVal = (string) ($contact[$col] ?? '');
            if ($oldVal !== $newVal) {
                $changes[$col] = [
                    'old' => $oldVal !== '' ? $oldVal : '(empty)',
                    'new' => $newVal !== '' ? $newVal : '(empty)',
                ];
            }
        }

        $ok = $model->update($id, [
            'name'          => $this->request->getPost('name'),
            'mobile'        => $mobile,
            'email'         => $this->request->getPost('email') ?: null,
            'country'       => $countryName,
            'notes'         => $this->request->getPost('notes') ?: null,
            'status'        => $this->request->getPost('status') ?: 'active',
            'birthday'      => $this->request->getPost('birthday') ?: null,
            'assigned_to'   => $this->request->getPost('assigned_to') ?: null,
            'custom_fields' => $customFields,
        ]);

        if (! $ok) {
            return redirect()->back()->withInput()->with('errors', $model->errors());
        }

        service('whatsAppConsent')->applyOperatorConsent(
            $id,
            (bool) $this->request->getPost('wa_opt_in'),
            (string) $this->request->getPost('wa_opt_in_source')
        );

        $tagIds = $this->request->getPost('tag_ids') ?? $this->request->getPost('tags') ?? [];
        $tagIds = array_map('intval', (array) $tagIds);
        $model->syncTags($id, $tagIds);

        if (is_array($customFields)) {
            $before = is_array($contact['custom_fields'] ?? null) ? $contact['custom_fields'] : (json_decode((string) ($contact['custom_fields'] ?? ''), true) ?: []);
            service('contactAttributes')->notifyChanges($id, $before, $customFields);
        }

        (new ActivityLogger())->log('update', 'contacts', 'Contact updated: ' . ($contact['name'] ?? ('#' . $id)), [
            'contact_id' => $id,
            'changes'    => $changes,
        ]);

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

    /**
     * Customer asked to delete their data: wipe history, keep only an opt-out record.
     */
    public function erase(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('contacts.delete')) {
            return $denied;
        }

        try {
            $result = (new \App\Libraries\ContactErasureService())->erase($id);
        } catch (\Throwable $e) {
            return $this->jsonResponse(false, null, $e->getMessage(), [], 422);
        }

        return $this->jsonResponse(true, $result, 'Customer data erased. The number is kept only as opted-out so it is never messaged again.');
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

        (new ActivityLogger())->log('bulk_tags', 'contacts', 'Updated tags for ' . count($ids) . ' contact(s) (mode: ' . $mode . ')', ['ids' => $ids, 'tag_ids' => $tagIds, 'mode' => $mode]);

        return $this->jsonResponse(true, null, 'Tags updated for selected contacts.');
    }

    protected function consentFormError(): ?string
    {
        if ($this->request->getPost('wa_opt_in') && trim((string) $this->request->getPost('wa_opt_in_source')) === '') {
            return 'Choose how the customer gave WhatsApp consent.';
        }

        return null;
    }

    /**
     * Single-contact WhatsApp consent action (AJAX): opt_in | opt_out | clear_suppression.
     */
    public function consent(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('contacts.edit')) {
            return $denied;
        }

        $contact = model(ContactModel::class)->find($id);
        if ($contact === null) {
            return $this->jsonResponse(false, null, 'Contact not found.', [], 404);
        }

        $input   = $this->request->getJSON(true) ?: $this->request->getPost();
        $action  = (string) ($input['action'] ?? '');
        $source  = (string) ($input['source'] ?? 'other');
        $service = service('whatsAppConsent');

        if (! $service->hasConsentColumns()) {
            return $this->jsonResponse(false, null, 'Run database migrations to enable WhatsApp consent.', [], 409);
        }

        switch ($action) {
            case 'opt_in':
                if ($service->isOptedOut($contact)) {
                    return $this->jsonResponse(
                        false,
                        null,
                        'This customer opted out with STOP. Only they can opt back in by sending START on WhatsApp.',
                        [],
                        422
                    );
                }
                $service->optIn($id, $source);
                $msg = 'WhatsApp opt-in recorded.';
                break;
            case 'opt_out':
                $service->optOut($id, 'operator');
                $msg = 'Contact opted out of WhatsApp messages.';
                break;
            case 'clear_suppression':
                $service->clearSuppression($id);
                $msg = 'Delivery pause cleared.';
                break;
            default:
                return $this->jsonResponse(false, null, 'Unknown consent action.', [], 422);
        }

        return $this->jsonResponse(true, ['contact' => model(ContactModel::class)->find($id)], $msg);
    }

    /**
     * Bulk WhatsApp consent for selected contacts (AJAX).
     */
    public function bulkConsent(): ResponseInterface
    {
        if ($denied = $this->requirePermission('contacts.edit')) {
            return $denied;
        }

        $input  = $this->request->getJSON(true) ?: $this->request->getPost();
        $ids    = array_map('intval', (array) ($input['ids'] ?? []));
        $action = (string) ($input['action'] ?? '');
        $source = (string) ($input['source'] ?? '');

        if ($ids === [] || ! in_array($action, ['opt_in', 'opt_out'], true)) {
            return $this->jsonResponse(false, null, 'Select contacts and a consent action.', [], 422);
        }
        if ($action === 'opt_in' && $source === '') {
            return $this->jsonResponse(false, null, 'Choose how these contacts gave WhatsApp consent.', [], 422);
        }

        $service = service('whatsAppConsent');
        if (! $service->hasConsentColumns()) {
            return $this->jsonResponse(false, null, 'Run database migrations to enable WhatsApp consent.', [], 409);
        }

        $n = $service->bulkSetConsent($ids, $action === 'opt_in', $action === 'opt_in' ? $source : 'operator');
        (new ActivityLogger())->log('bulk_consent', 'contacts', 'WhatsApp consent ' . $action, [
            'ids'     => $ids,
            'source'  => $source,
            'updated' => $n,
        ]);

        $skipped = count(array_unique($ids)) - $n;
        $msg     = $action === 'opt_in'
            ? "WhatsApp opt-in recorded for {$n} contact(s)."
            : "{$n} contact(s) opted out.";
        if ($skipped > 0 && $action === 'opt_in') {
            $msg .= " {$skipped} skipped (customer sent STOP).";
        }

        return $this->jsonResponse(true, ['updated' => $n], $msg);
    }

    /**
     * Set (or clear, with an empty value) one attribute on selected contacts (AJAX).
     */
    public function bulkAttribute(): ResponseInterface
    {
        if ($denied = $this->requirePermission('contacts.edit')) {
            return $denied;
        }
        $input = $this->request->getJSON(true) ?: $this->request->getPost();
        $ids   = array_map('intval', (array) ($input['ids'] ?? []));
        $key   = trim((string) ($input['attribute'] ?? ''));
        $value = (string) ($input['value'] ?? '');
        if ($key === '') {
            return $this->jsonResponse(false, null, 'Choose an attribute.', [], 422);
        }

        try {
            $n = service('contactAttributes')->bulkSet($ids, $key, $value);
        } catch (\InvalidArgumentException $e) {
            return $this->jsonResponse(false, null, $e->getMessage(), [], 422);
        }
        (new ActivityLogger())->log('bulk_attribute', 'contacts', "Attribute {$key} updated", ['ids' => $ids, 'attribute' => $key, 'updated' => $n]);

        $label = service('contactAttributes')->label($key);

        return $this->jsonResponse(true, ['updated' => $n], trim($value) === ''
            ? "{$label} cleared for {$n} contact(s)."
            : "{$label} updated for {$n} contact(s).");
    }

    public function importCsv(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('contacts.import')) {
            return $denied;
        }

        return $this->render('contacts/import', [
            'pageTitle' => 'Import Contacts',
            'countries' => countries_list(),
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
            $error = $file !== null ? $file->getErrorString() : 'No file received.';

            return $this->jsonResponse(false, null, 'Please upload a valid CSV or XLSX file. ' . $error, [], 422);
        }

        $clientName = (string) $file->getClientName();
        $ext = strtolower(pathinfo($clientName, PATHINFO_EXTENSION));
        if (! in_array($ext, ['csv', 'xlsx'], true)) {
            return $this->jsonResponse(false, null, 'Please upload a CSV or XLSX file.', [], 422);
        }

        try {
            $preview = (new \App\Libraries\ContactImportService())->parseUpload(
                $file->getTempName(),
                $clientName
            );
        } catch (\Throwable $e) {
            return $this->jsonResponse(false, null, $e->getMessage(), [], 422);
        }

        $msg = 'Map columns to CRM fields, then import.';
        if (! empty($preview['warning'])) {
            $msg = (string) $preview['warning'] . ' ' . $msg;
        }

        return $this->jsonResponse(true, $preview, $msg);
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

        $optInSource = null;
        if ($this->request->getPost('wa_opt_in')) {
            $optInSource = service('whatsAppConsent')->normalizeSource((string) ($this->request->getPost('wa_opt_in_source') ?: 'import'));
        }

        $defaultCountryCode = (string) ($this->request->getPost('default_country_code') ?: '91');

        try {
            $result = (new \App\Libraries\ContactImportService())->commit(
                $token,
                $mapping,
                $tagId > 0 ? $tagId : null,
                $skipDuplicates,
                $optInSource,
                $defaultCountryCode
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
        if (! empty($result['truncated'])) {
            $msg .= ' Row limit reached (max ' . \App\Libraries\ContactImportService::MAX_ROWS . ').';
        }
        if (($result['consent_requested'] ?? 0) > 0) {
            $msg .= " WhatsApp consent request sent to {$result['consent_requested']}.";
        } elseif (($result['consent_note'] ?? '') !== '') {
            $msg .= ' WhatsApp consent request not sent: ' . $result['consent_note'];
        }
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
            $format = strtolower((string) ($this->request->getGet('format') ?? 'csv'));
            if ($format === 'xlsx') {
                return $this->downloadSampleXlsx();
            }

            $csv = "name,mobile,email,country,notes,tags\n"
                . "Sample Contact,919999999999,sample@example.com,IN,Notes here,\"vip,lead\"\n";

            return $this->response
                ->setHeader('Content-Type', 'text/csv')
                ->setHeader('Content-Disposition', 'attachment; filename="contacts_sample.csv"')
                ->setBody($csv);
        }

        $csv = (new ContactExportService())->csv(ContactListFilter::fromInput($this->request->getGet() ?? []));

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
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

    /**
     * Pull customers from ElintOm POS (sma_companies) into local contacts.
     */
    public function syncFromElintOm(): ResponseInterface
    {
        if ($denied = $this->requirePermission('contacts.import')) {
            return $denied;
        }

        try {
            $stats = (new \App\Libraries\ElintOmCustomerSyncService())->sync();
            $msg   = sprintf(
                'ElintOm customers: %d created, %d updated%s%s%s.',
                $stats['created'],
                $stats['updated'],
                $stats['skipped'] ? ', ' . $stats['skipped'] . ' skipped' : '',
                $stats['deleted'] ? ', ' . $stats['deleted'] . ' deleted in app (not restored)' : '',
                $stats['failed'] ? ', ' . $stats['failed'] . ' failed' : ''
            );

            if ($this->request->isAJAX()) {
                return $this->jsonResponse(true, $stats, $msg);
            }

            return redirect()->to('/contacts')->with('success', $msg);
        } catch (\Throwable $e) {
            log_message('error', 'ElintOm customer sync failed: {msg}', ['msg' => $e->getMessage()]);

            if ($this->request->isAJAX()) {
                return $this->jsonResponse(false, null, $e->getMessage(), [], 500);
            }

            return redirect()->to('/contacts')->with('error', $e->getMessage());
        }
    }

    protected function downloadSampleXlsx(): ResponseInterface
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['name', 'mobile', 'email', 'country', 'notes', 'tags'],
            ['Sample Contact', '919999999999', 'sample@example.com', 'IN', 'Notes here', 'vip,lead'],
        ], null, 'A1');

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        $body = (string) ob_get_clean();
        $spreadsheet->disconnectWorksheets();

        return $this->response
            ->setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->setHeader('Content-Disposition', 'attachment; filename="contacts_sample.xlsx"')
            ->setBody($body);
    }

    /**
     * Form custom attributes checked against their type (Contacts → Attributes).
     *
     * @return array<string, mixed>|string|null normalised fields, or an error message
     */
    protected function validatedCustomFields(): array|string|null
    {
        $fields = $this->parseCustomFields();
        if (! is_array($fields) || $fields === []) {
            return $fields;
        }
        $result = service('contactAttributes')->normalizeFields($fields);
        if ($result['errors'] !== []) {
            return implode(' ', $result['errors']);
        }

        return array_filter($result['fields'], static fn ($v): bool => $v !== '' && $v !== null);
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
