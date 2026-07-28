<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\ActivityLogger;
use App\Models\TemplateModel;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * WhatsApp message templates — list, create/submit via Cheerio, sync, preview, delete.
 */
class Templates extends BaseController
{
    public function index(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('templates.view')) {
            return $denied;
        }

        $status = (string) ($this->request->getGet('status') ?? '');
        $model  = model(TemplateModel::class);

        if ($status !== '') {
            $model->where('status', $status);
        }

        $templates = $model->orderBy('name', 'ASC')->findAll();

        return $this->render('templates/index', [
            'pageTitle' => 'Templates',
            'templates' => $templates,
            'filterStatus' => $status,
        ]);
    }

    public function sync(): ResponseInterface
    {
        if ($denied = $this->requirePermission('templates.sync')) {
            return $denied;
        }

        try {
            $api      = service('whatsApp');
            $response = $api->getTemplates();
            $data     = $response['data'] ?? [];

            if (! is_array($data)) {
                return $this->jsonResponse(false, null, 'Unexpected response from Cheerio.', [], 502);
            }

            $model  = model(TemplateModel::class);
            $synced = 0;
            $now    = date('Y-m-d H:i:s');

            foreach ($data as $tpl) {
                if (! is_array($tpl) || empty($tpl['name'])) {
                    continue;
                }

                $parsed   = $this->parseComponents(is_array($tpl['components'] ?? null) ? $tpl['components'] : []);
                $metaId   = (string) ($tpl['id'] ?? '');
                $name     = (string) $tpl['name'];
                $language = (string) ($tpl['language'] ?? 'en');

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

                $existing = null;
                if ($metaId !== '') {
                    $existing = $model->findByMetaId($metaId);
                }
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

            (new ActivityLogger())->log('sync', 'templates', "Synced {$synced} templates from Cheerio");

            if ($this->request->isAJAX()) {
                return $this->jsonResponse(true, ['synced' => $synced], "Synced {$synced} template(s).");
            }

            return redirect()->to('/templates')->with('success', "Synced {$synced} template(s).");
        } catch (Throwable $e) {
            log_message('error', 'Template sync failed: {msg}', ['msg' => $e->getMessage()]);

            if ($this->request->isAJAX()) {
                return $this->jsonResponse(false, null, $e->getMessage(), [], 500);
            }

            return redirect()->to('/templates')->with('error', $e->getMessage());
        }
    }

    public function show(int $id): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('templates.view')) {
            return $denied;
        }

        $template = model(TemplateModel::class)->find($id);
        if ($template === null) {
            return redirect()->to('/templates')->with('error', 'Template not found.');
        }

        return $this->render('templates/show', [
            'pageTitle' => 'Template: ' . $template['name'],
            'template'  => $template,
        ]);
    }

    public function create(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('templates.create')) {
            return $denied;
        }

        return $this->render('templates/create', [
            'pageTitle' => 'Create Template',
        ]);
    }

    public function store(): ResponseInterface
    {
        if ($denied = $this->requirePermission('templates.create')) {
            return $denied;
        }

        $name     = strtolower(trim((string) $this->request->getPost('name')));
        $language = trim((string) ($this->request->getPost('language') ?: 'en_US'));
        $category = strtoupper(trim((string) ($this->request->getPost('category') ?: 'UTILITY')));
        $header   = trim((string) $this->request->getPost('header'));
        $body     = trim((string) $this->request->getPost('body'));
        $footer   = trim((string) $this->request->getPost('footer'));
        $examples = trim((string) $this->request->getPost('body_examples'));

        if ($name === '' || $body === '') {
            return redirect()->back()->withInput()->with('error', 'Name and body are required.');
        }

        if (! preg_match('/^[a-z0-9_]+$/', $name)) {
            return redirect()->back()->withInput()->with('error', 'Name must be lowercase letters, numbers, and underscores only.');
        }

        if (! in_array($category, ['UTILITY', 'MARKETING', 'AUTHENTICATION'], true)) {
            return redirect()->back()->withInput()->with('error', 'Invalid category.');
        }

        try {
            $components = $this->buildSubmitComponents($header, $body, $footer, $examples);
            $api        = service('whatsApp');
            $response   = $api->createTemplate([
                'name'                   => $name,
                'language'               => $language,
                'category'               => $category,
                'components'             => $components,
                'allow_category_change'  => true,
            ]);

            $metaId = (string) ($response['id'] ?? '');
            $status = strtoupper((string) ($response['status'] ?? 'PENDING'));
            $parsed = $this->parseComponents($components);

            model(TemplateModel::class)->insert([
                'meta_id'        => $metaId !== '' ? $metaId : null,
                'name'           => $name,
                'language'       => $language,
                'category'       => $category,
                'status'         => $status !== '' ? $status : 'PENDING',
                'header_type'    => $parsed['header_type'],
                'header_content' => $parsed['header_content'],
                'body'           => $parsed['body'],
                'footer'         => $parsed['footer'],
                'buttons'        => $parsed['buttons'],
                'variables'      => $parsed['variables'],
                'raw_payload'    => $response,
                'synced_at'      => date('Y-m-d H:i:s'),
            ]);

            (new ActivityLogger())->log('create', 'templates', "Submitted template {$name} to Cheerio", [
                'meta_id' => $metaId,
                'status'  => $status,
            ]);

            return redirect()->to('/templates')->with(
                'success',
                "Template \"{$name}\" submitted to Cheerio ({$status}). Sync again after Cheerio approves it."
            );
        } catch (Throwable $e) {
            log_message('error', 'Template create failed: {msg}', ['msg' => $e->getMessage()]);

            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function delete(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('templates.delete')) {
            return $denied;
        }

        $model    = model(TemplateModel::class);
        $template = $model->find($id);
        if ($template === null) {
            return redirect()->to('/templates')->with('error', 'Template not found.');
        }

        try {
            $api = service('whatsApp');
            $api->deleteTemplate(
                (string) $template['name'],
                ! empty($template['meta_id']) ? (string) $template['meta_id'] : null
            );
        } catch (Throwable $e) {
            // Still allow local delete if Cheerio already removed it.
            log_message('warning', 'Cheerio template delete: {msg}', ['msg' => $e->getMessage()]);
        }

        $model->delete($id);
        (new ActivityLogger())->log('delete', 'templates', 'Deleted template ' . ($template['name'] ?? $id));

        return redirect()->to('/templates')->with('success', 'Template deleted.');
    }

    public function preview(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('templates.view')) {
            return $denied;
        }

        $template = model(TemplateModel::class)->find($id);
        if ($template === null) {
            return $this->jsonResponse(false, null, 'Template not found.', [], 404);
        }

        $vars = $this->request->getGet('vars') ?? $this->request->getJSON(true)['vars'] ?? [];
        if (is_string($vars)) {
            $decoded = json_decode($vars, true);
            $vars = is_array($decoded) ? $decoded : [];
        }

        $body = (string) ($template['body'] ?? '');
        $i    = 1;
        foreach ((array) $vars as $value) {
            $body = str_replace('{{' . $i . '}}', (string) $value, $body);
            $i++;
        }

        $variables = $template['variables'] ?? null;
        if (is_string($variables)) {
            $decoded   = json_decode($variables, true);
            $variables = is_array($decoded) ? $decoded : null;
        }
        if (! is_array($variables) || $variables === []) {
            $variables = $this->extractPlaceholders((string) ($template['body'] ?? ''));
        }

        return $this->jsonResponse(true, [
            'id'           => (int) $template['id'],
            'name'         => $template['name'],
            'language'     => $template['language'],
            'header'       => $template['header_content'],
            'header_type'  => $template['header_type'] ?? null,
            'body'         => $body,
            'body_raw'     => $template['body'] ?? '',
            'footer'       => $template['footer'],
            'buttons'      => $template['buttons'],
            'status'       => $template['status'],
            'variables'    => $variables,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function buildSubmitComponents(string $header, string $body, string $footer, string $examplesCsv): array
    {
        $components = [];

        if ($header !== '') {
            $headerComponent = [
                'type'   => 'HEADER',
                'format' => 'TEXT',
                'text'   => $header,
            ];
            $headerVars = $this->extractPlaceholders($header);
            if ($headerVars !== []) {
                $headerComponent['example'] = [
                    'header_text' => array_map(static fn ($n) => 'Sample' . $n, $headerVars),
                ];
            }
            $components[] = $headerComponent;
        }

        $bodyComponent = [
            'type' => 'BODY',
            'text' => $body,
        ];
        $bodyVars = $this->extractPlaceholders($body);
        if ($bodyVars !== []) {
            $exampleValues = array_values(array_filter(array_map('trim', explode(',', $examplesCsv)), static fn ($v) => $v !== ''));
            if (count($exampleValues) < count($bodyVars)) {
                foreach ($bodyVars as $idx => $num) {
                    if (! isset($exampleValues[$idx])) {
                        $exampleValues[$idx] = 'Sample' . $num;
                    }
                }
            }
            $bodyComponent['example'] = [
                'body_text' => [array_slice($exampleValues, 0, count($bodyVars))],
            ];
        }
        $components[] = $bodyComponent;

        if ($footer !== '') {
            $components[] = [
                'type' => 'FOOTER',
                'text' => $footer,
            ];
        }

        return $components;
    }

    /**
     * @return list<string>
     */
    protected function extractPlaceholders(string $body): array
    {
        if ($body === '' || ! preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $body, $matches)) {
            return [];
        }

        $nums = array_map('intval', $matches[1]);
        sort($nums);
        $out = [];
        foreach (array_unique($nums) as $n) {
            $out[] = (string) $n;
        }

        return $out;
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
                    $result['header_content'] = $component['text'] ?? ($component['example']['header_text'][0] ?? null);
                    break;
                case 'BODY':
                    $result['body'] = $component['text'] ?? null;
                    if (! empty($component['example']['body_text'][0]) && is_array($component['example']['body_text'][0])) {
                        $result['variables'] = $component['example']['body_text'][0];
                    } elseif (is_string($result['body']) && preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $result['body'], $m)) {
                        $nums = array_map('intval', $m[1]);
                        sort($nums);
                        $result['variables'] = array_map('strval', array_values(array_unique($nums)));
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
