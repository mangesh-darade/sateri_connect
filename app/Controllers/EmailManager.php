<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\ActivityLogger;
use App\Libraries\EmailVerifier;
use App\Libraries\SettingsService;
use App\Models\EmailBuilderModel;
use App\Models\EmailDripModel;
use App\Models\EmailDripStepModel;
use App\Models\EmailHtmlCampaignModel;
use App\Models\EmailLogModel;
use App\Models\EmailSenderModel;
use App\Models\EmailVerificationModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Email Manager — Builder, Drips, Verifier, HTML Campaigns, Sender/Domain IDs.
 * Sends via Cheerio (or active email provider) when configured.
 */
class EmailManager extends BaseController
{
    protected const MAX_CAMPAIGN_RECIPIENTS = 100;

    public function index(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('emails.view')) {
            return $denied;
        }

        $tab = strtolower(trim((string) ($this->request->getGet('tab') ?: 'builder')));
        $allowed = ['builder', 'drips', 'verifier', 'campaigns', 'senders'];
        if (! in_array($tab, $allowed, true)) {
            $tab = 'builder';
        }

        $settings = new SettingsService();
        $provider = $settings->getEmailProvider();

        $campaignRows = model(EmailHtmlCampaignModel::class)->orderBy('id', 'DESC')->findAll(50);
        $campaigns = [];
        foreach ($campaignRows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $campaigns[] = [
                'id'                 => (int) ($row['id'] ?? 0),
                'name'               => (string) ($row['name'] ?? ('Campaign #' . ((int) ($row['id'] ?? 0)))),
                'subject'            => (string) ($row['subject'] ?? ''),
                'status'             => (string) ($row['status'] ?? 'draft'),
                'sent_count'         => (int) ($row['sent_count'] ?? 0),
                'failed_count'       => (int) ($row['failed_count'] ?? 0),
                'builder_id'         => ! empty($row['builder_id']) ? (int) $row['builder_id'] : null,
                'cheerio_builder_id' => isset($row['cheerio_builder_id']) ? (string) $row['cheerio_builder_id'] : null,
                'mode'               => (string) ($row['mode'] ?? 'recipients'),
                'label_name'         => isset($row['label_name']) ? (string) $row['label_name'] : null,
                'recipients'         => is_array($row['recipients'] ?? null) ? $row['recipients'] : [],
                'html_content'       => (string) ($row['html_content'] ?? ''),
                'last_error'         => isset($row['last_error']) ? (string) $row['last_error'] : null,
            ];
        }

