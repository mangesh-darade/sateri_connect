<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\ActivityLogger;
use App\Libraries\SettingsService;
use App\Models\CampaignContactModel;
use App\Models\CampaignModel;
use App\Models\ContactModel;
use App\Models\EmailBuilderModel;
use App\Models\EmailHtmlCampaignModel;
use App\Models\MessageQueueModel;
use App\Models\TagModel;
use App\Models\TemplateModel;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Campaign lifecycle controllers — delegates to CampaignService.
 */
class Campaigns extends BaseController
{
    public function index(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('campaigns.view')) {
            return $denied;
        }

        $status  = strtolower(trim((string) ($this->request->getGet('status') ?? '')));
        $channel = strtolower(trim((string) ($this->request->getGet('channel') ?? '')));
        $search  = trim((string) ($this->request->getGet('q') ?? ''));
        $sort    = strtolower(trim((string) ($this->request->getGet('sort') ?? 'latest')));

        $campaigns = $this->buildMergedCampaignList($status, $channel, $search, $sort);

        $settings = new SettingsService();

        return $this->render('campaigns/index', [
            'pageTitle'       => 'Recent Campaigns',
            'campaigns'       => $campaigns,
            'filterStatus'    => $status,
            'filterChannel'   => $channel,
            'filterSearch'    => $search,
            'filterSort'      => $sort,
            'canCreateWa'     => function_exists('can') && can('campaigns.create'),
            'canCreateEmail'  => function_exists('can') && can('emails.send'),
            'openChannel'     => strtolower(trim((string) ($this->request->getGet('new') ?? ''))),
            'isCheerioEmail'  => $settings->getEmailProvider() === SettingsService::EMAIL_PROVIDER_CHEERIO,
        ]);
    }

    public function create(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('campaigns.create')) {
            return $denied;
        }

        $channel = strtolower(trim((string) ($this->request->getGet('channel') ?? 'whatsapp')));
        if (! in_array($channel, ['whatsapp', 'email'], true)) {
            $channel = 'whatsapp';
        }

        return redirect()->to('/campaigns?new=' . $channel);
    }

    public function store(): ResponseInterface
    {
        if ($denied = $this->requirePermission('campaigns.create')) {
            return $denied;
        }

        $rules = [
            'name'         => 'required|max_length[191]',
            'message_type' => 'permit_empty|in_list[template,text,image,document]',
            'template_id'  => 'permit_empty|is_natural_no_zero',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        try {
            $payload   = $this->parsePayloadFromRequest();
            $variables = $this->parseVariablesFromRequest();
            $audience  = $this->parseAudienceFromRequest();
            $payload['_audience'] = $audience;

            $id = service('campaignService')->create([
                'name'         => $this->request->getPost('name'),
                'template_id'  => $this->request->getPost('template_id') ?: null,
                'message_type' => $this->request->getPost('message_type') ?: 'template',
                'payload'      => $payload,
                'variables'    => $variables,
                'created_by'   => $this->userId(),
            ]);

            return $this->applyFormAction($id, $audience);
        } catch (Throwable $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function show(int $id): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('campaigns.view')) {
            return $denied;
        }

        $campaign = model(CampaignModel::class)->find($id);
        if ($campaign === null) {
            return redirect()->to('/campaigns')->with('error', 'Campaign not found.');
        }

        model(CampaignModel::class)->updateStats($id);
        $campaign = model(CampaignModel::class)->find($id);

        $recipients = model(CampaignContactModel::class)
            ->select('campaign_contacts.*, contacts.name, contacts.mobile')
            ->join('contacts', 'contacts.id = campaign_contacts.contact_id', 'left')
            ->where('campaign_id', $id)
            ->orderBy('campaign_contacts.id', 'DESC')
            ->findAll(100);

        $template = null;
        if (! empty($campaign['template_id'])) {
            $template = model(TemplateModel::class)->find((int) $campaign['template_id']);
        }

        return $this->render('campaigns/show', [
            'pageTitle'  => 'Campaign: ' . $campaign['name'],
            'campaign'   => $campaign,
            'recipients' => $recipients,
            'template'   => $template,
            'tags'       => model(TagModel::class)->orderBy('name', 'ASC')->findAll(),
        ]);
    }

    public function edit(int $id): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('campaigns.edit')) {
            return $denied;
        }

        $campaign = model(CampaignModel::class)->find($id);
        if ($campaign === null) {
            return redirect()->to('/campaigns')->with('error', 'Campaign not found.');
        }

        if (! in_array($campaign['status'], ['draft', 'paused', 'scheduled'], true)) {
            return redirect()->to('/campaigns/' . $id)->with('error', 'Only draft, paused, or scheduled campaigns can be edited.');
        }

        $campaign = $this->hydrateAudienceFields($campaign);

        return $this->render('campaigns/form', [
            'pageTitle' => 'Edit Campaign',
            'campaign'  => $campaign,
            'templates' => model(TemplateModel::class)->getApproved(),
            'tags'      => model(TagModel::class)->orderBy('name', 'ASC')->findAll(),
            'contacts'  => model(ContactModel::class)->where('status', 'active')->orderBy('name', 'ASC')->findAll(2000),
        ]);
    }

    public function update(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('campaigns.edit')) {
            return $denied;
        }

        $model    = model(CampaignModel::class);
        $campaign = $model->find($id);

        if ($campaign === null) {
            return redirect()->to('/campaigns')->with('error', 'Campaign not found.');
        }

        if (! in_array($campaign['status'], ['draft', 'paused', 'scheduled'], true)) {
            return redirect()->to('/campaigns/' . $id)->with('error', 'Campaign cannot be edited in its current status.');
        }

        $rules = [
            'name'         => 'required|max_length[191]',
            'message_type' => 'permit_empty|in_list[template,text,image,document]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        try {
            $payload   = $this->parsePayloadFromRequest();
            if ($payload === null || $payload === []) {
                $payload = is_array($campaign['payload'] ?? null) ? $campaign['payload'] : [];
            }
            $variables = $this->parseVariablesFromRequest();
            $audience  = $this->parseAudienceFromRequest();
            $payload['_audience'] = $audience;

            $model->update($id, [
                'name'         => $this->request->getPost('name'),
                'template_id'  => $this->request->getPost('template_id') ?: null,
                'message_type' => $this->request->getPost('message_type') ?: $campaign['message_type'],
                'payload'      => $payload,
                'variables'    => $variables,
            ]);

            (new ActivityLogger())->log('update', 'campaigns', 'Campaign updated', ['campaign_id' => $id]);

            return $this->applyFormAction($id, $audience);
        } catch (Throwable $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function preview(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('campaigns.view')) {
            return $denied;
        }

        $audience   = $this->parseAudienceFromRequest();
        $contactIds = $audience['contact_ids'] !== [] ? $audience['contact_ids'] : null;
        $tagIds     = $audience['tag_ids'] !== [] ? $audience['tag_ids'] : null;

        try {
            $preview = service('campaignService')->preview(
                $id,
                $contactIds,
                $tagIds,
                $audience['all']
            );

            return $this->jsonResponse(true, $preview);
        } catch (Throwable $e) {
            return $this->jsonResponse(false, null, $e->getMessage(), [], 400);
        }
    }

    public function schedule(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('campaigns.start')) {
            return $denied;
        }

        $scheduledAt = (string) ($this->request->getPost('scheduled_at')
            ?? $this->request->getJSON(true)['scheduled_at']
            ?? '');

        if ($scheduledAt === '' || strtotime($scheduledAt) === false) {
            return $this->failOrRedirect('A valid scheduled_at datetime is required.');
        }

        try {
            $audience = $this->parseAudienceFromRequest();
            service('campaignService')->saveAudience($id, $audience);
            service('campaignService')->schedule($id, $scheduledAt);

            return $this->okOrRedirect('/campaigns/' . $id, 'Campaign scheduled.');
        } catch (Throwable $e) {
            return $this->failOrRedirect($e->getMessage());
        }
    }

    public function sendNow(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('campaigns.start')) {
            return $denied;
        }

        try {
            $audience   = $this->parseAudienceFromRequest();
            service('campaignService')->saveAudience($id, $audience);
            $contactIds = $audience['contact_ids'] !== [] ? $audience['contact_ids'] : null;
            $tagIds     = $audience['tag_ids'] !== [] ? $audience['tag_ids'] : null;
            $result     = service('campaignService')->start($id, $contactIds, $tagIds, $audience['all']);

            return $this->okOrRedirect('/campaigns/' . $id, 'Campaign started. Queued ' . $result['queued'] . ' messages.');
        } catch (Throwable $e) {
            return $this->failOrRedirect($e->getMessage());
        }
    }

    public function pause(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('campaigns.start')) {
            return $denied;
        }

        try {
            service('campaignService')->pause($id);

            return $this->okOrRedirect('/campaigns/' . $id, 'Campaign paused.');
        } catch (Throwable $e) {
            return $this->failOrRedirect($e->getMessage());
        }
    }

    public function resume(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('campaigns.start')) {
            return $denied;
        }

        try {
            service('campaignService')->resume($id);

            return $this->okOrRedirect('/campaigns/' . $id, 'Campaign resumed.');
        } catch (Throwable $e) {
            return $this->failOrRedirect($e->getMessage());
        }
    }

    public function cancel(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('campaigns.start')) {
            return $denied;
        }

        try {
            service('campaignService')->cancel($id);

            return $this->okOrRedirect('/campaigns/' . $id, 'Campaign cancelled.');
        } catch (Throwable $e) {
            return $this->failOrRedirect($e->getMessage());
        }
    }

    public function analytics(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('campaigns.view')) {
            return $denied;
        }

        $campaign = model(CampaignModel::class)->find($id);
        if ($campaign === null) {
            return $this->jsonResponse(false, null, 'Campaign not found.', [], 404);
        }

        model(CampaignModel::class)->updateStats($id);
        $campaign = model(CampaignModel::class)->find($id);

        $byStatus = db_connect()->table('campaign_contacts')
            ->select('status, COUNT(*) AS total')
            ->where('campaign_id', $id)
            ->groupBy('status')
            ->get()
            ->getResultArray();

        $timeline = db_connect()->table('messages')
            ->select("DATE(created_at) AS day, COUNT(*) AS total")
            ->where('campaign_id', $id)
            ->groupBy('day')
            ->orderBy('day', 'ASC')
            ->get()
            ->getResultArray();

        return $this->jsonResponse(true, [
            'campaign'  => $campaign,
            'by_status' => $byStatus,
            'timeline'  => $timeline,
        ]);
    }

    public function queueStatus(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('campaigns.view')) {
            return $denied;
        }

        $rows = model(MessageQueueModel::class)
            ->select('status, COUNT(*) AS total')
            ->where('campaign_id', $id)
            ->groupBy('status')
            ->findAll();

        $stats = [
            'pending'    => 0,
            'processing' => 0,
            'sent'       => 0,
            'failed'     => 0,
            'cancelled'  => 0,
        ];

        foreach ($rows as $row) {
            $stats[(string) $row['status']] = (int) $row['total'];
        }

        return $this->jsonResponse(true, $stats);
    }

    public function delete(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('campaigns.delete')) {
            return $denied;
        }

        $campaign = model(CampaignModel::class)->find($id);
        if ($campaign === null) {
            return $this->failOrRedirect('Campaign not found.');
        }

        $status = (string) ($campaign['status'] ?? '');
        if (in_array($status, ['running', 'scheduled'], true)) {
            return $this->failOrRedirect('Pause or cancel the campaign before deleting.');
        }

        db_connect()->table('message_queue')->where('campaign_id', $id)->delete();
        db_connect()->table('campaign_contacts')->where('campaign_id', $id)->delete();
        model(CampaignModel::class)->delete($id);

        (new ActivityLogger())->log('delete', 'campaigns', 'Campaign deleted', ['campaign_id' => $id]);

        return $this->okOrRedirect('/campaigns', 'Campaign deleted.');
    }

    public function wizardData(): ResponseInterface
    {
        if ($denied = $this->requirePermission('campaigns.view')) {
            return $denied;
        }

        $templates = model(TemplateModel::class)->getApproved();
        $templateCards = [];
        foreach ($templates as $tpl) {
            $headerType = strtolower((string) ($tpl['header_type'] ?? 'none'));
            $needsMedia = in_array($headerType, ['image', 'video', 'document'], true);
            $templateCards[] = [
                'id'          => (int) $tpl['id'],
                'name'        => (string) ($tpl['name'] ?? ''),
                'language'    => (string) ($tpl['language'] ?? 'en_US'),
                'category'    => strtoupper((string) ($tpl['category'] ?? '')),
                'status'      => strtoupper((string) ($tpl['status'] ?? '')),
                'body'        => (string) ($tpl['body'] ?? ''),
                'footer'      => (string) ($tpl['footer'] ?? ''),
                'header_type' => $headerType !== '' ? $headerType : 'none',
                'header'      => (string) ($tpl['header_content'] ?? $tpl['header'] ?? ''),
                'needs_media' => $needsMedia,
                'variables'   => $this->normalizeTemplateVariables($tpl['variables'] ?? null, (string) ($tpl['body'] ?? '')),
            ];
        }

        $builders = [];
        if (function_exists('can') && can('emails.send')) {
            foreach (model(EmailBuilderModel::class)->orderBy('name', 'ASC')->findAll(200) as $b) {
                $builders[] = [
                    'id'                 => (int) $b['id'],
                    'name'               => (string) ($b['name'] ?? ''),
                    'subject'            => (string) ($b['subject'] ?? ''),
                    'html_content'       => (string) ($b['html_content'] ?? ''),
                    'cheerio_builder_id' => (string) ($b['cheerio_builder_id'] ?? ''),
                ];
            }
        }

        $labels = [];
        foreach (model(TagModel::class)->listWithContactCounts() as $tag) {
            $labels[] = [
                'id'            => (int) $tag['id'],
                'name'          => (string) ($tag['name'] ?? ''),
                'color'         => (string) ($tag['color'] ?? '#6B7280'),
                'contact_count' => (int) ($tag['contact_count'] ?? 0),
            ];
        }

        $settings = new SettingsService();

        return $this->jsonResponse(true, [
            'labels'           => $labels,
            'templates'        => $templateCards,
            'email_builders'   => $builders,
            'attribute_fields' => [
                ['value' => 'name', 'label' => 'Name'],
                ['value' => 'mobile', 'label' => 'Phone'],
                ['value' => 'email', 'label' => 'Email'],
                ['value' => 'status', 'label' => 'Status'],
            ],
            'conditions' => [
                ['value' => 'equals', 'label' => 'Equals'],
                ['value' => 'contains', 'label' => 'Contains'],
                ['value' => 'starts_with', 'label' => 'Starts with'],
                ['value' => 'not_equals', 'label' => 'Not equals'],
            ],
            'can_create_wa'    => function_exists('can') && can('campaigns.create'),
            'can_create_email' => function_exists('can') && can('emails.send'),
            'can_start_wa'     => function_exists('can') && can('campaigns.start'),
            'is_cheerio_email' => $settings->getEmailProvider() === SettingsService::EMAIL_PROVIDER_CHEERIO,
            'contacts_import_url' => site_url('contacts'),
        ]);
    }

    public function audiencePreview(): ResponseInterface
    {
        $canView = function_exists('can') && (can('campaigns.view') || can('emails.send'));
        if (! $canView) {
            return $this->jsonResponse(false, null, 'Permission denied.', [], 403);
        }

        $input      = $this->requestInput();
        $tagId      = (int) ($input['tag_id'] ?? $input['label_id'] ?? 0);
        $attributes = $this->parseAttributesFromInput($input);
        $tagIds     = $tagId > 0 ? [$tagId] : [];

        if ($tagIds === []) {
            return $this->jsonResponse(false, null, 'Select a label first.', [], 422);
        }

        try {
            $preview = service('campaignService')->previewAudience($tagIds, [], $attributes, false);

            return $this->jsonResponse(true, [
                'total'        => $preview['total'],
                'phone_count'  => $preview['phone_count'],
                'email_count'  => $preview['email_count'],
                'contact_ids'  => $preview['contact_ids'],
                'sample'       => $preview['sample'],
                'attributes'   => $attributes,
            ]);
        } catch (Throwable $e) {
            return $this->jsonResponse(false, null, $e->getMessage(), [], 400);
        }
    }

    public function createLabel(): ResponseInterface
    {
        $canWa = function_exists('can') && can('campaigns.create');
        $canEmail = function_exists('can') && can('emails.send');
        if (! $canWa && ! $canEmail) {
            return $this->jsonResponse(false, null, 'Permission denied.', [], 403);
        }

        $input = $this->requestInput();
        $name  = trim((string) ($input['name'] ?? ''));
        $color = trim((string) ($input['color'] ?? '#6B7280'));

        if ($name === '' || mb_strlen($name) > 100) {
            return $this->jsonResponse(false, null, 'Label name is required (max 100 characters).', [], 422);
        }

        $tagModel = model(TagModel::class);
        $existing = $tagModel->where('name', $name)->first();
        if (is_array($existing)) {
            return $this->jsonResponse(true, [
                'id'            => (int) $existing['id'],
                'name'          => (string) $existing['name'],
                'color'         => (string) ($existing['color'] ?? '#6B7280'),
                'contact_count' => $tagModel->contactCount((int) $existing['id']),
                'created'       => false,
            ], 'Label already exists.');
        }

        $id = (int) $tagModel->insert([
            'name'  => $name,
            'color' => $color !== '' ? $color : '#6B7280',
        ]);

        if ($id <= 0) {
            $errors = $tagModel->errors();

            return $this->jsonResponse(false, null, (string) (reset($errors) ?: 'Unable to create label.'), $errors ?: [], 422);
        }

        return $this->jsonResponse(true, [
            'id'            => $id,
            'name'          => $name,
            'color'         => $color !== '' ? $color : '#6B7280',
            'contact_count' => 0,
            'created'       => true,
        ], 'Label created.');
    }

    public function wizardStore(): ResponseInterface
    {
        $input   = $this->requestInput();
        $channel = strtolower(trim((string) ($input['channel'] ?? 'whatsapp')));

        if ($channel === 'email') {
            return $this->wizardStoreEmail($input);
        }

        return $this->wizardStoreWhatsApp($input);
    }

    public function wizardRun(string $channel, int $id): ResponseInterface
    {
        $channel = strtolower($channel);
        if ($channel === 'email') {
            return $this->wizardRunEmail($id);
        }

        return $this->wizardRunWhatsApp($id);
    }

    public function wizardSchedule(string $channel, int $id): ResponseInterface
    {
        $channel = strtolower($channel);
        $input   = $this->requestInput();
        $scheduledAt = trim((string) ($input['scheduled_at'] ?? ''));
        if ($scheduledAt === '' || strtotime($scheduledAt) === false) {
            return $this->jsonResponse(false, null, 'A valid scheduled_at datetime is required.', [], 422);
        }
        $ts = date('Y-m-d H:i:s', (int) strtotime($scheduledAt));

        if ($channel === 'email') {
            return $this->wizardScheduleEmail($id, $ts);
        }

        return $this->wizardScheduleWhatsApp($id, $ts, $input);
    }

    /**
     * @param array<string, mixed> $input
     */
    protected function wizardStoreWhatsApp(array $input): ResponseInterface
    {
        if ($denied = $this->requirePermission('campaigns.create')) {
            return $denied;
        }

        $name       = trim((string) ($input['name'] ?? ''));
        $templateId = (int) ($input['template_id'] ?? 0);
        $tagId      = (int) ($input['tag_id'] ?? $input['label_id'] ?? 0);
        $attributes = $this->parseAttributesFromInput($input);
        $variables  = is_array($input['variables'] ?? null) ? $input['variables'] : [];
        $mediaUrl   = trim((string) ($input['header_media_url'] ?? $input['media_url'] ?? ''));

        if ($name === '' || mb_strlen($name) > 30) {
            return $this->jsonResponse(false, null, 'Campaign name is required (max 30 characters).', [], 422);
        }
        if ($templateId <= 0) {
            return $this->jsonResponse(false, null, 'Select a WhatsApp template.', [], 422);
        }
        if ($tagId <= 0) {
            return $this->jsonResponse(false, null, 'Select a label.', [], 422);
        }

        $template = model(TemplateModel::class)->find($templateId);
        if ($template === null) {
            return $this->jsonResponse(false, null, 'Template not found.', [], 404);
        }

        $preview = service('campaignService')->previewAudience([$tagId], [], $attributes, false);
        if ($preview['total'] === 0) {
            return $this->jsonResponse(false, null, 'No contacts matched this label/filters.', [], 422);
        }

        $label = model(TagModel::class)->find($tagId);
        $payload = [
            'template_name' => (string) ($template['name'] ?? ''),
            'language'      => (string) ($template['language'] ?? 'en_US'),
            'name'          => (string) ($template['name'] ?? ''),
            '_audience'     => [
                'all'         => false,
                'contact_ids' => $preview['contact_ids'],
                'tag_ids'     => [$tagId],
                'label_id'    => $tagId,
                'label_name'  => (string) ($label['name'] ?? ''),
                'attributes'  => $attributes,
            ],
        ];

        $headerType = strtolower(trim((string) ($template['header_type'] ?? '')));
        // Only IMAGE/VIDEO/DOCUMENT headers accept a media link at send time.
        // TEXT/NONE templates must not get a header component (breaks Cheerio/Meta).
        if ($mediaUrl !== '' && in_array($headerType, ['image', 'video', 'document'], true)) {
            $payload['header_media_url'] = $mediaUrl;
            $payload['components']      = [[
                'type'       => 'header',
                'parameters' => [[
                    'type'     => $headerType,
                    $headerType => ['link' => $mediaUrl],
                ]],
            ]];
        }

        try {
            $id = service('campaignService')->create([
                'name'         => $name,
                'template_id'  => $templateId,
                'message_type' => 'template',
                'payload'      => $payload,
                'variables'    => $variables !== [] ? $variables : null,
                'created_by'   => $this->userId(),
            ]);

            service('campaignService')->saveAudience($id, [
                'all'         => false,
                'contact_ids' => $preview['contact_ids'],
                'tag_ids'     => [$tagId],
            ]);

            return $this->jsonResponse(true, [
                'id'           => $id,
                'channel'      => 'whatsapp',
                'name'         => $name,
                'status'       => 'draft',
                'total'        => $preview['total'],
                'phone_count'  => $preview['phone_count'],
                'email_count'  => $preview['email_count'],
                'label_name'   => (string) ($label['name'] ?? ''),
                'template'     => [
                    'id'   => $templateId,
                    'name' => (string) ($template['name'] ?? ''),
                    'body' => (string) ($template['body'] ?? ''),
                ],
                'redirect'     => site_url('campaigns/' . $id),
            ], 'WhatsApp campaign draft saved.');
        } catch (Throwable $e) {
            return $this->jsonResponse(false, null, $e->getMessage(), [], 400);
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    protected function wizardStoreEmail(array $input): ResponseInterface
    {
        if ($denied = $this->requirePermission('emails.send')) {
            return $denied;
        }

        $name       = trim((string) ($input['name'] ?? ''));
        $subject    = trim((string) ($input['subject'] ?? ''));
        $html       = (string) ($input['html_content'] ?? '');
        $builderId  = (int) ($input['builder_id'] ?? 0);
        $tagId      = (int) ($input['tag_id'] ?? $input['label_id'] ?? 0);
        $attributes = $this->parseAttributesFromInput($input);
        $cheerioBuilderId = trim((string) ($input['cheerio_builder_id'] ?? ''));

        if ($name === '' || mb_strlen($name) > 30) {
            return $this->jsonResponse(false, null, 'Campaign name is required (max 30 characters).', [], 422);
        }
        if ($tagId <= 0) {
            return $this->jsonResponse(false, null, 'Select a label.', [], 422);
        }

        if ($builderId > 0) {
            $builder = model(EmailBuilderModel::class)->find($builderId);
            if ($builder) {
                if ($subject === '' && ! empty($builder['subject'])) {
                    $subject = (string) $builder['subject'];
                }
                if ($html === '' && ! empty($builder['html_content'])) {
                    $html = (string) $builder['html_content'];
                }
                if ($cheerioBuilderId === '' && ! empty($builder['cheerio_builder_id'])) {
                    $cheerioBuilderId = (string) $builder['cheerio_builder_id'];
                }
            }
        }

        if ($subject === '') {
            return $this->jsonResponse(false, null, 'Email subject is required.', [], 422);
        }

        $preview = service('campaignService')->previewAudience([$tagId], [], $attributes, false);
        $emails  = [];
        foreach ($preview['contacts'] as $contact) {
            $email = strtolower(trim((string) ($contact['email'] ?? '')));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $email;
            }
        }
        $emails = array_values(array_unique($emails));

        if ($emails === []) {
            return $this->jsonResponse(false, null, 'No contacts with email matched this label/filters.', [], 422);
        }

        $label = model(TagModel::class)->find($tagId);
        $settings = new SettingsService();
        $isCheerio = $settings->getEmailProvider() === SettingsService::EMAIL_PROVIDER_CHEERIO;

        // Prefer recipients list from filtered contacts so SMTP/SendGrid and Cheerio both work.
        $row = [
            'name'               => $name,
            'subject'            => $subject,
            'html_content'       => $html,
            'builder_id'         => $builderId > 0 ? $builderId : null,
            'cheerio_builder_id' => $cheerioBuilderId !== '' ? $cheerioBuilderId : null,
            'mode'               => 'recipients',
            'label_name'         => (string) ($label['name'] ?? ''),
            'recipients_json'    => $emails,
            'status'             => 'draft',
            'created_by'         => $this->userId(),
        ];

        // Optional Cheerio label mode when user asks and provider supports it.
        if ($isCheerio && ! empty($input['use_cheerio_label'])) {
            $row['mode'] = 'label';
            $row['recipients_json'] = [];
        }

        try {
            $id = (int) model(EmailHtmlCampaignModel::class)->insert($row, true);
            if ($id <= 0) {
                return $this->jsonResponse(false, null, 'Failed to save email campaign.', [], 500);
            }

            return $this->jsonResponse(true, [
                'id'          => $id,
                'channel'     => 'email',
                'name'        => $name,
                'status'      => 'draft',
                'total'       => count($emails),
                'email_count' => count($emails),
                'phone_count' => $preview['phone_count'],
                'label_name'  => (string) ($label['name'] ?? ''),
                'subject'     => $subject,
                'redirect'    => site_url('email-manager?tab=campaigns'),
            ], 'Email campaign draft saved.');
        } catch (Throwable $e) {
            return $this->jsonResponse(false, null, $e->getMessage(), [], 400);
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    protected function wizardScheduleWhatsApp(int $id, string $scheduledAt, array $input): ResponseInterface
    {
        if ($denied = $this->requirePermission('campaigns.start')) {
            return $denied;
        }

        try {
            $campaign = model(CampaignModel::class)->find($id);
            if ($campaign === null) {
                return $this->jsonResponse(false, null, 'Campaign not found.', [], 404);
            }

            $audience = $this->audienceFromCampaignPayload($campaign);
            if ($audience['contact_ids'] === [] && $audience['tag_ids'] === []) {
                return $this->jsonResponse(false, null, 'Campaign has no audience.', [], 422);
            }

            service('campaignService')->saveAudience($id, $audience);
            service('campaignService')->schedule($id, $scheduledAt);

            return $this->jsonResponse(true, [
                'id'           => $id,
                'channel'      => 'whatsapp',
                'status'       => 'scheduled',
                'scheduled_at' => $scheduledAt,
                'redirect'     => site_url('campaigns/' . $id),
            ], 'Campaign scheduled.');
        } catch (Throwable $e) {
            return $this->jsonResponse(false, null, $e->getMessage(), [], 400);
        }
    }

    protected function wizardScheduleEmail(int $id, string $scheduledAt): ResponseInterface
    {
        if ($denied = $this->requirePermission('emails.send')) {
            return $denied;
        }

        $model = model(EmailHtmlCampaignModel::class);
        $camp  = $model->find($id);
        if ($camp === null) {
            return $this->jsonResponse(false, null, 'Email campaign not found.', [], 404);
        }

        if (! in_array((string) ($camp['status'] ?? ''), ['draft', 'queued', 'failed'], true)) {
            return $this->jsonResponse(false, null, 'Only draft email campaigns can be scheduled.', [], 422);
        }

        $this->ensureEmailScheduledAtColumn();

        $model->update($id, [
            'status'       => 'queued',
            'scheduled_at' => $scheduledAt,
            'last_error'   => null,
        ]);

        return $this->jsonResponse(true, [
            'id'           => $id,
            'channel'      => 'email',
            'status'       => 'queued',
            'scheduled_at' => $scheduledAt,
            'redirect'     => site_url('campaigns'),
        ], 'Email campaign scheduled.');
    }

    protected function wizardRunWhatsApp(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('campaigns.start')) {
            return $denied;
        }

        try {
            $campaign = model(CampaignModel::class)->find($id);
            if ($campaign === null) {
                return $this->jsonResponse(false, null, 'Campaign not found.', [], 404);
            }

            $audience   = $this->audienceFromCampaignPayload($campaign);
            $contactIds = $audience['contact_ids'] !== [] ? $audience['contact_ids'] : null;
            $tagIds     = $audience['tag_ids'] !== [] ? $audience['tag_ids'] : null;
            service('campaignService')->saveAudience($id, $audience);
            $result = service('campaignService')->start($id, $contactIds, $tagIds, false);

            return $this->jsonResponse(true, [
                'id'       => $id,
                'channel'  => 'whatsapp',
                'status'   => 'running',
                'queued'   => $result['queued'] ?? 0,
                'contacts' => $result['contacts'] ?? 0,
                'redirect' => site_url('campaigns/' . $id),
            ], 'Campaign started. Queued ' . ($result['queued'] ?? 0) . ' messages.');
        } catch (Throwable $e) {
            return $this->jsonResponse(false, null, $e->getMessage(), [], 400);
        }
    }

    protected function wizardRunEmail(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('emails.send')) {
            return $denied;
        }

        $model = model(EmailHtmlCampaignModel::class);
        $camp  = $model->find($id);
        if ($camp === null) {
            return $this->jsonResponse(false, null, 'Email campaign not found.', [], 404);
        }

        try {
            $result = (new \App\Libraries\EmailCampaignService())->dispatch($camp, $this->userId());
            $ok     = (bool) ($result['ok'] ?? false);

            return $this->jsonResponse($ok, [
                'id'       => $id,
                'channel'  => 'email',
                'status'   => $ok ? 'sent' : 'failed',
                'sent'     => (int) ($result['sent'] ?? 0),
                'redirect' => site_url('campaigns'),
            ], $ok ? 'Email campaign sent.' : (string) ($result['message'] ?? 'Send failed.'), [], $ok ? 200 : 400);
        } catch (Throwable $e) {
            $model->update($id, [
                'status'     => 'failed',
                'last_error' => $e->getMessage(),
            ]);

            return $this->jsonResponse(false, null, $e->getMessage(), [], 400);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function buildMergedCampaignList(string $status, string $channel, string $search, string $sort): array
    {
        $rows = [];

        if ($channel === '' || $channel === 'whatsapp' || $channel === 'all') {
            $wa = db_connect()->table('campaigns c')
                ->select('c.id, c.name, c.status, c.template_id, c.total_contacts, c.sent_count, c.delivered_count, c.failed_count, c.scheduled_at, c.created_at, c.payload, t.name AS template_name')
                ->join('templates t', 't.id = c.template_id', 'left')
                ->orderBy('c.created_at', 'DESC')
                ->get()
                ->getResultArray();

            foreach ($wa as $c) {
                $payload = [];
                if (! empty($c['payload'])) {
                    $decoded = is_string($c['payload']) ? json_decode($c['payload'], true) : $c['payload'];
                    $payload = is_array($decoded) ? $decoded : [];
                }
                $audience = is_array($payload['_audience'] ?? null) ? $payload['_audience'] : [];
                $labelName = (string) ($audience['label_name'] ?? '');
                $rows[] = [
                    'id'             => (int) $c['id'],
                    'channel'        => 'whatsapp',
                    'name'           => (string) ($c['name'] ?? ''),
                    'label'          => $labelName,
                    'campaign_type'  => (string) ($c['template_name'] ?? 'WhatsApp'),
                    'status'         => (string) ($c['status'] ?? 'draft'),
                    'contacts'       => (int) ($c['total_contacts'] ?? 0),
                    'sent'           => (int) ($c['sent_count'] ?? 0),
                    'delivered'      => (int) ($c['delivered_count'] ?? 0),
                    'failed'         => (int) ($c['failed_count'] ?? 0),
                    'scheduled_at'   => $c['scheduled_at'] ?? null,
                    'created_at'     => $c['created_at'] ?? null,
                    'view_url'       => site_url('campaigns/' . (int) $c['id']),
                    'edit_url'       => site_url('campaigns/' . (int) $c['id'] . '/edit'),
                ];
            }
        }

        if (($channel === '' || $channel === 'email' || $channel === 'all') && db_connect()->tableExists('email_html_campaigns')) {
            $em = model(EmailHtmlCampaignModel::class)->orderBy('id', 'DESC')->findAll(200);
            foreach ($em as $c) {
                if (! is_array($c) || ! isset($c['id'])) {
                    continue;
                }
                $rows[] = [
                    'id'            => (int) $c['id'],
                    'channel'       => 'email',
                    'name'          => (string) ($c['name'] ?? ''),
                    'label'         => (string) ($c['label_name'] ?? ''),
                    'campaign_type' => (string) ($c['subject'] ?? 'Email'),
                    'status'        => (string) ($c['status'] ?? 'draft'),
                    'contacts'      => is_array($c['recipients'] ?? null) ? count($c['recipients']) : 0,
                    'sent'          => (int) ($c['sent_count'] ?? 0),
                    'delivered'     => (int) ($c['sent_count'] ?? 0),
                    'failed'        => (int) ($c['failed_count'] ?? 0),
                    'scheduled_at'  => $c['scheduled_at'] ?? null,
                    'created_at'    => $c['created_at'] ?? null,
                    'view_url'      => site_url('email-manager?tab=campaigns'),
                    'edit_url'      => site_url('email-manager?tab=campaigns'),
                ];
            }
        }

        if ($status !== '') {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => strtolower((string) $r['status']) === $status));
        }
        if ($search !== '') {
            $q = mb_strtolower($search);
            $rows = array_values(array_filter($rows, static function (array $r) use ($q): bool {
                return str_contains(mb_strtolower($r['name']), $q)
                    || str_contains(mb_strtolower($r['label']), $q)
                    || str_contains(mb_strtolower($r['campaign_type']), $q);
            }));
        }

        usort($rows, static function (array $a, array $b) use ($sort): int {
            $ta = strtotime((string) ($a['created_at'] ?? '')) ?: 0;
            $tb = strtotime((string) ($b['created_at'] ?? '')) ?: 0;
            if ($sort === 'oldest') {
                return $ta <=> $tb;
            }
            if ($sort === 'name') {
                return strcasecmp($a['name'], $b['name']);
            }

            return $tb <=> $ta;
        });

        return $rows;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return list<array{name:string,condition:string,value:string}>
     */
    protected function parseAttributesFromInput(array $input): array
    {
        $raw = $input['attributes'] ?? $input['attribute_filters'] ?? [];
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $raw     = is_array($decoded) ? $decoded : [];
        }
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = strtolower(trim((string) ($row['name'] ?? $row['attribute'] ?? '')));
            $value = trim((string) ($row['value'] ?? ''));
            $condition = strtolower(trim((string) ($row['condition'] ?? 'equals')));
            if ($name === '' || $value === '') {
                continue;
            }
            if (! in_array($condition, ['equals', 'contains', 'starts_with', 'not_equals'], true)) {
                $condition = 'equals';
            }
            $out[] = [
                'name'      => $name,
                'condition' => $condition,
                'value'     => $value,
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $campaign
     *
     * @return array{all: bool, contact_ids: list<int>, tag_ids: list<int>}
     */
    protected function audienceFromCampaignPayload(array $campaign): array
    {
        $payload = is_array($campaign['payload'] ?? null) ? $campaign['payload'] : [];
        if ($payload === [] && ! empty($campaign['payload']) && is_string($campaign['payload'])) {
            $decoded = json_decode($campaign['payload'], true);
            $payload = is_array($decoded) ? $decoded : [];
        }
        $audience = is_array($payload['_audience'] ?? null) ? $payload['_audience'] : [];

        return [
            'all'         => ! empty($audience['all']),
            'contact_ids' => array_values(array_map('intval', $audience['contact_ids'] ?? [])),
            'tag_ids'     => array_values(array_map('intval', $audience['tag_ids'] ?? [])),
        ];
    }

    /**
     * @param mixed $variables
     *
     * @return list<string>
     */
    protected function normalizeTemplateVariables(mixed $variables, string $body = ''): array
    {
        if (is_string($variables) && $variables !== '') {
            $decoded   = json_decode($variables, true);
            $variables = is_array($decoded) ? $decoded : null;
        }
        if (is_array($variables) && $variables !== []) {
            $out = [];
            foreach ($variables as $key => $value) {
                if (is_int($key) || ctype_digit((string) $key)) {
                    $out[] = (string) (is_scalar($value) && ! is_bool($value) && (string) $value !== '' ? $value : $key);
                } elseif (is_string($value) && preg_match('/^\d+$/', $value)) {
                    $out[] = $value;
                } else {
                    $out[] = (string) $key;
                }
            }

            return array_values(array_unique($out));
        }

        if ($body !== '' && preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $body, $m)) {
            $nums = array_map('intval', $m[1]);
            sort($nums);

            return array_map('strval', array_values(array_unique($nums)));
        }

        return [];
    }

    protected function ensureEmailScheduledAtColumn(): void
    {
        $db = db_connect();
        if (! $db->tableExists('email_html_campaigns')) {
            return;
        }
        if (! $db->fieldExists('scheduled_at', 'email_html_campaigns')) {
            $db->query('ALTER TABLE email_html_campaigns ADD COLUMN scheduled_at DATETIME NULL');
        }
    }

    /**
     * @return list<int>|null
     */
    protected function optionalIdList(string $key): ?array
    {
        $json = $this->request->getJSON(true);
        $raw  = $this->request->getPost($key)
            ?? (is_array($json) ? ($json[$key] ?? ($json['audience'][$key] ?? null)) : null);
        if ($raw === null || $raw === '') {
            return null;
        }
        if (! is_array($raw)) {
            $raw = explode(',', (string) $raw);
        }

        return array_values(array_filter(array_map('intval', $raw)));
    }

    /**
     * @return array{all: bool, contact_ids: list<int>, tag_ids: list<int>}
     */
    protected function parseAudienceFromRequest(): array
    {
        $json = $this->request->getJSON(true);
        $all  = (string) ($this->request->getPost('audience_all')
            ?? (is_array($json) ? ($json['audience_all'] ?? $json['audience']['all_active'] ?? $json['all_active'] ?? '0') : '0'));

        return [
            'all'          => in_array($all, ['1', 'true', 'on', 'yes'], true),
            'contact_ids'  => $this->optionalIdList('contact_ids') ?? [],
            'tag_ids'      => $this->optionalIdList('tag_ids') ?? [],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function parsePayloadFromRequest(): ?array
    {
        $payload = $this->request->getPost('payload');
        if (is_string($payload) && $payload !== '') {
            $decoded = json_decode($payload, true);

            return is_array($decoded) ? $decoded : ['text' => $payload];
        }
        if (is_array($payload)) {
            return $payload;
        }

        $text = (string) ($this->request->getPost('message_text') ?? '');

        return $text !== '' ? ['text' => $text] : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function parseVariablesFromRequest(): ?array
    {
        $variables = $this->request->getPost('variables');
        if (is_string($variables) && $variables !== '') {
            $decoded   = json_decode($variables, true);
            $variables = is_array($decoded) ? $decoded : null;
        }
        if (! is_array($variables)) {
            $variables = [];
        }

        $custom = $this->request->getPost('variables_custom');
        if (is_array($custom)) {
            foreach ($custom as $key => $value) {
                $src = $variables[$key] ?? null;
                if ($src === null || $src === '' || $src === 'custom') {
                    $variables[$key] = (string) $value;
                }
            }
        }

        return $variables === [] ? null : $variables;
    }

    /**
     * @param array{all: bool, contact_ids: list<int>, tag_ids: list<int>} $audience
     */
    protected function applyFormAction(int $id, array $audience): ResponseInterface
    {
        $action = (string) ($this->request->getPost('action') ?? 'draft');

        if ($action === 'schedule') {
            $scheduledAt = (string) ($this->request->getPost('scheduled_at') ?? '');
            if ($scheduledAt === '' || strtotime($scheduledAt) === false) {
                return redirect()->to('/campaigns/' . $id)->with('error', 'A valid scheduled_at datetime is required to schedule.');
            }
            // Normalize datetime-local to Y-m-d H:i:s
            $ts = date('Y-m-d H:i:s', (int) strtotime($scheduledAt));
            service('campaignService')->schedule($id, $ts);

            return redirect()->to('/campaigns/' . $id)->with('success', 'Campaign scheduled.');
        }

        if ($action === 'send_now') {
            $contactIds = $audience['contact_ids'] !== [] ? $audience['contact_ids'] : null;
            $tagIds     = $audience['tag_ids'] !== [] ? $audience['tag_ids'] : null;
            $result     = service('campaignService')->start($id, $contactIds, $tagIds, $audience['all']);

            return redirect()->to('/campaigns/' . $id)->with(
                'success',
                'Campaign started. Queued ' . $result['queued'] . ' messages.'
            );
        }

        return redirect()->to('/campaigns/' . $id)->with('success', 'Campaign saved as draft.');
    }

    /**
     * @param array<string, mixed> $campaign
     *
     * @return array<string, mixed>
     */
    protected function hydrateAudienceFields(array $campaign): array
    {
        $payload  = is_array($campaign['payload'] ?? null) ? $campaign['payload'] : [];
        $audience = is_array($payload['_audience'] ?? null) ? $payload['_audience'] : [];

        $campaign['audience_all'] = ! empty($audience['all']);
        $campaign['contact_ids']  = array_map('intval', $audience['contact_ids'] ?? []);
        $campaign['tag_ids']      = array_map('intval', $audience['tag_ids'] ?? []);

        return $campaign;
    }

    protected function okOrRedirect(string $url, string $message): ResponseInterface
    {
        if ($this->request->isAJAX()) {
            return $this->jsonResponse(true, null, $message);
        }

        return redirect()->to($url)->with('success', $message);
    }

    protected function failOrRedirect(string $message): ResponseInterface
    {
        if ($this->request->isAJAX()) {
            return $this->jsonResponse(false, null, $message, [], 400);
        }

        return redirect()->back()->with('error', $message);
    }
}
