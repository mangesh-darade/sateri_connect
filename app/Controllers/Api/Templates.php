<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\TemplateModel;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * API templates list and Cheerio sync.
 */
class Templates extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $status = (string) ($this->request->getGet('status') ?? '');
        $model  = model(TemplateModel::class);

        if ($status !== '') {
            $model->where('status', $status);
        }

        $items = $model->orderBy('name', 'ASC')->findAll();

        return $this->respondSuccess(['items' => $items]);
    }

    public function sync(): ResponseInterface
    {
        try {
            $api      = service('whatsApp');
            $response = $api->getTemplates();
            $data     = $response['data'] ?? [];

            if (! is_array($data)) {
                return $this->respondError('Unexpected Cheerio response.', [], 502);
            }

            $model  = model(TemplateModel::class);
            $synced = 0;
            $now    = date('Y-m-d H:i:s');

            foreach ($data as $tpl) {
                if (! is_array($tpl) || empty($tpl['name'])) {
                    continue;
                }

                $metaId   = (string) ($tpl['id'] ?? '');
                $name     = (string) $tpl['name'];
                $language = (string) ($tpl['language'] ?? 'en');
                $components = is_array($tpl['components'] ?? null) ? $tpl['components'] : [];

                $parsed = $this->parseComponents($components);

                $row = [
                    'meta_id'        => $metaId !== '' ? $metaId : null,
                    'name'           => $name,
                    'language'       => $language,
                    'category'       => $tpl['category'] ?? null,
                    'status'         => $tpl['status'] ?? null,
                    'header_type'    => $parsed['header_type'],
                    'header_content' => $parsed['header_content'],
                    'body'           => $parsed['body'],
                    'footer'         => $parsed['footer'],
                    'buttons'        => $parsed['buttons'],
                    'variables'      => $parsed['variables'],
                    'raw_payload'    => $tpl,
                    'synced_at'      => $now,
                ];

                $existing = $metaId !== '' ? $model->findByMetaId($metaId) : null;
                if ($existing === null) {
                    $existing = $model->where('name', $name)->where('language', $language)->first();
                }

                if ($existing !== null) {
                    $model->update((int) $existing['id'], $row);
                } else {
                    $model->insert($row);
                }
                $synced++;
            }

            return $this->respondSuccess(['synced' => $synced], "Synced {$synced} template(s).");
        } catch (Throwable $e) {
            return $this->respondError($e->getMessage(), [], 500);
        }
    }

    /**
     * @param list<array<string, mixed>> $components
     *
     * @return array{
     *     header_type: ?string,
     *     header_content: ?string,
     *     body: ?string,
     *     footer: ?string,
     *     buttons: ?array,
     *     variables: ?array
     * }
     */
    protected function parseComponents(array $components): array
    {
        $result = [
            'header_type'    => null,
            'header_content' => null,
            'body'           => null,
            'footer'         => null,
            'buttons'        => null,
            'variables'      => [],
        ];

        foreach ($components as $component) {
            $type = strtoupper((string) ($component['type'] ?? ''));
            switch ($type) {
                case 'HEADER':
                    $result['header_type']    = strtolower((string) ($component['format'] ?? 'text'));
                    $result['header_content'] = $component['text']
                        ?? ($component['example']['header_text'][0] ?? null)
                        ?? ($component['example']['header_url'] ?? null)
                        ?? ($component['example']['link'] ?? null)
                        ?? ($component['example']['header_handle'][0] ?? null);
                    break;
                case 'BODY':
                    $result['body'] = $component['text'] ?? null;
                    if (! empty($component['example']['body_text'][0]) && is_array($component['example']['body_text'][0])) {
                        $result['variables'] = $component['example']['body_text'][0];
                    }
                    break;
                case 'FOOTER':
                    $result['footer'] = $component['text'] ?? null;
                    break;
                case 'BUTTONS':
                    $result['buttons'] = $component['buttons'] ?? null;
                    break;
            }
        }

        if ($result['variables'] === []) {
            $result['variables'] = null;
        }

        return $result;
    }
}