        return $this->render('email_manager/index', [
            'pageTitle'     => 'Email Manager',
            'activeTab'     => $tab,
            'provider'      => $provider,
            'providerLabel' => $this->providerLabel($provider),
            'isCheerio'     => $provider === SettingsService::EMAIL_PROVIDER_CHEERIO,
            'builders'      => model(EmailBuilderModel::class)->orderBy('id', 'DESC')->findAll(100),
            'drips'         => model(EmailDripModel::class)->withSteps(50),
            'campaigns'     => $campaigns,
            'senders'       => model(EmailSenderModel::class)->orderBy('type', 'ASC')->orderBy('id', 'DESC')->findAll(100),
            'verifications' => model(EmailVerificationModel::class)->orderBy('id', 'DESC')->findAll(50),
            'defaultCampaign' => (string) $settings->get('cheerio_email_campaign_name', 'app-direct'),
        ]);
    }

    // ─── Builders ───────────────────────────────────────────────

    public function saveBuilder(): ResponseInterface
    {
        if ($denied = $this->requirePermission('emails.send')) {
            return $denied;
        }

        $input = $this->requestInput();
        $id    = (int) ($input['id'] ?? 0);
        $name  = trim((string) ($input['name'] ?? ''));
        $subject = trim((string) ($input['subject'] ?? ''));
        $html  = (string) ($input['html_content'] ?? '');
        $cheerioId = trim((string) ($input['cheerio_builder_id'] ?? ''));
        $status = (string) ($input['status'] ?? 'draft');
        if (! in_array($status, ['draft', 'active', 'archived'], true)) {
            $status = 'draft';
        }

        if ($name === '') {
            return $this->jsonResponse(false, null, 'Builder name is required.', ['name' => 'Required'], 422);
        }

        $row = [
            'name'               => $name,
            'subject'            => $subject !== '' ? $subject : null,
            'html_content'       => $html,
            'cheerio_builder_id' => $cheerioId !== '' ? $cheerioId : null,
            'status'             => $status,
        ];

        $model = model(EmailBuilderModel::class);
        try {
            if ($id > 0) {
                $existing = $model->find($id);
                if (! $existing) {
                    return $this->jsonResponse(false, null, 'Builder not found.', [], 404);
                }
                $model->update($id, $row);
            } else {
                $row['created_by'] = (int) ($this->currentUser['id'] ?? 0) ?: null;
                $id = (int) $model->insert($row, true);
            }

            $saved = $model->find($id);
            (new ActivityLogger())->log('email_builder_save', 'emails', 'Saved email builder: ' . $name, ['id' => $id]);

            return $this->jsonResponse(true, $saved, 'Builder saved.');
        } catch (\Throwable $e) {
            return $this->jsonResponse(false, null, $e->getMessage(), [], 500);
        }
    }

    public function deleteBuilder(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('emails.send')) {
            return $denied;
        }

        $model = model(EmailBuilderModel::class);
        $row   = $model->find($id);
        if (! $row) {
            return $this->jsonResponse(false, null, 'Builder not found.', [], 404);
        }

        $model->delete($id);
        (new ActivityLogger())->log('email_builder_delete', 'emails', 'Deleted email builder #' . $id);

        return $this->jsonResponse(true, null, 'Builder deleted.');
    }

    // ─── Drips ──────────────────────────────────────────────────

    public function saveDrip(): ResponseInterface
    {
        if ($denied = $this->requirePermission('emails.send')) {
            return $denied;
        }

        $input = $this->requestInput();
        $id    = (int) ($input['id'] ?? 0);
        $name  = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            return $this->jsonResponse(false, null, 'Drip name is required.', ['name' => 'Required'], 422);
        }

        $trigger = (string) ($input['trigger_type'] ?? 'manual');
        if (! in_array($trigger, ['manual', 'on_subscribe', 'on_tag'], true)) {
            $trigger = 'manual';
        }
        $status = (string) ($input['status'] ?? 'draft');
        if (! in_array($status, ['draft', 'active', 'paused', 'archived'], true)) {
            $status = 'draft';
        }

        $dripModel = model(EmailDripModel::class);
        $stepModel = model(EmailDripStepModel::class);

        $payload = [
            'name'          => $name,
            'description'   => trim((string) ($input['description'] ?? '')) ?: null,
            'trigger_type'  => $trigger,
            'trigger_value' => trim((string) ($input['trigger_value'] ?? '')) ?: null,
            'status'        => $status,
        ];

        $db = db_connect();
        $db->transStart();

        try {
            if ($id > 0) {
                if (! $dripModel->find($id)) {
                    return $this->jsonResponse(false, null, 'Drip not found.', [], 404);
                }
                $dripModel->update($id, $payload);
                $stepModel->where('drip_id', $id)->delete();
            } else {
                $payload['created_by'] = (int) ($this->currentUser['id'] ?? 0) ?: null;
                $id = (int) $dripModel->insert($payload, true);
            }

            $steps = $input['steps'] ?? [];
            if (is_string($steps)) {
                $decoded = json_decode($steps, true);
                $steps   = is_array($decoded) ? $decoded : [];
            }

            $order = 1;
            if (is_array($steps)) {
                foreach ($steps as $step) {
                    if (! is_array($step)) {
                        continue;
                    }
                    $subject = trim((string) ($step['subject'] ?? ''));
                    if ($subject === '') {
                        continue;
                    }
                    $stepModel->insert([
                        'drip_id'      => $id,
                        'step_order'   => $order++,
                        'delay_hours'  => max(0, (int) ($step['delay_hours'] ?? 0)),
                        'subject'      => $subject,
                        'html_content' => (string) ($step['html_content'] ?? ''),
                        'builder_id'   => ! empty($step['builder_id']) ? (int) $step['builder_id'] : null,
                    ]);
                }
            }

            $db->transComplete();
            if (! $db->transStatus()) {
                return $this->jsonResponse(false, null, 'Failed to save drip.', [], 500);
            }

            $saved = $dripModel->withSteps();
            $match = null;
            foreach ($saved as $d) {
                if ((int) $d['id'] === $id) {
                    $match = $d;
                    break;
                }
            }

            (new ActivityLogger())->log('email_drip_save', 'emails', 'Saved email drip: ' . $name, ['id' => $id]);

            return $this->jsonResponse(true, $match, 'Drip saved.');
        } catch (\Throwable $e) {
            $db->transRollback();

            return $this->jsonResponse(false, null, $e->getMessage(), [], 500);
        }
    }

    public function deleteDrip(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('emails.send')) {
            return $denied;
        }

        $model = model(EmailDripModel::class);
        if (! $model->find($id)) {
            return $this->jsonResponse(false, null, 'Drip not found.', [], 404);
        }
        $model->delete($id);
        (new ActivityLogger())->log('email_drip_delete', 'emails', 'Deleted email drip #' . $id);

        return $this->jsonResponse(true, null, 'Drip deleted.');
    }

    public function sendDripStep(): ResponseInterface
    {
        if ($denied = $this->requirePermission('emails.send')) {
            return $denied;
        }

        $input  = $this->requestInput();
        $dripId = (int) ($input['drip_id'] ?? 0);
        $stepId = (int) ($input['step_id'] ?? 0);
        $to     = trim((string) ($input['to'] ?? ''));

        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return $this->jsonResponse(false, null, 'Valid recipient email required.', ['to' => 'Invalid'], 422);
        }

        $drip = model(EmailDripModel::class)->find($dripId);
        $step = model(EmailDripStepModel::class)->find($stepId);
        if (! $drip || ! $step || (int) $step['drip_id'] !== $dripId) {
            return $this->jsonResponse(false, null, 'Drip step not found.', [], 404);
        }

        $html    = (string) ($step['html_content'] ?? '');
        $options = [];
        $builderId = null;

        if (! empty($step['builder_id'])) {
            $builder = model(EmailBuilderModel::class)->find((int) $step['builder_id']);
            if ($builder) {
                $builderId = (int) $builder['id'];
                if ($html === '' && ! empty($builder['html_content'])) {
                    $html = (string) $builder['html_content'];
                }
                if (! empty($builder['cheerio_builder_id'])) {
                    $options['email_builder_id'] = (string) $builder['cheerio_builder_id'];
                }
            }
        }

        if ($html === '' && empty($options['email_builder_id'])) {
            return $this->jsonResponse(false, null, 'Step has no HTML content or builder.', [], 422);
        }

        try {
            $mailer = service('emailProvider');
            $result = $mailer->sendHtml($to, (string) $step['subject'], $html !== '' ? $html : '<p></p>', $options);
            $ok     = (bool) ($result['ok'] ?? false);

            model(EmailLogModel::class)->record(
                'drip',
                $ok ? 'sent' : 'failed',
                (string) $step['subject'],
                $to,
                (string) ($result['provider'] ?? ''),
                (string) ($result['message'] ?? ''),
                ['drip_id' => $dripId, 'step_id' => $stepId],
                (int) ($this->currentUser['id'] ?? 0) ?: null,
                $builderId,
                null,
                $dripId
            );

            return $this->jsonResponse($ok, $result, $ok ? 'Drip step sent via provider.' : (string) ($result['message'] ?? 'Send failed.'));
        } catch (\Throwable $e) {
            return $this->jsonResponse(false, null, $e->getMessage(), [], 500);
        }
    }

    // ─── Verifier ───────────────────────────────────────────────

    public function verifyEmails(): ResponseInterface
    {
        if ($denied = $this->requirePermission('emails.view')) {
            return $denied;
        }

        $input = $this->requestInput();
        $raw   = (string) ($input['emails'] ?? $input['email'] ?? '');
        $parts = preg_split('/[\s,;]+/', $raw) ?: [];
        $list  = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '') {
                $list[] = $p;
            }
        }

        if ($list === []) {
            return $this->jsonResponse(false, null, 'Provide at least one email.', ['emails' => 'Required'], 422);
        }

        $verifier = new EmailVerifier();
        $results  = $verifier->verifyMany($list, 50);
        $model    = model(EmailVerificationModel::class);
        $saved    = [];

        foreach ($results as $r) {
            $existing = $model->where('email', $r['email'])->first();
            $row = [
                'email'       => $r['email'],
                'status'      => $r['status'],
                'syntax_ok'   => $r['syntax_ok'] ? 1 : 0,
                'mx_ok'       => $r['mx_ok'] ? 1 : 0,
                'disposable'  => $r['disposable'] ? 1 : 0,
                'checks_json' => $r['checks'],
                'verified_at' => date('Y-m-d H:i:s'),
            ];
            if ($existing) {
                $model->update((int) $existing['id'], $row);
                $row['id'] = (int) $existing['id'];
            } else {
                $row['id'] = (int) $model->insert($row, true);
            }
            $row['checks'] = $r['checks'];
            $saved[] = $row;
        }

        return $this->jsonResponse(true, ['results' => $saved], 'Verification complete.');
    }

    // ─── HTML Campaigns ─────────────────────────────────────────

    public function saveCampaign(): ResponseInterface
    {
        if ($denied = $this->requirePermission('emails.send')) {
            return $denied;
        }

        $input   = $this->requestInput();
        $id      = (int) ($input['id'] ?? 0);
        $name    = trim((string) ($input['name'] ?? ''));
        $subject = trim((string) ($input['subject'] ?? ''));
        $html    = (string) ($input['html_content'] ?? '');
        $mode    = strtolower(trim((string) ($input['mode'] ?? 'recipients')));
        if (! in_array($mode, ['recipients', 'label'], true)) {
            $mode = 'recipients';
        }
        $provider = (new SettingsService())->getEmailProvider();
        if ($mode === 'label' && $provider !== SettingsService::EMAIL_PROVIDER_CHEERIO) {
            return $this->jsonResponse(
                false,
                null,
                'Label mode is only available when Cheerio Email API is active.',
                ['mode' => 'Use recipients mode for SMTP/SendGrid.'],
                422
            );
        }

        if ($name === '' || $subject === '') {
            return $this->jsonResponse(false, null, 'Name and subject are required.', [], 422);
        }

        $recipients = [];
        $raw = (string) ($input['recipients'] ?? '');
        if ($raw !== '') {
            $parts = preg_split('/[\s,;]+/', $raw) ?: [];
            foreach ($parts as $p) {
                $p = strtolower(trim($p));
                if ($p !== '' && filter_var($p, FILTER_VALIDATE_EMAIL)) {
                    $recipients[] = $p;
                }
            }
            $recipients = array_values(array_unique($recipients));
        }
        if (isset($input['recipients']) && is_array($input['recipients'])) {
            foreach ($input['recipients'] as $p) {
                $p = strtolower(trim((string) $p));
                if ($p !== '' && filter_var($p, FILTER_VALIDATE_EMAIL)) {
                    $recipients[] = $p;
                }
            }
            $recipients = array_values(array_unique($recipients));
        }

        if (count($recipients) > self::MAX_CAMPAIGN_RECIPIENTS) {
            return $this->jsonResponse(false, null, 'Max ' . self::MAX_CAMPAIGN_RECIPIENTS . ' recipients.', [], 422);
        }

        $builderId = ! empty($input['builder_id']) ? (int) $input['builder_id'] : null;
        $cheerioBuilderId = trim((string) ($input['cheerio_builder_id'] ?? ''));

        if ($builderId) {
            $builder = model(EmailBuilderModel::class)->find($builderId);
            if ($builder) {
                if ($html === '' && ! empty($builder['html_content'])) {
                    $html = (string) $builder['html_content'];
                }
                if ($cheerioBuilderId === '' && ! empty($builder['cheerio_builder_id'])) {
                    $cheerioBuilderId = (string) $builder['cheerio_builder_id'];
                }
            }
        }

        $row = [
            'name'               => $name,
            'subject'            => $subject,
            'html_content'       => $html,
            'builder_id'         => $builderId,
            'cheerio_builder_id' => $cheerioBuilderId !== '' ? $cheerioBuilderId : null,
            'mode'               => $mode,
            'label_name'         => trim((string) ($input['label_name'] ?? '')) ?: null,
            'recipients_json'    => $recipients,
            'status'             => 'draft',
        ];

        $model = model(EmailHtmlCampaignModel::class);
        try {
            if ($id > 0) {
                if (! $model->find($id)) {
                    return $this->jsonResponse(false, null, 'Campaign not found.', [], 404);
                }
                $model->update($id, $row);
            } else {
                $row['created_by'] = (int) ($this->currentUser['id'] ?? 0) ?: null;
                $id = (int) $model->insert($row, true);
            }

            return $this->jsonResponse(true, $model->find($id), 'Campaign saved.');
        } catch (\Throwable $e) {
            return $this->jsonResponse(false, null, $e->getMessage(), [], 500);
        }
    }

    public function sendCampaign(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('emails.send')) {
            return $denied;
        }

        $model = model(EmailHtmlCampaignModel::class);
        $camp  = $model->find($id);
        if (! $camp) {
            return $this->jsonResponse(false, null, 'Campaign not found.', [], 404);
        }

        $settings = new SettingsService();
        $provider = $settings->getEmailProvider();
        $mode     = (string) ($camp['mode'] ?? 'recipients');
        $html     = (string) ($camp['html_content'] ?? '');
        $subject  = (string) ($camp['subject'] ?? '');
        $name     = (string) ($camp['name'] ?? 'html-campaign');

        $options = [
            'campaign_name' => $name,
        ];
        if ($provider === SettingsService::EMAIL_PROVIDER_CHEERIO && ! empty($camp['cheerio_builder_id'])) {
            $options['email_builder_id'] = (string) $camp['cheerio_builder_id'];
        }

        $model->update($id, ['status' => 'sending', 'last_error' => null]);

        try {
            $mailer = service('emailProvider');

            // Provider-specific send strategy:
            // - Cheerio: supports label mode + emailBuilderId.
            // - SMTP/SendGrid: send HTML directly to recipients list.
            if ($provider === SettingsService::EMAIL_PROVIDER_CHEERIO) {
                $campaignPayload = [
                    'name'          => $name,
                    'subject'       => $subject,
                    'html'          => $html !== '' ? $html : '<p></p>',
                    'campaign_name' => $name,
                ];

                if ($mode === 'label') {
                    $label = trim((string) ($camp['label_name'] ?? ''));
                    if ($label === '') {
                        return $this->jsonResponse(false, null, 'Label name required for label mode.', [], 422);
                    }
                    $campaignPayload['label_name'] = $label;
                } else {
                    $recipients = $camp['recipients'] ?? [];
                    if (! is_array($recipients) || $recipients === []) {
                        return $this->jsonResponse(false, null, 'No recipients on this campaign.', [], 422);
                    }
                    $campaignPayload['recipients'] = $recipients;
                }

                if (! empty($options['email_builder_id'])) {
                    $campaignPayload['email_builder_id'] = $options['email_builder_id'];
                }

                $result = $mailer->sendCampaign($campaignPayload);
            } else {
                if ($mode === 'label') {
                    return $this->jsonResponse(
                        false,
                        null,
                        'Label mode is only available for Cheerio provider. Use recipients mode for SMTP/SendGrid.',
                        [],
                        422
                    );
                }

                $recipients = $camp['recipients'] ?? [];
                if (! is_array($recipients) || $recipients === []) {
                    return $this->jsonResponse(false, null, 'No recipients on this campaign.', [], 422);
                }

                $body = $html !== '' ? $html : '<p></p>';
                $result = $mailer->sendHtml($recipients, $subject, $body, $options);
            }

            $ok     = (bool) ($result['ok'] ?? false);

            $rdata = is_array($result['data'] ?? null) ? $result['data'] : [];
            $sentCount = (int) ($rdata['sent'] ?? $rdata['emailCount'] ?? ($rdata['data']['emailCount'] ?? 0));
            if ($ok && $sentCount === 0 && $mode === 'recipients') {
                $sentCount = count($camp['recipients'] ?? []);
            }
            $failedCount = is_array($rdata['failed'] ?? null) ? count($rdata['failed']) : ($ok ? 0 : 1);

            $model->update($id, [
                'status'       => $ok ? 'sent' : 'failed',
                'sent_count'   => $sentCount,
                'failed_count' => $failedCount,
                'last_error'   => $ok ? null : (string) ($result['message'] ?? 'Send failed'),
                'sent_at'      => $ok ? date('Y-m-d H:i:s') : null,
            ]);

            $target = $mode === 'label'
                ? ('label:' . ($camp['label_name'] ?? ''))
                : implode(', ', array_slice($camp['recipients'] ?? [], 0, 3));

            model(EmailLogModel::class)->record(
                'campaign',
                $ok ? 'sent' : 'failed',
                (string) $camp['subject'],
                $target,
                (string) ($result['provider'] ?? ''),
                (string) ($result['message'] ?? ''),
                ['result' => $result['data'] ?? null],
                (int) ($this->currentUser['id'] ?? 0) ?: null,
                ! empty($camp['builder_id']) ? (int) $camp['builder_id'] : null,
                $id
            );

            (new ActivityLogger())->log(
                $ok ? 'email_campaign_sent' : 'email_campaign_failed',
                'emails',
                ($ok ? 'Sent' : 'Failed') . ' HTML email campaign: ' . $camp['name'],
                ['id' => $id]
            );

            return $this->jsonResponse($ok, $model->find($id), $ok ? 'Campaign sent.' : (string) ($result['message'] ?? 'Failed'));
        } catch (\Throwable $e) {
            $model->update($id, ['status' => 'failed', 'last_error' => $e->getMessage()]);

            return $this->jsonResponse(false, null, $e->getMessage(), [], 500);
        }
    }

    public function deleteCampaign(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('emails.send')) {
            return $denied;
        }

        $model = model(EmailHtmlCampaignModel::class);
        if (! $model->find($id)) {
            return $this->jsonResponse(false, null, 'Campaign not found.', [], 404);
        }
        $model->delete($id);

        return $this->jsonResponse(true, null, 'Campaign deleted.');
    }

    // ─── Senders / Domains ──────────────────────────────────────

    public function saveSender(): ResponseInterface
    {
        if ($denied = $this->requirePermission('emails.send')) {
            return $denied;
        }

        $input = $this->requestInput();
        $id    = (int) ($input['id'] ?? 0);
        $type  = (string) ($input['type'] ?? 'sender');
        if (! in_array($type, ['sender', 'domain'], true)) {
            $type = 'sender';
        }
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            return $this->jsonResponse(false, null, 'Name is required.', ['name' => 'Required'], 422);
        }

        $status = (string) ($input['status'] ?? 'pending');
        if (! in_array($status, ['pending', 'verified', 'failed', 'disabled'], true)) {
            $status = 'pending';
        }

        $dns = $input['dns_records'] ?? null;
        if (is_string($dns) && $dns !== '') {
            $decoded = json_decode($dns, true);
            $dns = is_array($decoded) ? $decoded : ['notes' => $dns];
        }

        $row = [
            'type'        => $type,
            'name'        => $name,
            'email'       => trim((string) ($input['email'] ?? '')) ?: null,
            'domain'      => trim((string) ($input['domain'] ?? '')) ?: null,
            'cheerio_id'  => trim((string) ($input['cheerio_id'] ?? '')) ?: null,
            'status'      => $status,
            'dns_records' => is_array($dns) ? $dns : null,
            'notes'       => trim((string) ($input['notes'] ?? '')) ?: null,
            'is_default'  => ! empty($input['is_default']) ? 1 : 0,
        ];

        if ($type === 'sender' && empty($row['email'])) {
            return $this->jsonResponse(false, null, 'Sender email is required.', ['email' => 'Required'], 422);
        }
        if ($type === 'domain' && empty($row['domain'])) {
            return $this->jsonResponse(false, null, 'Domain is required.', ['domain' => 'Required'], 422);
        }

        $model = model(EmailSenderModel::class);
        try {
            if (! empty($row['is_default'])) {
                $model->where('type', $type)->set(['is_default' => 0])->update();
            }

            if ($id > 0) {
                if (! $model->find($id)) {
                    return $this->jsonResponse(false, null, 'Record not found.', [], 404);
                }
                $model->update($id, $row);
            } else {
                $id = (int) $model->insert($row, true);
            }

            return $this->jsonResponse(true, $model->find($id), 'Saved. Verify this Sender/Domain in Cheerio Dashboard for delivery.');
        } catch (\Throwable $e) {
            return $this->jsonResponse(false, null, $e->getMessage(), [], 500);
        }
    }

    public function deleteSender(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('emails.send')) {
            return $denied;
        }

        $model = model(EmailSenderModel::class);
        if (! $model->find($id)) {
            return $this->jsonResponse(false, null, 'Record not found.', [], 404);
        }
        $model->delete($id);

        return $this->jsonResponse(true, null, 'Deleted.');
    }

    protected function providerLabel(string $provider): string
    {
        return match ($provider) {
            SettingsService::EMAIL_PROVIDER_SENDGRID => 'SendGrid',
            SettingsService::EMAIL_PROVIDER_CHEERIO  => 'Cheerio Email API',
            default                                  => 'SMTP',
        };
    }
}
