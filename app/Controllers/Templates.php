<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\ActivityLogger;
use App\Models\MediaModel;
use App\Models\TemplateModel;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * WhatsApp message templates — list, create/submit via Cheerio, sync, preview, delete.
 */
class Templates extends BaseController
{
    protected string $uploadPath = WRITEPATH . 'uploads/media/';

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
            $seen   = [];

            foreach ($data as $tpl) {
                if (! is_array($tpl) || empty($tpl['name'])) {
                    continue;
                }

                $componentsList = is_array($tpl['components'] ?? null) ? $tpl['components'] : [];
                $parsed   = $this->parseComponents($componentsList);
                $metaId   = (string) ($tpl['id'] ?? '');
                $name     = (string) $tpl['name'];
                $language = (string) ($tpl['language'] ?? 'en');
                $templateType = $this->detectTemplateTypeFromComponents($tpl, $componentsList);
                $seen[] = strtolower(trim($name)) . '|' . strtolower(trim($language));

                $row = [
                    'meta_id'        => $metaId !== '' ? $metaId : null,
                    'name'           => $name,
                    'language'       => $language,
                    'category'       => $tpl['category'] ?? null,
                    'template_type'  => $templateType,
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

            $disabled = $model->disableMissingFromSync($seen);
            $provider = service('settingsService')->getWhatsAppProvider();
            $msg = "Synced {$synced} template(s) from {$provider}.";
            if ($disabled > 0) {
                $msg .= " Disabled {$disabled} not returned by provider.";
            }

            (new ActivityLogger())->log('sync', 'templates', $msg);

            if ($this->request->isAJAX()) {
                return $this->jsonResponse(true, ['synced' => $synced, 'disabled' => $disabled], $msg);
            }

            return redirect()->to('/templates')->with('success', $msg);
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

        $input = $this->normalizeTemplateInput();
        if ($input['error'] !== null) {
            return $this->templateCreateErrorResponse($input['error']);
        }

        try {
            $components = $this->buildSubmitComponents($input);
            $api        = service('whatsApp');
            $response   = $api->createTemplate([
                'name'                  => $input['name'],
                'language'              => $input['language'],
                'category'              => $input['category'],
                'components'            => $components,
                'allow_category_change' => true,
            ]);

            $responseData = is_array($response['data'] ?? null) ? $response['data'] : $response;
            $metaId = (string) ($responseData['id'] ?? '');
            $status = strtoupper((string) ($responseData['status'] ?? $response['status'] ?? 'PENDING'));
            $parsed = $this->parseComponents($components);
            $storedPayload = is_array($responseData) ? $responseData : [];
            $storedPayload['name'] = $input['name'];
            $storedPayload['language'] = $input['language'];
            $storedPayload['category'] = $input['category'];
            $storedPayload['components'] = $components;

            model(TemplateModel::class)->insert([
                'meta_id'        => $metaId !== '' ? $metaId : null,
                'name'           => $input['name'],
                'language'       => $input['language'],
                'category'       => $input['category'],
                'template_type'  => $input['template_type'],
                'status'         => $status !== '' ? $status : 'PENDING',
                'header_type'    => $parsed['header_type'],
                'header_content' => $parsed['header_content'],
                'body'           => $parsed['body'],
                'footer'         => $parsed['footer'],
                'buttons'        => $parsed['buttons'],
                'variables'      => $parsed['variables'],
                'raw_payload'    => $storedPayload,
                'synced_at'      => date('Y-m-d H:i:s'),
            ]);

            (new ActivityLogger())->log('create', 'templates', "Submitted template {$input['name']} to Cheerio", [
                'meta_id' => $metaId,
                'status'  => $status,
            ]);

            $message = "Template \"{$input['name']}\" submitted to Cheerio ({$status}). Sync again after Cheerio approves it.";
            if ($this->request->isAJAX()) {
                return $this->jsonResponse(true, [
                    'redirect' => site_url('templates'),
                    'status'   => $status,
                    'name'     => $input['name'],
                ], $message);
            }

            return redirect()->to('/templates')->with('success', $message);
        } catch (Throwable $e) {
            log_message('error', 'Template create failed: {msg}', ['msg' => $e->getMessage()]);

            return $this->templateCreateErrorResponse($e->getMessage());
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

        $variableDefinitions = \App\Libraries\WhatsAppTemplateVariables::definitionsForTemplate(
            $template['variables'] ?? null,
            (string) ($template['body'] ?? ''),
            $template['raw_payload'] ?? null
        );
        $variables = array_column($variableDefinitions, 'key');

        return $this->jsonResponse(true, [
            'id'           => (int) $template['id'],
            'name'         => $template['name'],
            'language'     => $template['language'],
            'header'       => $template['header_content'],
            'header_type'  => $template['header_type'] ?? null,
            'needs_header_upload' => $this->templateNeedsHeaderUpload($template),
            'body'         => $body,
            'body_raw'     => $template['body'] ?? '',
            'footer'       => $template['footer'],
            'buttons'      => $template['buttons'],
            'status'       => $template['status'],
            'variables'    => $variables,
            'variable_definitions' => $variableDefinitions,
        ]);
    }

    /**
     * IMAGE/VIDEO/DOCUMENT headers need a real send-time media file when the
     * stored sample is a Meta CDN / localhost URL (not fetchable by WhatsApp).
     *
     * @param array<string, mixed> $template
     */
    protected function templateNeedsHeaderUpload(array $template): bool
    {
        $headerType = strtolower(trim((string) ($template['header_type'] ?? '')));
        if (! in_array($headerType, ['image', 'video', 'document'], true)) {
            return false;
        }

        $sample = trim((string) ($template['header_content'] ?? ''));
        if ($sample === '') {
            return true;
        }

        $host = strtolower((string) (parse_url($sample, PHP_URL_HOST) ?: ''));
        if (
            $host === ''
            || $host === 'localhost'
            || $host === '127.0.0.1'
            || str_ends_with($host, '.local')
            || str_contains($host, 'whatsapp.net')
            || str_contains($host, 'fbcdn.net')
            || str_contains($host, 'facebook.com')
            || str_contains($host, 'scontent.')
        ) {
            return true;
        }

        return false;
    }

    public function uploadHeaderMedia(): ResponseInterface
    {
        // Chat agents send media headers without templates.create.
        if ($denied = $this->requireAnyPermission(['templates.create', 'chat.send'])) {
            return $denied;
        }

        $file = $this->request->getFile('file') ?? $this->request->getFile('media');
        if ($file === null || ! $file->isValid()) {
            return $this->jsonResponse(false, null, 'No valid media file uploaded.', [], 422);
        }

        $mime = (string) $file->getMimeType();
        $allowed = [
            'image/jpeg', 'image/png', 'image/webp', 'image/gif',
            'application/pdf',
            'video/mp4', 'video/3gpp',
        ];
        if (! in_array($mime, $allowed, true)) {
            return $this->jsonResponse(false, null, 'Unsupported file type for template header: ' . $mime, [], 422);
        }

        if ($file->getSize() > 16 * 1024 * 1024) {
            return $this->jsonResponse(false, null, 'File exceeds 16MB limit.', [], 422);
        }

        if (! is_dir($this->uploadPath)) {
            mkdir($this->uploadPath, 0755, true);
        }

        $newName = $file->getRandomName();
        try {
            $file->move($this->uploadPath, $newName);
        } catch (Throwable $e) {
            return $this->jsonResponse(false, null, 'Upload failed: ' . $e->getMessage(), [], 500);
        }

        $fullPath  = $this->uploadPath . $newName;
        $publicUrl = site_url('media/serve/' . $newName);
        $waMediaId = '';
        $providerUploadError = '';

        try {
            $uploaded = service('whatsApp')->uploadMedia($fullPath, $mime);
            $waMediaId = trim((string) ($uploaded['id'] ?? $uploaded['media_id'] ?? ''));
        } catch (Throwable $e) {
            $providerUploadError = $e->getMessage();
            log_message('warning', 'Template header media provider upload failed: {msg}', ['msg' => $providerUploadError]);
        }

        $id = model(MediaModel::class)->insert([
            'filename'      => $newName,
            'original_name' => $file->getClientName(),
            'mime_type'     => $mime,
            'size'          => $file->getSize(),
            'path'          => 'uploads/media/' . $newName,
            'wa_media_id'   => $waMediaId !== '' ? $waMediaId : null,
            'url'           => $publicUrl,
            'uploaded_by'   => $this->userId(),
        ]);

        if (! $id) {
            return $this->jsonResponse(false, null, 'Failed to save uploaded media.', [], 500);
        }

        // Meta (and local serve URLs) cannot use localhost links at send time — require a provider media ID.
        $provider = strtolower((string) service('settingsService')->getWhatsAppProvider());
        if ($waMediaId === '' && ($provider === 'meta' || \App\Libraries\LocalMediaUrl::isLocalHost($publicUrl))) {
            return $this->jsonResponse(
                false,
                [
                    'id'          => (int) $id,
                    'url'         => $publicUrl,
                    'preview_url' => $publicUrl,
                    'filename'    => $file->getClientName(),
                ],
                $providerUploadError !== ''
                    ? ('Media saved locally, but WhatsApp upload failed: ' . $providerUploadError)
                    : 'Media saved locally, but WhatsApp did not return a media ID. Check Meta Phone Number ID / Access Token, then upload again.',
                [],
                422
            );
        }

        return $this->jsonResponse(true, [
            'id'          => (int) $id,
            'url'         => $publicUrl,
            'mime_type'   => $mime,
            // Prefer public HTTPS URL for template samples; media IDs fail on Cheerio create.
            'source'      => $this->resolveUploadedTemplateSource($waMediaId, $publicUrl),
            'preview_url' => $publicUrl,
            'wa_media_id' => $waMediaId !== '' ? $waMediaId : null,
            'filename'    => $file->getClientName(),
        ], 'Header media uploaded.');
    }

    protected function resolveUploadedTemplateSource(string $waMediaId, string $publicUrl): string
    {
        if ($publicUrl !== ''
            && preg_match('#^https?://#i', $publicUrl)
            && ! preg_match('#^https?://(localhost|127\.0\.0\.1)(:|/|$)#i', $publicUrl)
        ) {
            return $publicUrl;
        }

        return $waMediaId !== '' ? $waMediaId : $publicUrl;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return list<array<string, mixed>>
     */
    protected function buildSubmitComponents(array $input): array
    {
        $templateType = (string) ($input['template_type'] ?? 'default');
        $body         = (string) ($input['body'] ?? '');
        $examplesCsv  = (string) ($input['body_examples'] ?? '');

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

        if ($templateType === 'carousel') {
            return [
                $bodyComponent,
                [
                    'type'  => 'CAROUSEL',
                    'cards' => $this->buildCarouselCards(is_array($input['carousel_cards'] ?? null) ? $input['carousel_cards'] : []),
                ],
            ];
        }

        $components = [];
        $headerType = (string) ($input['header_type'] ?? 'none');
        $header     = (string) ($input['header'] ?? '');
        $headerMediaSource = (string) ($input['header_media_source'] ?? '');
        $headerMediaPreviewUrl = (string) ($input['header_media_preview_url'] ?? '');
        $footer = (string) ($input['footer'] ?? '');

        if ($headerType === 'text' && $header !== '') {
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
        } elseif (in_array($headerType, ['image', 'video', 'document'], true) && $headerMediaSource !== '') {
            $handle = $this->resolveTemplateMediaHandle($headerMediaSource, $headerMediaPreviewUrl);
            $components[] = [
                'type'    => 'HEADER',
                'format'  => strtoupper($headerType),
                'example' => [
                    'header_handle' => [$handle],
                    'header_url'    => $headerMediaPreviewUrl !== '' ? $headerMediaPreviewUrl : $handle,
                    'link'          => $headerMediaPreviewUrl !== '' ? $headerMediaPreviewUrl : $handle,
                ],
            ];
        }

        $components[] = $bodyComponent;

        if ($footer !== '') {
            $components[] = [
                'type' => 'FOOTER',
                'text' => $footer,
            ];
        }

        $buttons = $this->buildButtonsFromInput(is_array($input['template_buttons'] ?? null) ? $input['template_buttons'] : []);
        if ($buttons !== []) {
            $components[] = [
                'type'    => 'BUTTONS',
                'buttons' => $buttons,
            ];
        }

        return $components;
    }

    /**
     * @param list<array<string, mixed>> $cards
     *
     * @return list<array<string, mixed>>
     */
    protected function buildCarouselCards(array $cards): array
    {
        $out = [];

        foreach ($cards as $card) {
            if (! is_array($card)) {
                continue;
            }

            $mediaType = strtolower(trim((string) ($card['media_type'] ?? 'image')));
            $mediaSource = trim((string) ($card['media_source'] ?? ''));
            $mediaPreview = trim((string) ($card['media_preview_url'] ?? $mediaSource));
            $cardBody = trim((string) ($card['body'] ?? ''));
            $handle = $this->resolveTemplateMediaHandle($mediaSource, $mediaPreview);

            $cardComponents = [[
                'type'    => 'HEADER',
                'format'  => strtoupper($mediaType),
                'example' => [
                    'header_handle' => [$handle],
                    'header_url'    => $mediaPreview !== '' ? $mediaPreview : $handle,
                    'link'          => $mediaPreview !== '' ? $mediaPreview : $handle,
                ],
            ]];

            if ($cardBody !== '') {
                $cardBodyComponent = [
                    'type' => 'BODY',
                    'text' => $cardBody,
                ];
                $cardVars = $this->extractPlaceholders($cardBody);
                if ($cardVars !== []) {
                    $examples = array_values(array_filter(array_map('trim', explode(',', (string) ($card['body_examples'] ?? ''))), static fn ($v) => $v !== ''));
                    foreach ($cardVars as $idx => $num) {
                        if (! isset($examples[$idx])) {
                            $examples[$idx] = 'Sample' . $num;
                        }
                    }
                    $cardBodyComponent['example'] = [
                        'body_text' => [array_slice($examples, 0, count($cardVars))],
                    ];
                }
                $cardComponents[] = $cardBodyComponent;
            }

            $buttons = $this->buildCtaButtons(
                strtolower(trim((string) ($card['cta_type'] ?? ''))),
                trim((string) ($card['cta_button_text'] ?? '')),
                trim((string) ($card['cta_url'] ?? '')),
                trim((string) ($card['cta_url_example'] ?? '')),
                trim((string) ($card['cta_phone_number'] ?? ''))
            );
            if ($buttons !== []) {
                $cardComponents[] = [
                    'type'    => 'BUTTONS',
                    'buttons' => $buttons,
                ];
            }

            $out[] = ['components' => $cardComponents];
        }

        return $out;
    }

    /**
     * Build Meta/Cheerio BUTTONS payload from the multi-button create form.
     *
     * @param list<array<string, mixed>> $items
     *
     * @return list<array<string, mixed>>
     */
    protected function buildButtonsFromInput(array $items): array
    {
        $buttons = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $type = strtolower(trim((string) ($item['type'] ?? '')));
            $text = trim((string) ($item['text'] ?? ''));
            if ($type === '' || $text === '') {
                continue;
            }

            if ($type === 'quick_reply') {
                $buttons[] = [
                    'type' => 'QUICK_REPLY',
                    'text' => $text,
                ];
                continue;
            }

            $built = $this->buildCtaButtons(
                $type,
                $text,
                trim((string) ($item['url'] ?? '')),
                trim((string) ($item['url_example'] ?? '')),
                trim((string) ($item['phone_number'] ?? ''))
            );
            if ($built !== []) {
                $buttons[] = $built[0];
            }
        }

        return $buttons;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function buildCtaButtons(
        string $ctaType,
        string $ctaButtonText,
        string $ctaUrl,
        string $ctaUrlExample,
        string $ctaPhoneNumber
    ): array {
        if ($ctaType === '' || $ctaButtonText === '') {
            return [];
        }

        if ($ctaType === 'quick_reply') {
            return [[
                'type' => 'QUICK_REPLY',
                'text' => $ctaButtonText,
            ]];
        }

        if ($ctaType === 'url') {
            $button = [
                'type' => 'URL',
                'text' => $ctaButtonText,
                'url'  => $ctaUrl,
            ];
            if ($ctaUrlExample !== '') {
                $button['example'] = [$ctaUrlExample];
            }

            return [$button];
        }

        if ($ctaType === 'phone_number') {
            return [[
                'type'         => 'PHONE_NUMBER',
                'text'         => $ctaButtonText,
                'phone_number' => $ctaPhoneNumber,
            ]];
        }

        return [];
    }

    /**
     * @return array{
     *     error: ?string,
     *     name: string,
     *     language: string,
     *     category: string,
     *     template_type: string,
     *     header_type: string,
     *     header: string,
     *     header_media_source: string,
     *     header_media_preview_url: string,
     *     body: string,
     *     footer: string,
     *     body_examples: string,
     *     template_buttons: list<array<string, mixed>>,
     *     carousel_cards: list<array<string, mixed>>
     * }
     */
    protected function normalizeTemplateInput(): array
    {
        $name         = strtolower(trim((string) $this->request->getPost('name')));
        $language     = trim((string) ($this->request->getPost('language') ?: 'en_US'));
        $category     = strtoupper(trim((string) ($this->request->getPost('category') ?: 'UTILITY')));
        $templateType = strtolower(trim((string) ($this->request->getPost('template_type') ?: 'default')));
        $headerType   = strtolower(trim((string) ($this->request->getPost('header_type') ?: 'text')));
        $header       = trim((string) $this->request->getPost('header'));
        $headerMediaSource = trim((string) $this->request->getPost('header_media_source'));
        $headerMediaPreviewUrl = trim((string) $this->request->getPost('header_media_preview_url'));
        $body         = trim((string) $this->request->getPost('body'));
        $footer       = trim((string) $this->request->getPost('footer'));
        $examples     = trim((string) $this->request->getPost('body_examples'));
        $templateButtons = $this->parseTemplateButtonsInput($this->request->getPost('template_buttons'));
        $carouselCards = $this->parseCarouselCardsInput($this->request->getPost('carousel_cards'));

        // Backward compatibility for older single-CTA posts.
        if ($templateButtons === []) {
            $legacyType = strtolower(trim((string) $this->request->getPost('cta_type')));
            $legacyText = trim((string) $this->request->getPost('cta_button_text'));
            if ($legacyType !== '' && $legacyText !== '') {
                $templateButtons[] = [
                    'type'         => $legacyType,
                    'text'         => $legacyText,
                    'url'          => trim((string) $this->request->getPost('cta_url')),
                    'url_example'  => trim((string) $this->request->getPost('cta_url_example')),
                    'phone_number' => trim((string) $this->request->getPost('cta_phone_number')),
                ];
            }
        }

        if ($name === '' || $body === '') {
            return $this->invalidTemplateInput('Name and body are required.');
        }

        if (! preg_match('/^[a-z0-9_]+$/', $name)) {
            return $this->invalidTemplateInput('Name must be lowercase letters, numbers, and underscores only.');
        }

        if (! preg_match('/^[a-z]{2}(?:_[A-Z]{2})?$/', $language)) {
            return $this->invalidTemplateInput('Language must be in `en` or `en_US` format.');
        }

        $bodyPlaceholders = $this->extractPlaceholders($body);
        if ($bodyPlaceholders !== []) {
            $exampleValues = array_values(array_map('trim', explode(',', $examples)));
            foreach ($bodyPlaceholders as $idx => $placeholder) {
                if (! isset($exampleValues[$idx]) || $exampleValues[$idx] === '') {
                    return $this->invalidTemplateInput('Please enter sample text for variable {{' . $placeholder . '}}.');
                }
            }

            $placementError = $this->validateBodyVariablePlacement($body);
            if ($placementError !== null) {
                return $this->invalidTemplateInput($placementError);
            }

            $ratioError = $this->validateBodyVariableRatio($body);
            if ($ratioError !== null) {
                return $this->invalidTemplateInput($ratioError);
            }
        }

        if (! in_array($category, ['UTILITY', 'MARKETING', 'AUTHENTICATION'], true)) {
            return $this->invalidTemplateInput('Invalid category.');
        }

        if (! in_array($templateType, ['default', 'carousel'], true)) {
            return $this->invalidTemplateInput('Invalid template type.');
        }

        if ($templateType === 'carousel') {
            if ($category !== 'MARKETING') {
                return $this->invalidTemplateInput('Carousel templates must use the Marketing category.');
            }

            $cardError = $this->validateCarouselCards($carouselCards);
            if ($cardError !== null) {
                return $this->invalidTemplateInput($cardError);
            }

            // Carousel templates use message body + card media; top-level header/footer/CTA are not used.
            $headerType = 'none';
            $header = '';
            $headerMediaSource = '';
            $headerMediaPreviewUrl = '';
            $footer = '';
            $templateButtons = [];
        } else {
            $carouselCards = [];

            if (! in_array($headerType, ['none', 'text', 'image', 'video', 'document'], true)) {
                return $this->invalidTemplateInput('Invalid header type.');
            }

            if ($headerType === 'text' && $header === '') {
                return $this->invalidTemplateInput('Header text is required when text header is selected.');
            }

            if (in_array($headerType, ['image', 'video', 'document'], true)) {
                if ($headerMediaSource === '') {
                    return $this->invalidTemplateInput('Upload a media file or provide a sample media URL for the header.');
                }
                $header = '';
            }

            if ($headerType === 'none') {
                $header = '';
                $headerMediaSource = '';
                $headerMediaPreviewUrl = '';
            }

            $buttonError = $this->validateTemplateButtons($templateButtons);
            if ($buttonError !== null) {
                return $this->invalidTemplateInput($buttonError);
            }
        }

        return [
            'error'         => null,
            'name'          => $name,
            'language'      => $language,
            'category'      => $category,
            'template_type' => $templateType,
            'header_type'   => $headerType,
            'header'        => $header,
            'header_media_source' => $headerMediaSource,
            'header_media_preview_url' => $headerMediaPreviewUrl,
            'body'          => $body,
            'footer'        => $footer,
            'body_examples' => $examples,
            'template_buttons' => $templateButtons,
            'carousel_cards' => $carouselCards,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function parseTemplateButtonsInput(mixed $raw): array
    {
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }
            $type = strtolower(trim((string) ($item['type'] ?? '')));
            $text = trim((string) ($item['text'] ?? ''));
            if ($type === '' && $text === '') {
                continue;
            }
            $out[] = [
                'type'         => $type,
                'text'         => $text,
                'url'          => trim((string) ($item['url'] ?? '')),
                'url_example'  => trim((string) ($item['url_example'] ?? '')),
                'phone_number' => trim((string) ($item['phone_number'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * WhatsApp Cloud API limits: max 10 buttons, 2 URL, 1 phone number.
     *
     * @param list<array<string, mixed>> $buttons
     */
    protected function validateTemplateButtons(array $buttons): ?string
    {
        if (count($buttons) > 10) {
            return 'You can add at most 10 buttons.';
        }

        $urlCount = 0;
        $phoneCount = 0;

        foreach ($buttons as $index => $button) {
            $n = $index + 1;
            $type = strtolower(trim((string) ($button['type'] ?? '')));
            $text = trim((string) ($button['text'] ?? ''));

            if (! in_array($type, ['quick_reply', 'url', 'phone_number'], true)) {
                return "Button {$n}: invalid button type.";
            }
            if ($text === '') {
                return "Button {$n}: button text is required.";
            }
            if (mb_strlen($text) > 25) {
                return "Button {$n}: button text must be 25 characters or fewer.";
            }

            if ($type === 'url') {
                $urlCount++;
                $url = trim((string) ($button['url'] ?? ''));
                $urlExample = trim((string) ($button['url_example'] ?? ''));
                if ($url === '' || ! filter_var(str_replace('{{1}}', 'sample', $url), FILTER_VALIDATE_URL)) {
                    return "Button {$n}: a valid CTA URL is required.";
                }
                if (preg_match('/\{\{\s*\d+\s*\}\}/', $url) && $urlExample === '') {
                    return "Button {$n}: CTA URL example is required when the URL has a placeholder.";
                }
            }

            if ($type === 'phone_number') {
                $phoneCount++;
                $phone = trim((string) ($button['phone_number'] ?? ''));
                if ($phone === '' || ! preg_match('/^\+?[0-9]{7,15}$/', $phone)) {
                    return "Button {$n}: a valid CTA phone number is required.";
                }
            }
        }

        if ($urlCount > 2) {
            return 'WhatsApp allows at most 2 Visit Website buttons.';
        }
        if ($phoneCount > 1) {
            return 'WhatsApp allows at most 1 Call Phone Number button.';
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function parseCarouselCardsInput(mixed $raw): array
    {
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($raw)) {
            return [];
        }

        $cards = [];
        foreach ($raw as $card) {
            if (! is_array($card)) {
                continue;
            }
            $cards[] = [
                'media_type' => strtolower(trim((string) ($card['media_type'] ?? 'image'))),
                'media_source' => trim((string) ($card['media_source'] ?? '')),
                'media_preview_url' => trim((string) ($card['media_preview_url'] ?? '')),
                'body' => trim((string) ($card['body'] ?? '')),
                'body_examples' => trim((string) ($card['body_examples'] ?? '')),
                'cta_type' => strtolower(trim((string) ($card['cta_type'] ?? ''))),
                'cta_button_text' => trim((string) ($card['cta_button_text'] ?? '')),
                'cta_url' => trim((string) ($card['cta_url'] ?? '')),
                'cta_url_example' => trim((string) ($card['cta_url_example'] ?? '')),
                'cta_phone_number' => trim((string) ($card['cta_phone_number'] ?? '')),
            ];
        }

        return $cards;
    }

    /**
     * @param list<array<string, mixed>> $cards
     */
    protected function validateCarouselCards(array $cards): ?string
    {
        $count = count($cards);
        if ($count < 2 || $count > 10) {
            return 'Carousel templates need between 2 and 10 cards.';
        }

        $hasBody = null;
        $hasButton = null;
        $mediaType = null;

        foreach ($cards as $index => $card) {
            $cardNo = $index + 1;
            $type = (string) ($card['media_type'] ?? '');
            if (! in_array($type, ['image', 'video'], true)) {
                return "Card {$cardNo}: media type must be image or video.";
            }
            if (trim((string) ($card['media_source'] ?? '')) === '') {
                return "Card {$cardNo}: upload media or provide a sample media URL.";
            }

            $cardHasBody = trim((string) ($card['body'] ?? '')) !== '';
            $ctaType = (string) ($card['cta_type'] ?? '');
            $cardHasButton = $ctaType !== '';

            if ($mediaType === null) {
                $mediaType = $type;
            } elseif ($mediaType !== $type) {
                return 'All carousel cards must use the same media type.';
            }

            if ($hasBody === null) {
                $hasBody = $cardHasBody;
            } elseif ($hasBody !== $cardHasBody) {
                return 'All carousel cards must either include body text or omit it.';
            }

            if ($hasButton === null) {
                $hasButton = $cardHasButton;
            } elseif ($hasButton !== $cardHasButton) {
                return 'All carousel cards must either include a CTA button or omit it.';
            }

            if ($cardHasButton) {
                if (! in_array($ctaType, ['url', 'phone_number'], true)) {
                    return "Card {$cardNo}: invalid CTA type.";
                }
                if (trim((string) ($card['cta_button_text'] ?? '')) === '') {
                    return "Card {$cardNo}: CTA button text is required.";
                }
            }
        }

        foreach ($cards as $index => $card) {
            $cardNo = $index + 1;
            $ctaType = (string) ($card['cta_type'] ?? '');
            if ($ctaType === '') {
                continue;
            }
            if ($ctaType === 'url') {
                $ctaUrl = (string) ($card['cta_url'] ?? '');
                if ($ctaUrl === '' || ! filter_var(str_replace('{{1}}', 'sample', $ctaUrl), FILTER_VALIDATE_URL)) {
                    return "Card {$cardNo}: a valid CTA URL is required.";
                }
                if (preg_match('/\{\{\s*\d+\s*\}\}/', $ctaUrl) && trim((string) ($card['cta_url_example'] ?? '')) === '') {
                    return "Card {$cardNo}: CTA URL example is required when the URL has a placeholder.";
                }
            }
            if ($ctaType === 'phone_number') {
                $phone = (string) ($card['cta_phone_number'] ?? '');
                if ($phone === '' || ! preg_match('/^\+?[0-9]{7,15}$/', $phone)) {
                    return "Card {$cardNo}: a valid CTA phone number is required.";
                }
            }
        }

        return null;
    }

    /**
     * @return array{
     *     error: string,
     *     name: string,
     *     language: string,
     *     category: string,
     *     template_type: string,
     *     header_type: string,
     *     header: string,
     *     header_media_source: string,
     *     header_media_preview_url: string,
     *     body: string,
     *     footer: string,
     *     body_examples: string,
     *     template_buttons: list<array<string, mixed>>,
     *     carousel_cards: list<array<string, mixed>>
     * }
     */
    protected function invalidTemplateInput(string $message): array
    {
        return [
            'error'         => $message,
            'name'          => '',
            'language'      => '',
            'category'      => '',
            'template_type' => 'default',
            'header_type'   => 'text',
            'header'        => '',
            'header_media_source' => '',
            'header_media_preview_url' => '',
            'body'          => '',
            'footer'        => '',
            'body_examples' => '',
            'template_buttons' => [],
            'carousel_cards' => [],
        ];
    }

    protected function templateCreateErrorResponse(string $message): ResponseInterface
    {
        if ($this->request->isAJAX()) {
            return $this->jsonResponse(false, null, $message, [], 422);
        }

        return redirect()->back()->withInput()->with('error', $message);
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
     * WhatsApp rejects bodies where variables are too dense vs fixed words
     * (error_subcode 2388293 / "Parameters words ratio exceeds limit").
     *
     * Meta counts whitespace-separated tokens and requires
     * words + parameters >= (3 x parameters) + 1. A variable glued to a word
     * (`order{{1}}`) counts as one token, so it does not help the ratio.
     */
    protected function validateBodyVariableRatio(string $body): ?string
    {
        $varCount = count($this->extractPlaceholders($body));
        if ($varCount === 0) {
            return null;
        }

        $tokenCount = $this->countBodyTokens($body);
        $required   = ($varCount * 3) + 1;

        if ($tokenCount < $required) {
            return sprintf(
                'This template has too many variables for its length. WhatsApp needs at least %d words for %d variable%s, but this body has %d. Add more text or use fewer variables.',
                $required,
                $varCount,
                $varCount === 1 ? '' : 's',
                $tokenCount
            );
        }

        return null;
    }

    protected function countBodyTokens(string $body): int
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $body) ?? $body);
        if ($normalized === '') {
            return 0;
        }

        $tokens = preg_split('/\s+/', $normalized, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($tokens) ? count($tokens) : 0;
    }

    /**
     * Meta rejects templates that open or close with a variable
     * (error_subcode 2388299 / "Leading or trailing parameters not allowed").
     */
    protected function validateBodyVariablePlacement(string $body): ?string
    {
        $trimmed = trim($body);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^\{\{\s*\d+\s*\}\}/', $trimmed)) {
            return 'The message body cannot start with a variable. Add some text before it.';
        }

        if (preg_match('/\{\{\s*\d+\s*\}\}$/', $trimmed)) {
            return 'The message body cannot end with a variable. Add some text after it.';
        }

        return null;
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
                    // Store placeholder identities, never provider example values.
                    $result['variables'] = \App\Libraries\WhatsAppTemplateVariables::identitiesFromComponents([$component]);
                    break;
                case 'FOOTER':
                    $result['footer'] = $component['text'] ?? null;
                    break;
                case 'BUTTONS':
                    $result['buttons'] = $component['buttons'] ?? null;
                    break;
                case 'CAROUSEL':
                    $cards = is_array($component['cards'] ?? null) ? $component['cards'] : [];
                    $result['buttons'] = ['carousel_cards' => count($cards)];
                    if ($result['header_type'] === null && isset($cards[0]['components']) && is_array($cards[0]['components'])) {
                        foreach ($cards[0]['components'] as $cardComponent) {
                            if (! is_array($cardComponent)) {
                                continue;
                            }
                            if (strtoupper((string) ($cardComponent['type'] ?? '')) !== 'HEADER') {
                                continue;
                            }
                            $result['header_type'] = strtolower((string) ($cardComponent['format'] ?? 'image'));
                            $result['header_content'] = $cardComponent['example']['header_url']
                                ?? $cardComponent['example']['link']
                                ?? ($cardComponent['example']['header_handle'][0] ?? null);
                            break;
                        }
                    }
                    break;
            }
        }

        if ($result['variables'] === []) {
            $result['variables'] = null;
        }

        return $result;
    }

    /**
     * @param array<string, mixed>       $tpl
     * @param list<array<string, mixed>> $components
     */
    protected function detectTemplateTypeFromComponents(array $tpl, array $components): string
    {
        $explicit = strtolower(trim((string) ($tpl['template_type'] ?? '')));
        if (in_array($explicit, ['default', 'carousel'], true)) {
            return $explicit;
        }

        foreach ($components as $component) {
            if (! is_array($component)) {
                continue;
            }
            if (strtoupper((string) ($component['type'] ?? '')) === 'CAROUSEL') {
                return 'carousel';
            }
        }

        return 'default';
    }

    /**
     * Cheerio rejects messaging media IDs as template header_handle samples.
     * Prefer a public HTTPS preview URL when available.
     */
    protected function resolveTemplateMediaHandle(string $source, string $previewUrl = ''): string
    {
        $source = trim($source);
        $previewUrl = trim($previewUrl);

        $isPublicHttp = static function (string $url): bool {
            if (! preg_match('#^https?://#i', $url)) {
                return false;
            }

            return ! preg_match('#^https?://(localhost|127\.0\.0\.1)(:|/|$)#i', $url);
        };

        if ($previewUrl !== '' && $isPublicHttp($previewUrl)) {
            return $previewUrl;
        }

        if ($source !== '' && $isPublicHttp($source)) {
            return $source;
        }

        return $source !== '' ? $source : $previewUrl;
    }
}
