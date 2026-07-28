<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\ActivityLogger;
use App\Models\KeywordModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Keyword / auto-reply bot management.
 */
class Keywords extends BaseController
{
    public function index(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('keywords.view')) {
            return $denied;
        }

        $keywords = model(KeywordModel::class)
            ->orderBy('menu_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->findAll();

        return $this->render('keywords/index', [
            'pageTitle' => 'Keywords',
            'keywords'  => $keywords,
        ]);
    }

    public function create(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('keywords.create')) {
            return $denied;
        }

        return $this->render('keywords/form', [
            'pageTitle'    => 'Create Keyword',
            'keyword'      => null,
            'parents'      => model(KeywordModel::class)->where('parent_id', null)->orderBy('menu_order', 'ASC')->findAll(),
            'automations'  => model(\App\Models\AutomationModel::class)->where('is_active', 1)->orderBy('name', 'ASC')->findAll(),
            'templates'    => model(\App\Models\TemplateModel::class)->where('status', 'APPROVED')->orderBy('name', 'ASC')->findAll(),
        ]);
    }

    public function store(): ResponseInterface
    {
        if ($denied = $this->requirePermission('keywords.create')) {
            return $denied;
        }

        $rules = [
            'keyword'       => 'required|max_length[191]',
            'match_type'    => 'required|in_list[exact,contains,starts_with]',
            'response_type' => 'permit_empty|max_length[50]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $payload = $this->request->getPost('response_payload');
        if (is_string($payload) && $payload !== '') {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : null;
        }

        $maxOrder = (int) (db_connect()->table('keywords')->selectMax('menu_order')->get()->getRow()->menu_order ?? 0);

        $id = model(KeywordModel::class)->insert([
            'keyword'          => $this->request->getPost('keyword'),
            'match_type'       => $this->request->getPost('match_type'),
            'response_type'    => $this->request->getPost('response_type') ?: 'text',
            'response_content' => $this->request->getPost('response_content'),
            'response_payload' => $this->normalizeKeywordPayload(
                is_array($payload) ? $payload : null,
                (string) ($this->request->getPost('response_type') ?: 'text'),
                (string) ($this->request->getPost('response_content') ?? '')
            ),
            'parent_id'        => $this->request->getPost('parent_id') ?: null,
            'menu_order'       => (int) ($this->request->getPost('menu_order') ?? ($maxOrder + 1)),
            'is_active'        => (int) ($this->request->getPost('is_active') ?? 0),
        ]);

        if (! $id) {
            return redirect()->back()->withInput()->with('errors', model(KeywordModel::class)->errors());
        }

        (new ActivityLogger())->log('create', 'keywords', 'Keyword created', ['keyword_id' => $id]);

        return redirect()->to('/keywords')->with('success', 'Keyword created.');
    }

    public function edit(int $id): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('keywords.edit')) {
            return $denied;
        }

        $keyword = model(KeywordModel::class)->find($id);
        if ($keyword === null) {
            return redirect()->to('/keywords')->with('error', 'Keyword not found.');
        }

        return $this->render('keywords/form', [
            'pageTitle'   => 'Edit Keyword',
            'keyword'     => $keyword,
            'parents'     => model(KeywordModel::class)
                ->where('id !=', $id)
                ->where('parent_id', null)
                ->orderBy('menu_order', 'ASC')
                ->findAll(),
            'automations' => model(\App\Models\AutomationModel::class)->where('is_active', 1)->orderBy('name', 'ASC')->findAll(),
            'templates'   => model(\App\Models\TemplateModel::class)->where('status', 'APPROVED')->orderBy('name', 'ASC')->findAll(),
        ]);
    }

    public function update(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('keywords.edit')) {
            return $denied;
        }

        $model = model(KeywordModel::class);
        if ($model->find($id) === null) {
            return redirect()->to('/keywords')->with('error', 'Keyword not found.');
        }

        $rules = [
            'keyword'    => 'required|max_length[191]',
            'match_type' => 'required|in_list[exact,contains,starts_with]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $payload = $this->request->getPost('response_payload');
        if (is_string($payload) && $payload !== '') {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : null;
        }

        $model->update($id, [
            'keyword'          => $this->request->getPost('keyword'),
            'match_type'       => $this->request->getPost('match_type'),
            'response_type'    => $this->request->getPost('response_type') ?: 'text',
            'response_content' => $this->request->getPost('response_content'),
            'response_payload' => $this->normalizeKeywordPayload(
                is_array($payload) ? $payload : null,
                (string) ($this->request->getPost('response_type') ?: 'text'),
                (string) ($this->request->getPost('response_content') ?? '')
            ),
            'parent_id'        => $this->request->getPost('parent_id') ?: null,
            'menu_order'       => (int) ($this->request->getPost('menu_order') ?? 0),
            'is_active'        => (int) ($this->request->getPost('is_active') ?? 0),
        ]);

        (new ActivityLogger())->log('update', 'keywords', 'Keyword updated', ['keyword_id' => $id]);

        return redirect()->to('/keywords')->with('success', 'Keyword updated.');
    }

    public function delete(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('keywords.delete')) {
            return $denied;
        }

        $model = model(KeywordModel::class);
        if ($model->find($id) === null) {
            return $this->request->isAJAX()
                ? $this->jsonResponse(false, null, 'Not found.', [], 404)
                : redirect()->to('/keywords')->with('error', 'Not found.');
        }

        $model->delete($id);
        (new ActivityLogger())->log('delete', 'keywords', 'Keyword deleted', ['keyword_id' => $id]);

        if ($this->request->isAJAX()) {
            return $this->jsonResponse(true, null, 'Keyword deleted.');
        }

        return redirect()->to('/keywords')->with('success', 'Keyword deleted.');
    }

    public function reorder(): ResponseInterface
    {
        if ($denied = $this->requirePermission('keywords.edit')) {
            return $denied;
        }

        $input = $this->request->getJSON(true) ?: $this->request->getPost();
        $order = $input['order'] ?? [];

        if (! is_array($order) || $order === []) {
            return $this->jsonResponse(false, null, 'order array is required.', [], 422);
        }

        $model = model(KeywordModel::class);
        $i     = 0;

        foreach ($order as $item) {
            $id = is_array($item) ? (int) ($item['id'] ?? 0) : (int) $item;
            if ($id <= 0) {
                continue;
            }
            $menuOrder = is_array($item) ? (int) ($item['menu_order'] ?? $i) : $i;
            $model->update($id, ['menu_order' => $menuOrder]);
            $i++;
        }

        return $this->jsonResponse(true, null, 'Order updated.');
    }

    /**
     * Stamp payload with Settings → active provider; auto-build text JSON if empty.
     *
     * @param array<string, mixed>|null $payload
     *
     * @return array<string, mixed>|null
     */
    protected function normalizeKeywordPayload(?array $payload, string $responseType, string $content): ?array
    {
        $provider = function_exists('whatsapp_provider')
            ? whatsapp_provider()
            : (new \App\Libraries\SettingsService())->getWhatsAppProvider();

        if ($payload === null || $payload === []) {
            if ($responseType === 'text' && trim($content) !== '') {
                $payload = [
                    'type' => 'text',
                    'text' => [
                        'preview_url' => false,
                        'body'        => $content,
                    ],
                ];
            } else {
                return $payload;
            }
        }

        $payload['_provider']  = $provider;
        $payload['_generated'] = ! empty($payload['_generated']);

        return $payload;
    }
}
