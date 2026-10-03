<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Models\TemplateModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * REST API v1 Templates Controller.
 * Allows external applications to discover approved WhatsApp templates,
 * preview body text, and inspect required dynamic variables.
 */
class TemplatesController extends BaseV1Controller
{
    /**
     * List WhatsApp templates.
     * GET /api/v1/templates
     *
     * Query parameters:
     * - status: APPROVED (default), PENDING, REJECTED, or "all"
     * - category: MARKETING, UTILITY, AUTHENTICATION
     * - language: e.g. en_US, mr, hi
     * - search: template name search
     * - page: 1
     * - per_page: 25 (max 100)
     */
    public function index(): ResponseInterface
    {
        $status   = trim((string) ($this->request->getGet('status') ?? 'APPROVED'));
        $category = trim((string) ($this->request->getGet('category') ?? ''));
        $language = trim((string) ($this->request->getGet('language') ?? ''));
        $search   = trim((string) ($this->request->getGet('search') ?? ''));
        $page     = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage  = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 25)));

        $model   = model(TemplateModel::class);
        $builder = $model->builder();

        if (strtolower($status) !== 'all') {
            $builder->where('status', strtoupper($status));
        }

        if ($category !== '') {
            $builder->where('category', strtoupper($category));
        }

        if ($language !== '') {
            $builder->where('language', $language);
        }

        if ($search !== '') {
            $builder->like('name', $search);
        }

        $totalBuilder = clone $builder;
        $total        = (int) $totalBuilder->countAllResults();

        $rows = $builder->orderBy('name', 'ASC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();

        $items = array_map(function (array $row): array {
            $variables = [];
            if (! empty($row['variables'])) {
                $decoded = is_string($row['variables']) ? json_decode($row['variables'], true) : $row['variables'];
                $variables = is_array($decoded) ? $decoded : [];
            }

            $buttons = [];
            if (! empty($row['buttons'])) {
                $decoded = is_string($row['buttons']) ? json_decode($row['buttons'], true) : $row['buttons'];
                $buttons = is_array($decoded) ? $decoded : [];
            }

            return [
                'id'              => (int) $row['id'],
                'meta_id'         => $row['meta_id'] ?? null,
                'name'            => $row['name'],
                'language'        => $row['language'] ?? 'en_US',
                'category'        => $row['category'] ?? 'UTILITY',
                'status'          => $row['status'] ?? 'APPROVED',
                'header_type'     => $row['header_type'] ?? null,
                'header_content'  => $row['header_content'] ?? null,
                'body'            => $row['body'] ?? '',
                'footer'          => $row['footer'] ?? null,
                'buttons'         => $buttons,
                'variables_count' => count($variables),
                'variables'       => $variables,
                'synced_at'       => $row['synced_at'] ?? null,
            ];
        }, $rows);

        return $this->respondSuccess([
            'templates' => $items,
        ], 'Templates retrieved successfully.', 200, [
            'total'        => $total,
            'per_page'     => $perPage,
            'current_page' => $page,
            'last_page'    => (int) ceil($total / $perPage),
        ]);
    }

    /**
     * Get single template details and sample payload for send-template.
     * GET /api/v1/templates/{idOrName}
     */
    public function show(string $idOrName): ResponseInterface
    {
        $idOrName = trim($idOrName);
        if ($idOrName === '') {
            return $this->respondError('Template ID or name is required.', 400);
        }

        $model = model(TemplateModel::class);
        $row   = null;

        if (ctype_digit($idOrName)) {
            $row = $model->find((int) $idOrName);
        }

        if (! $row) {
            $row = $model->where('name', $idOrName)->first();
        }

        if (! $row) {
            return $this->respondError("Template '{$idOrName}' not found.", 404);
        }

        $variables = [];
        if (! empty($row['variables'])) {
            $decoded = is_string($row['variables']) ? json_decode($row['variables'], true) : $row['variables'];
            $variables = is_array($decoded) ? $decoded : [];
        }

        $buttons = [];
        if (! empty($row['buttons'])) {
            $decoded = is_string($row['buttons']) ? json_decode($row['buttons'], true) : $row['buttons'];
            $buttons = is_array($decoded) ? $decoded : [];
        }

        // Generate sample variables placeholder
        $sampleVars = [];
        for ($i = 1; $i <= max(1, count($variables)); $i++) {
            $sampleVars[] = "value_{$i}";
        }

        return $this->respondSuccess([
            'id'             => (int) $row['id'],
            'meta_id'        => $row['meta_id'] ?? null,
            'name'           => $row['name'],
            'language'       => $row['language'] ?? 'en_US',
            'category'       => $row['category'] ?? 'UTILITY',
            'status'         => $row['status'] ?? 'APPROVED',
            'header_type'    => $row['header_type'] ?? null,
            'header_content' => $row['header_content'] ?? null,
            'body'           => $row['body'] ?? '',
            'footer'         => $row['footer'] ?? null,
            'buttons'        => $buttons,
            'variables'      => $variables,
            'sample_send_payload' => [
                'to'            => '+919876543210',
                'template_name' => $row['name'],
                'language'      => $row['language'] ?? 'en_US',
                'variables'     => $sampleVars,
            ],
        ], 'Template retrieved successfully.');
    }
}
