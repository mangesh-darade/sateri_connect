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
            'name'          => $input['name'] ?? null,
            'mobile'        => $mobile,
            'email'         => $input['email'] ?? null,
            'country'       => $input['country'] ?? null,
            'notes'         => $input['notes'] ?? null,
            'status'        => $input['status'] ?? 'active',
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
