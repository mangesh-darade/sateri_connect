<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\ContactModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * REST CRUD for contacts.
 */
class Contacts extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $page   = max(1, (int) ($this->request->getGet('page') ?? 1));
        $limit  = max(1, min(100, (int) ($this->request->getGet('limit') ?? 25)));
        $q      = (string) ($this->request->getGet('q') ?? '');
        $status = (string) ($this->request->getGet('status') ?? '');

        $model = model(ContactModel::class);
        if ($status !== '') {
            $model->where('status', $status);
        }
        if ($q !== '') {
            $model->groupStart()
                ->like('name', $q)
                ->orLike('mobile', $q)
                ->orLike('email', $q)
                ->groupEnd();
        }

        $total = $model->countAllResults(false);
        $rows  = $model->orderBy('id', 'DESC')->findAll($limit, ($page - 1) * $limit);

        return $this->respondSuccess([
            'items' => $rows,
            'meta'  => [
                'page'  => $page,
                'limit' => $limit,
                'total' => $total,
            ],
        ]);
    }

    public function show(int $id): ResponseInterface
    {
        $contact = model(ContactModel::class)->getWithTags($id);
        if ($contact === null) {
            return $this->respondError('Contact not found.', [], 404);
        }

        return $this->respondSuccess($contact);
    }

    public function create(): ResponseInterface
    {
        $input  = $this->getJsonInput();
        $mobile = normalize_phone((string) ($input['mobile'] ?? ''));

        if ($mobile === '') {
            return $this->respondValidationError(['mobile' => 'Mobile is required.']);
        }

        $model = model(ContactModel::class);
        if ($model->findByMobile($mobile) !== null) {
            return $this->respondError('Contact already exists.', ['mobile' => 'Duplicate mobile.'], 409);
        }

        $id = $model->insert([
            'channel'       => 'whatsapp',
            'external_id'   => $mobile,
            'name'          => $input['name'] ?? null,
            'mobile'        => $mobile,
            'email'         => $input['email'] ?? null,
            'country'       => $input['country'] ?? null,
            'notes'         => $input['notes'] ?? null,
            'status'        => $input['status'] ?? 'active',
            'birthday'      => $input['birthday'] ?? null,
            'custom_fields' => is_array($input['custom_fields'] ?? null) ? $input['custom_fields'] : null,
        ]);

        if (! $id) {
            return $this->respondValidationError($model->errors());
        }

        if (! empty($input['tag_ids']) && is_array($input['tag_ids'])) {
            $model->syncTags((int) $id, array_map('intval', $input['tag_ids']));
        }

        return $this->respondSuccess($model->getWithTags((int) $id), 'Contact created.', 201);
    }

    /**
     * Create or update a contact by mobile (WhatsApp channel).
     * Used by ElintOm POS customer sync.
     */
    public function upsert(): ResponseInterface
    {
        $input  = $this->getJsonInput();
        $result = $this->upsertOne($input);

        if (! ($result['ok'] ?? false)) {
            $status = (int) ($result['status'] ?? 422);

            return $status === 422
                ? $this->respondValidationError($result['errors'] ?? [], (string) ($result['message'] ?? 'Validation failed.'))
                : $this->respondError((string) ($result['message'] ?? 'Upsert failed.'), $result['errors'] ?? [], $status);
        }

        $created = (bool) ($result['created'] ?? false);

        return $this->respondSuccess(
            $result['contact'],
            $created ? 'Contact created.' : 'Contact updated.',
            $created ? 201 : 200
        );
    }

    /**
     * Bulk create/update contacts by mobile.
     * Body: { "contacts": [ {...}, ... ] } (max 200 per request)
     */
    public function bulkUpsert(): ResponseInterface
    {
        $input    = $this->getJsonInput();
        $rows     = $input['contacts'] ?? $input['items'] ?? null;
        if (! is_array($rows)) {
            return $this->respondValidationError(['contacts' => 'contacts array is required.']);
        }

        if (count($rows) > 200) {
            return $this->respondValidationError(['contacts' => 'Maximum 200 contacts per request.']);
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors  = [];
        $items   = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                $skipped++;
                $errors[] = ['index' => $index, 'message' => 'Invalid row.'];
                continue;
            }

            $result = $this->upsertOne($row);
            if (! ($result['ok'] ?? false)) {
                $skipped++;
                $errors[] = [
                    'index'   => $index,
                    'mobile'  => $row['mobile'] ?? null,
                    'message' => $result['message'] ?? 'Failed',
                    'errors'  => $result['errors'] ?? null,
                ];
                continue;
            }

            if ($result['created'] ?? false) {
                $created++;
            } else {
                $updated++;
            }
            $items[] = $result['contact'];
        }

        return $this->respondSuccess([
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors'  => $errors === [] ? null : $errors,
            'items'   => $items,
        ], 'Bulk upsert complete.');
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, created?: bool, contact?: array<string, mixed>|null, message?: string, errors?: array<string, mixed>, status?: int}
     */
    private function upsertOne(array $input): array
    {
        $mobile = normalize_phone((string) ($input['mobile'] ?? ''));
        if ($mobile === '') {
            return [
                'ok'      => false,
                'status'  => 422,
                'message' => 'Validation failed.',
                'errors'  => ['mobile' => 'Mobile is required.'],
            ];
        }

        $model    = model(ContactModel::class);
        $existing = $model->findByMobile($mobile, true);
        $created  = false;

        $customFields = is_array($input['custom_fields'] ?? null) ? $input['custom_fields'] : [];
        if ($existing !== null && is_array($existing['custom_fields'] ?? null)) {
            $customFields = array_merge($existing['custom_fields'], $customFields);
        }

        $data = [
            'channel'       => 'whatsapp',
            'external_id'   => $mobile,
            'mobile'        => $mobile,
            'name'          => array_key_exists('name', $input) ? ($input['name'] ?: null) : ($existing['name'] ?? null),
            'email'         => array_key_exists('email', $input) ? ($input['email'] ?: null) : ($existing['email'] ?? null),
            'country'       => array_key_exists('country', $input) ? ($input['country'] ?: null) : ($existing['country'] ?? null),
            'notes'         => array_key_exists('notes', $input) ? ($input['notes'] ?: null) : ($existing['notes'] ?? null),
            'status'        => $input['status'] ?? ($existing['status'] ?? 'active'),
            'birthday'      => array_key_exists('birthday', $input) ? ($input['birthday'] ?: null) : ($existing['birthday'] ?? null),
            'custom_fields' => $customFields === [] ? ($existing['custom_fields'] ?? null) : $customFields,
        ];

        if ($existing === null) {
            $id = $model->insert($data);
            if (! $id) {
                return [
                    'ok'      => false,
                    'status'  => 422,
                    'message' => 'Validation failed.',
                    'errors'  => $model->errors(),
                ];
            }
            $created = true;
            $contactId = (int) $id;
        } else {
            $contactId = (int) $existing['id'];
            if (! empty($existing['deleted_at'])) {
                $model->restoreContact($contactId);
                $data['status'] = $input['status'] ?? 'active';
            }
            if (! $model->update($contactId, $data)) {
                return [
                    'ok'      => false,
                    'status'  => 422,
                    'message' => 'Validation failed.',
                    'errors'  => $model->errors(),
                ];
            }
        }

        if (! empty($input['tag_ids']) && is_array($input['tag_ids'])) {
            $model->syncTags($contactId, array_map('intval', $input['tag_ids']));
        }

        return [
            'ok'      => true,
            'created' => $created,
            'contact' => $model->getWithTags($contactId),
        ];
    }

    public function update(int $id): ResponseInterface
    {
        $model = model(ContactModel::class);
        if ($model->find($id) === null) {
            return $this->respondError('Contact not found.', [], 404);
        }

        $input = $this->getJsonInput();
        $data  = [];

        foreach (['name', 'email', 'country', 'notes', 'status', 'birthday'] as $field) {
            if (array_key_exists($field, $input)) {
                $data[$field] = $input[$field];
            }
        }

        if (isset($input['mobile'])) {
            $data['mobile'] = normalize_phone((string) $input['mobile']);
        }
        if (isset($input['custom_fields']) && is_array($input['custom_fields'])) {
            $data['custom_fields'] = $input['custom_fields'];
        }

        if ($data !== [] && ! $model->update($id, $data)) {
            return $this->respondValidationError($model->errors());
        }

        if (isset($input['tag_ids']) && is_array($input['tag_ids'])) {
            $model->syncTags($id, array_map('intval', $input['tag_ids']));
        }

        return $this->respondSuccess($model->getWithTags($id), 'Contact updated.');
    }

    public function delete(int $id): ResponseInterface
    {
        $model = model(ContactModel::class);
        if ($model->find($id) === null) {
            return $this->respondError('Contact not found.', [], 404);
        }

        $model->delete($id);

        return $this->respondSuccess(null, 'Contact deleted.');
    }
}
