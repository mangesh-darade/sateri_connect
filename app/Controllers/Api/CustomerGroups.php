<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Libraries\ActivityLogger;
use App\Models\ContactModel;
use App\Models\TagModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * REST API for customer groups (campaign audience lists — backed by tags).
 */
class CustomerGroups extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $q      = trim((string) ($this->request->getGet('q') ?? ''));
        $groups = model(TagModel::class)->listWithContactCounts($q !== '' ? $q : null);

        return $this->respondSuccess([
            'items' => array_map([$this, 'formatGroup'], $groups),
            'meta'  => [
                'total' => count($groups),
            ],
        ]);
    }

    public function show(int $id): ResponseInterface
    {
        $tagModel = model(TagModel::class);
        $group    = $tagModel->find($id);
        if ($group === null) {
            return $this->respondError('Customer group not found.', [], 404);
        }

        $payload               = $this->formatGroup($group);
        $payload['contact_count'] = $tagModel->contactCount($id);
        $payload['contacts']   = $tagModel->getContacts($id);

        return $this->respondSuccess($payload);
    }

    public function create(): ResponseInterface
    {
        $input  = $this->getJsonInput();
        $name   = trim((string) ($input['name'] ?? $input['group_name'] ?? ''));
        $errors = $this->validateGroupName($name);

        if ($errors !== []) {
            return $this->respondValidationError($errors);
        }

        $tagModel = model(TagModel::class);
        if ($tagModel->where('name', $name)->first() !== null) {
            return $this->respondValidationError(['name' => 'A group with this name already exists.']);
        }

        $color = trim((string) ($input['color'] ?? '#6B7280'));
        if ($color !== '' && ! preg_match('/^#[0-9A-Fa-f]{3,8}$/', $color)) {
            return $this->respondValidationError(['color' => 'Color must be a valid hex value (e.g. #6B7280).']);
        }

        $id = (int) $tagModel->insert([
            'name'  => $name,
            'color' => $color !== '' ? $color : '#6B7280',
        ]);

        if ($id <= 0) {
            return $this->respondValidationError($tagModel->errors() ?: ['name' => 'Unable to create group.']);
        }

        (new ActivityLogger())->log('create', 'customer_groups', 'Customer group created via API', ['group_id' => $id]);

        $group = $tagModel->find($id);

        return $this->respondSuccess($this->formatGroup(is_array($group) ? $group : [
            'id'   => $id,
            'name' => $name,
            'color'=> $color,
            'contact_count' => 0,
        ]), 'Customer group created.', 201);
    }

    public function update(int $id): ResponseInterface
    {
        $tagModel = model(TagModel::class);
        $group    = $tagModel->find($id);
        if ($group === null) {
            return $this->respondError('Customer group not found.', [], 404);
        }

        $input = $this->getJsonInput();
        $data  = [];

        if (array_key_exists('name', $input) || array_key_exists('group_name', $input)) {
            $name   = trim((string) ($input['name'] ?? $input['group_name'] ?? ''));
            $errors = $this->validateGroupName($name);
            if ($errors !== []) {
                return $this->respondValidationError($errors);
            }

            $duplicate = $tagModel->where('name', $name)->where('id !=', $id)->first();
            if ($duplicate !== null) {
                return $this->respondValidationError(['name' => 'A group with this name already exists.']);
            }
            $data['name'] = $name;
        }

        if (array_key_exists('color', $input)) {
            $color = trim((string) $input['color']);
            if ($color !== '' && ! preg_match('/^#[0-9A-Fa-f]{3,8}$/', $color)) {
                return $this->respondValidationError(['color' => 'Color must be a valid hex value (e.g. #6B7280).']);
            }
            $data['color'] = $color !== '' ? $color : '#6B7280';
        }

        if ($data === []) {
            return $this->respondValidationError(['name' => 'Provide at least one field to update (name or color).']);
        }

        if (! $tagModel->update($id, $data)) {
            return $this->respondValidationError($tagModel->errors() ?: ['name' => 'Unable to update group.']);
        }

        (new ActivityLogger())->log('update', 'customer_groups', 'Customer group updated via API', ['group_id' => $id]);

        $updated = $tagModel->find($id);
        $payload = $this->formatGroup(is_array($updated) ? $updated : $group);
        $payload['contact_count'] = $tagModel->contactCount($id);

        return $this->respondSuccess($payload, 'Customer group updated.');
    }

    public function delete(int $id): ResponseInterface
    {
        $tagModel = model(TagModel::class);
        $group    = $tagModel->find($id);
        if ($group === null) {
            return $this->respondError('Customer group not found.', [], 404);
        }

        $tagModel->delete($id);
        (new ActivityLogger())->log('delete', 'customer_groups', 'Customer group deleted via API', [
            'group_id' => $id,
            'name'     => $group['name'] ?? '',
        ]);

        return $this->respondSuccess(null, 'Customer group deleted.');
    }

    /**
     * Add a contact to a group by mobile (create if needed) or by contact_id.
     */
    public function addContact(int $id): ResponseInterface
    {
        $tagModel = model(TagModel::class);
        $group    = $tagModel->find($id);
        if ($group === null) {
            return $this->respondError('Customer group not found.', [], 404);
        }

        $input      = $this->getJsonInput();
        $contactId  = (int) ($input['contact_id'] ?? 0);
        $contactModel = model(ContactModel::class);
        $created    = false;

        if ($contactId > 0) {
            $contact = $contactModel->find($contactId);
            if ($contact === null) {
                return $this->respondError('Contact not found.', ['contact_id' => 'Invalid contact.'], 404);
            }
        } else {
            $errors = $this->validateContactInput($input);
            if ($errors !== []) {
                return $this->respondValidationError($errors);
            }

            $mobile   = normalize_phone((string) ($input['mobile'] ?? ''));
            $existing = $contactModel->findByMobile($mobile);

            if ($existing !== null) {
                $contactId = (int) $existing['id'];
                $updates   = [];
                $name      = trim((string) ($input['name'] ?? ''));
                $email     = trim((string) ($input['email'] ?? ''));
                if ($name !== '' && empty($existing['name'])) {
                    $updates['name'] = $name;
                }
                if ($email !== '' && empty($existing['email'])) {
                    $updates['email'] = $email;
                }
                if ($updates !== []) {
                    $contactModel->update($contactId, $updates);
                }
            } else {
                $email = trim((string) ($input['email'] ?? ''));
                $name  = trim((string) ($input['name'] ?? ''));
                $contactId = (int) $contactModel->insert([
                    'name'        => $name !== '' ? $name : null,
                    'mobile'      => $mobile,
                    'external_id' => $mobile,
                    'channel'     => 'whatsapp',
                    'email'       => $email !== '' ? $email : null,
                    'status'      => 'active',
                ]);

                if ($contactId <= 0) {
                    return $this->respondValidationError($contactModel->errors() ?: ['mobile' => 'Unable to create contact.']);
                }
                $created = true;
            }
        }

        $attached = $tagModel->attachContact($id, $contactId);

        (new ActivityLogger())->log(
            $created ? 'create' : 'update',
            'customer_groups',
            'Contact added to customer group via API',
            [
                'group_id'   => $id,
                'contact_id' => $contactId,
                'attached'   => $attached,
                'created'    => $created,
            ]
        );

        $alreadyExists = ! $created;
        $mobileDigits  = normalize_phone((string) ($input['mobile'] ?? ''));

        if ($created) {
            $message = 'Contact created and added to the group.';
        } elseif (! $attached) {
            $message = $mobileDigits !== ''
                ? 'This mobile number already exists (' . $mobileDigits . ') and is already in this group.'
                : 'This contact is already in this group.';
        } elseif ($alreadyExists && $mobileDigits !== '') {
            $message = 'This mobile number already exists (' . $mobileDigits . '). Contact has been added to this group.';
        } else {
            $message = 'Contact added to the group.';
        }

        return $this->respondSuccess([
            'group_id'         => $id,
            'contact_id'       => $contactId,
            'created'          => $created,
            'attached'         => $attached,
            'already_exists'   => $alreadyExists,
            'already_in_group' => $alreadyExists && ! $attached,
            'mobile'           => $mobileDigits !== '' ? $mobileDigits : null,
            'contact'          => $contactModel->getWithTags($contactId),
        ], $message, $created ? 201 : 200);
    }

    public function removeContact(int $id, int $contactId): ResponseInterface
    {
        $tagModel = model(TagModel::class);
        if ($tagModel->find($id) === null) {
            return $this->respondError('Customer group not found.', [], 404);
        }

        if (model(ContactModel::class)->find($contactId) === null) {
            return $this->respondError('Contact not found.', [], 404);
        }

        $tagModel->detachContact($id, $contactId);
        (new ActivityLogger())->log('update', 'customer_groups', 'Contact removed from customer group via API', [
            'group_id'   => $id,
            'contact_id' => $contactId,
        ]);

        return $this->respondSuccess(null, 'Contact removed from group.');
    }

    /**
     * @param array<string, mixed> $group
     * @return array<string, mixed>
     */
    protected function formatGroup(array $group): array
    {
        return [
            'id'            => (int) ($group['id'] ?? 0),
            'name'          => (string) ($group['name'] ?? ''),
            'color'         => (string) ($group['color'] ?? '#6B7280'),
            'contact_count' => (int) ($group['contact_count'] ?? 0),
            'created_at'    => $group['created_at'] ?? null,
            'updated_at'    => $group['updated_at'] ?? null,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validateGroupName(string $name): array
    {
        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Group name is required.';
        } elseif (mb_strlen($name) > 30) {
            $errors['name'] = 'Group name must be 30 characters or less.';
        } elseif (mb_strlen($name) < 2) {
            $errors['name'] = 'Group name must be at least 2 characters.';
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    protected function validateContactInput(array $input): array
    {
        $errors    = [];
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
}
