<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\ActivityLogger;
use App\Models\CampaignContactModel;
use App\Models\CampaignModel;
use App\Models\ContactModel;
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

        $status = (string) ($this->request->getGet('status') ?? '');
        $builder = db_connect()->table('campaigns c')
            ->select('c.*, t.name AS template_name')
            ->join('templates t', 't.id = c.template_id', 'left');

        if ($status !== '') {
            $builder->where('c.status', $status);
        }

        $campaigns = $builder->orderBy('c.created_at', 'DESC')->get()->getResultArray();

        return $this->render('campaigns/index', [
            'pageTitle' => 'Campaigns',
            'campaigns' => $campaigns,
            'filterStatus' => $status,
        ]);
    }

    public function create(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('campaigns.create')) {
            return $denied;
        }

        return $this->render('campaigns/form', [
            'pageTitle'  => 'Create Campaign',
            'campaign'   => null,
            'templates'  => model(TemplateModel::class)->getApproved(),
            'tags'       => model(TagModel::class)->orderBy('name', 'ASC')->findAll(),
            'contacts'   => model(ContactModel::class)->where('status', 'active')->orderBy('name', 'ASC')->findAll(2000),
        ]);
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
