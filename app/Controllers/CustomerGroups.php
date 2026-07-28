<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\ActivityLogger;
use App\Models\ContactModel;
use App\Models\TagModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Customer Groups — audience lists for campaigns (backed by tags).
 */
class CustomerGroups extends BaseController
{
    public function index(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('contacts.view')) {
            return $denied;
        }

        $search = trim((string) ($this->request->getGet('q') ?? ''));
        $groups = model(TagModel::class)->listWithContactCounts($search !== '' ? $search : null);

        if ($this->request->isAJAX() || $this->request->getGet('json') === '1') {
            return $this->jsonResponse(true, ['groups' => $groups]);
        }

        return $this->render('customer_groups/index', [
            'pageTitle' => 'Customer Groups',
            'groups'    => $groups,
            'search'    => $search,
        ]);
    }

    public function show(int $id): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('contacts.view')) {
            return $denied;
        }

        $tagModel = model(TagModel::class);
        $group    = $tagModel->find($id);
        if ($group === null) {
            return redirect()->to('/customer-groups')->with('error', 'Customer group not found.');
        }

        $contacts = $tagModel->getContacts($id);

        return $this->render('customer_groups/show', [
            'pageTitle' => 'Group: ' . ($group['name'] ?? ''),
            'group'     => $group,
            'contacts'  => $contacts,
        ]);
    }

    public function store(): ResponseInterface
    {
        if ($denied = $this->requirePermission('contacts.create')) {
            return $denied;
        }

        $input = $this->requestInput();
        $mode  = strtolower(trim((string) ($input['mode'] ?? 'new')));
        if (! in_array($mode, ['new', 'existing'], true)) {
            return $this->jsonResponse(false, null, 'Invalid mode. Use new or existing.', ['mode' => 'Invalid mode.'], 422);
        }

        $name  = trim((string) ($input['group_name'] ?? $input['label_name'] ?? ''));
        $tagId = (int) ($input['group_id'] ?? $input['tag_id'] ?? 0);

        $errors = $this->validateAddContactPayload($input, $mode, $name, $tagId);
        if ($errors !== []) {
            $message = (string) (reset($errors) ?: 'Please fix the highlighted fields.');

            return $this->jsonResponse(false, null, $message, $errors, 422);
        }

        if ($mode === 'existing') {
            $group = model(TagModel::class)->find($tagId);
            if ($group === null) {
                return $this->jsonResponse(false, null, 'Customer group not found.', ['group_id' => 'Customer group not found.'], 404);
            }
        } else {
            $group = model(TagModel::class)->findOrCreateByName($name);
            $tagId = (int) $group['id'];
        }

        $contactName  = trim((string) ($input['name'] ?? ''));
        $email        = trim((string) ($input['email'] ?? ''));
        $mobile       = normalize_phone((string) ($input['mobile'] ?? ''));
        $contactModel = model(ContactModel::class);
        $existing     = $contactModel->findByMobile($mobile);
        $created      = false;

        if ($existing !== null) {
            $contactId = (int) $existing['id'];
            $updates   = [];
            if ($contactName !== '' && empty($existing['name'])) {
                $updates['name'] = $contactName;
            }
            if ($email !== '' && empty($existing['email'])) {
                $updates['email'] = $email;
            }
            if ($updates !== [] && ! $contactModel->update($contactId, $updates)) {
                return $this->jsonResponse(
                    false,
                    null,
                    'Unable to update contact.',
                    $contactModel->errors() ?: ['name' => 'Unable to update contact.'],
                    422
                );
            }
        } else {
            $contactId = (int) $contactModel->insert([
                'name'        => $contactName !== '' ? $contactName : null,
                'mobile'      => $mobile,
                'external_id' => $mobile,
                'channel'     => 'whatsapp',
                'email'       => $email !== '' ? $email : null,
                'status'      => 'active',
            ]);

            if ($contactId <= 0) {
                return $this->jsonResponse(
                    false,
                    null,
                    'Unable to create contact.',
                    $contactModel->errors() ?: ['mobile' => 'Unable to create contact.'],
                    422
                );
            }
            $created = true;
        }

        $attached = model(TagModel::class)->attachContact($tagId, $contactId);

        (new ActivityLogger())->log(
            $created ? 'create' : 'update',
            'customer_groups',
            $created
                ? 'Contact created and added to customer group'
                : ($attached ? 'Contact added to customer group' : 'Contact already in customer group'),
            [
                'group_id'   => $tagId,
                'contact_id' => $contactId,
                'attached'   => $attached,
            ]
        );

        if ($created) {
            try {
                $contact = $contactModel->find($contactId);
                service('automationEngine')->processTrigger('contact_created', [
                    'contact_id' => $contactId,
                    'contact'    => $contact,
                    'source'     => 'customer_groups',
                ]);
                service('automationEngine')->processTrigger('tag_added', [
                    'contact_id' => $contactId,
                    'contact'    => $contact,
                    'tag_id'     => $tagId,
                ]);
            } catch (\Throwable $e) {
                log_message('error', 'Customer group contact automation error: {msg}', ['msg' => $e->getMessage()]);
            }
        } elseif ($attached) {
            try {
                $contact = $contactModel->find($contactId);
                service('automationEngine')->processTrigger('tag_added', [
                    'contact_id' => $contactId,
                    'contact'    => $contact,
                    'tag_id'     => $tagId,
                ]);
            } catch (\Throwable $e) {
                log_message('error', 'Customer group tag automation error: {msg}', ['msg' => $e->getMessage()]);
            }
        }

        $alreadyExists = ! $created;
        $displayMobile = $mobile !== '' ? $mobile : trim((string) ($input['mobile'] ?? ''));

        if ($created) {
            $message = 'Contact saved and added to the group.';
        } elseif ($attached) {
            $message = 'This mobile number already exists (' . $displayMobile . '). Contact has been added to this group.';
        } else {
            $message = 'This mobile number already exists (' . $displayMobile . ') and is already in this group.';
        }

        return $this->jsonResponse(true, [
            'group_id'         => $tagId,
            'contact_id'       => $contactId,
            'created'          => $created,
            'attached'         => $attached,
            'already_exists'   => $alreadyExists,
            'already_in_group' => $alreadyExists && ! $attached,
            'mobile'           => $displayMobile,
        ], $message);
    }

    public function createGroup(): ResponseInterface
    {
        if ($denied = $this->requirePermission('contacts.create')) {
            return $denied;
        }

        $input  = $this->requestInput();
        $name   = trim((string) ($input['name'] ?? $input['group_name'] ?? ''));
        $errors = $this->validateGroupName($name, 'name');

        if ($errors !== []) {
            return $this->jsonResponse(false, null, (string) reset($errors), $errors, 422);
        }

        $tagModel = model(TagModel::class);
        if ($tagModel->where('name', $name)->first() !== null) {
            return $this->jsonResponse(false, null, 'A group with this name already exists.', ['name' => 'A group with this name already exists.'], 422);
        }

        $color = trim((string) ($input['color'] ?? '#6B7280'));
        if ($color !== '' && ! preg_match('/^#[0-9A-Fa-f]{3,8}$/', $color)) {
            return $this->jsonResponse(false, null, 'Invalid color.', ['color' => 'Color must be a valid hex value (e.g. #6B7280).'], 422);
        }

        $id = (int) $tagModel->insert([
            'name'  => $name,
            'color' => $color !== '' ? $color : '#6B7280',
        ]);

        if ($id <= 0) {
            return $this->jsonResponse(false, null, 'Unable to create group.', $tagModel->errors() ?: ['name' => 'Unable to create group.'], 422);
        }

        (new ActivityLogger())->log('create', 'customer_groups', 'Customer group created', ['group_id' => $id]);

        return $this->jsonResponse(true, [
            'id'   => $id,
            'name' => $name,
        ], 'Customer group created.');
    }

    public function delete(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('contacts.delete')) {
            return $denied;
        }

        $tagModel = model(TagModel::class);
        $group    = $tagModel->find($id);
        if ($group === null) {
            return $this->request->isAJAX()
                ? $this->jsonResponse(false, null, 'Customer group not found.', ['id' => 'Customer group not found.'], 404)
                : redirect()->to('/customer-groups')->with('error', 'Customer group not found.');
        }

        $tagModel->delete($id);
        (new ActivityLogger())->log('delete', 'customer_groups', 'Customer group deleted', [
            'group_id' => $id,
            'name'     => $group['name'] ?? '',
        ]);

        if ($this->request->isAJAX()) {
            return $this->jsonResponse(true, null, 'Customer group deleted.');
        }

        return redirect()->to('/customer-groups')->with('success', 'Customer group deleted.');
    }

    public function removeContact(int $id, int $contactId): ResponseInterface
    {
        if ($denied = $this->requirePermission('contacts.edit')) {
            return $denied;
        }

        $tagModel = model(TagModel::class);
        if ($tagModel->find($id) === null) {
            return $this->jsonResponse(false, null, 'Customer group not found.', ['group_id' => 'Customer group not found.'], 404);
        }

        if (model(ContactModel::class)->find($contactId) === null) {
            return $this->jsonResponse(false, null, 'Contact not found.', ['contact_id' => 'Contact not found.'], 404);
        }

        $tagModel->detachContact($id, $contactId);
        (new ActivityLogger())->log('update', 'customer_groups', 'Contact removed from customer group', [
            'group_id'   => $id,
            'contact_id' => $contactId,
        ]);

        return $this->jsonResponse(true, null, 'Contact removed from group.');
    }

    public function export(int $id = 0): ResponseInterface
    {
        if ($denied = $this->requirePermission('contacts.export')) {
            return $denied;
        }

        $tagModel = model(TagModel::class);

        if ($id > 0) {
            $group = $tagModel->find($id);
            if ($group === null) {
                return redirect()->to('/customer-groups')->with('error', 'Customer group not found.');
            }

            $contacts = $tagModel->getContacts($id);
            $safeName = preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string) $group['name']) ?: 'group';
            $lines    = ['name,mobile,email,status,created_at'];
            foreach ($contacts as $c) {
                $lines[] = implode(',', [
                    '"' . str_replace('"', '""', (string) ($c['name'] ?? '')) . '"',
                    $c['mobile'] ?? '',
                    $c['email'] ?? '',
                    $c['status'] ?? '',
                    $c['created_at'] ?? '',
                ]);
            }

            return $this->response
                ->setHeader('Content-Type', 'text/csv')
                ->setHeader('Content-Disposition', 'attachment; filename="group_' . $safeName . '_' . date('Ymd_His') . '.csv"')
                ->setBody(implode("\n", $lines));
        }

        $groups = $tagModel->listWithContactCounts();
        $lines  = ['group,contacts,added_on'];
        foreach ($groups as $g) {
            $lines[] = implode(',', [
                '"' . str_replace('"', '""', (string) ($g['name'] ?? '')) . '"',
                (string) ($g['contact_count'] ?? 0),
                $g['created_at'] ?? '',
            ]);
        }

        return $this->response
            ->setHeader('Content-Type', 'text/csv')
            ->setHeader('Content-Disposition', 'attachment; filename="customer_groups_' . date('Ymd_His') . '.csv"')
            ->setBody(implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    protected function validateAddContactPayload(array $input, string $mode, string $groupName, int $tagId): array
    {
        $errors = [];

        if ($mode === 'existing') {
            if ($tagId <= 0) {
                $errors['group_id'] = 'Select an existing customer group.';
            }
        } else {
            $errors = array_merge($errors, $this->validateGroupName($groupName, 'group_name'));
        }

        $mobileRaw = trim((string) ($input['mobile'] ?? ''));
        $mobile    = normalize_phone($mobileRaw);
        $email     = trim((string) ($input['email'] ?? ''));
        $name      = trim((string) ($input['name'] ?? ''));

        if ($mobileRaw === '') {
            $errors['mobile'] = 'Mobile number is required.';
        } elseif ($mobile === '' || strlen($mobile) < 10 || strlen($mobile) > 15) {
            $errors['mobile'] = 'Enter a valid mobile number (10–15 digits, with country code).';
        }

        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }

        if ($name !== '' && mb_strlen($name) > 150) {
            $errors['name'] = 'Name must be 150 characters or less.';
        }

        return $errors;
    }

    /**
     * @return array<string, string>
     */
    protected function validateGroupName(string $name, string $field = 'name'): array
    {
        if ($name === '') {
            return [$field => 'Group name is required.'];
        }
        if (mb_strlen($name) < 2) {
            return [$field => 'Group name must be at least 2 characters.'];
        }
        if (mb_strlen($name) > 30) {
            return [$field => 'Group name must be 30 characters or less.'];
        }

        return [];
    }
}
