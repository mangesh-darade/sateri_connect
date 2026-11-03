<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\SocialAccountService;
use App\Libraries\SocialCaptionService;
use App\Libraries\SocialLibraryService;
use App\Libraries\SocialScheduleService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Social scheduler: calendar, Google Drive media library and bulk scheduling (docs/SOCIAL_SCHEDULER.md).
 */
class Social extends BaseController
{
    public function index(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('social.view')) {
            return $denied;
        }

        $accounts = new SocialAccountService();
        $accounts->ensureWhatsAppChannel();

        return view('social/calendar', [
            'pageTitle' => 'Social Calendar',
            'accounts'  => array_values(array_filter($accounts->list(), static fn (array $a): bool => $a['status'] === 'active')),
            'templates' => model(\App\Models\TemplateModel::class)->select('id,name,language')->where('status', 'APPROVED')->orderBy('name')->findAll(200),
            'tags'      => model(\App\Models\TagModel::class)->select('id,name')->orderBy('name')->findAll(500),
        ]);
    }

    public function calendarData(): ResponseInterface
    {
        if ($denied = $this->requirePermission('social.view')) {
            return $denied;
        }

        $from = (string) $this->request->getGet('from');
        $to   = (string) $this->request->getGet('to');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) !== 1
            || strtotime($from) === false || strtotime($to) === false) {
            return $this->jsonResponse(false, null, 'Invalid date range.', [], 422);
        }

        return $this->jsonResponse(true, (new SocialScheduleService())->calendar($from, $to));
    }

    public function library(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('social.view')) {
            return $denied;
        }

        $library  = new SocialLibraryService();
        $accounts = new SocialAccountService();
        $accounts->ensureWhatsAppChannel();

        return view('social/library', [
            'pageTitle'    => 'Media Library',
            'driveReady'   => $library->drive()->isConfigured(),
            'aiLabel'      => (new \App\Libraries\SocialAiClient())->label(),
            'aiReady'      => (new \App\Libraries\SocialAiClient())->isConfigured(),
            'serviceEmail' => $library->drive()->serviceAccountEmail(),
            'folders'      => $library->folders(),
            'assets'       => $library->assets(),
            'accounts'     => $accounts->list(),
            'templates'    => model(\App\Models\TemplateModel::class)->select('id,name,language')->where('status', 'APPROVED')->orderBy('name')->findAll(200),
            'tags'         => model(\App\Models\TagModel::class)->select('id,name')->orderBy('name')->findAll(500),
        ]);
    }

    /**
     * Plays or shows a library file inside the app. Needs a signed-in user with Social access; supports Range,
     * so the browser's video controls can seek.
     */
    public function mediaFile(string|int $id = 0): ResponseInterface
    {
        if ($denied = $this->requirePermission('social.view')) {
            return $denied;
        }
        $asset = model(\App\Models\SocialMediaAssetModel::class)->find((int) $id);
        if (! is_array($asset)) {
            return $this->response->setStatusCode(404)->setBody('Not found');
        }

        if (str_starts_with((string) $asset['drive_file_id'], 'local:')) {
            $local = \App\Libraries\SocialMediaDelivery::localPath((string) $asset['drive_file_id']);

            return $local === null
                ? $this->response->setStatusCode(404)->setBody('Not found')
                : $this->response->setHeader('Content-Type', (string) ($asset['mime'] ?: 'image/jpeg'))->setBody((string) file_get_contents($local));
        }

        // Release the session lock: a long video must not block the user's other pages.
        session_write_close();
        ignore_user_abort(true);
        set_time_limit(0);

        $ok = (new \App\Libraries\GoogleDriveService())->stream(
            (string) $asset['drive_file_id'],
            (string) ($this->request->getHeaderLine('Range')),
            (string) ($asset['mime'] ?? '')
        );
        if (! $ok) {
            return $this->response->setStatusCode(502)->setBody('The file could not be loaded from Google Drive.');
        }
        exit;
    }

    public function mediaThumb(string|int $id = 0): ResponseInterface
    {
        if ($denied = $this->requirePermission('social.view')) {
            return $denied;
        }
        $asset = model(\App\Models\SocialMediaAssetModel::class)->find((int) $id);
        if (! is_array($asset)) {
            return $this->response->setStatusCode(404)->setBody('Not found');
        }
        session_write_close();

        if (str_starts_with((string) $asset['drive_file_id'], 'local:')) {
            $local = \App\Libraries\SocialMediaDelivery::localPath((string) $asset['drive_file_id']);

            return $local === null
                ? $this->response->setStatusCode(404)->setBody('No preview')
                : $this->response->setHeader('Content-Type', (string) ($asset['mime'] ?: 'image/jpeg'))->setHeader('Cache-Control', 'private, max-age=3600')->setBody((string) file_get_contents($local));
        }

        $thumb = (new \App\Libraries\GoogleDriveService())->thumbnail((string) $asset['drive_file_id']);
        if ($thumb === null) {
            return $this->response->setStatusCode(404)->setBody('No preview');
        }

        // Browser-side only (no server cache): lets a page of thumbnails reload instantly.
        return $this->response
            ->setHeader('Content-Type', $thumb['mime'])
            ->setHeader('Cache-Control', 'private, max-age=3600')
            ->setBody($thumb['body']);
    }

    public function addFolder(): ResponseInterface
    {
        if ($denied = $this->requirePermission('social.create')) {
            return $denied;
        }

        return $this->respond((new SocialLibraryService())->addFolder((string) $this->request->getPost('folder')));
    }

    public function scanFolder(string|int $id): ResponseInterface
    {
        $id = (int) $id;
        if ($denied = $this->requirePermission('social.create')) {
            return $denied;
        }

        return $this->respond((new SocialLibraryService())->scan($id));
    }

    public function removeFolder(string|int $id): ResponseInterface
    {
        $id = (int) $id;
        if ($denied = $this->requirePermission('social.create')) {
            return $denied;
        }

        return $this->respond((new SocialLibraryService())->removeFolder($id));
    }

    public function schedule(): ResponseInterface
    {
        if ($denied = $this->requirePermission('social.create')) {
            return $denied;
        }

        $in = $this->request->getPost();

        return $this->respond((new SocialScheduleService())->createBulk(
            (array) ($in['assets'] ?? []),
            (array) ($in['accounts'] ?? []),
            (string) ($in['start_date'] ?? ''),
            array_map('strval', (array) ($in['times'] ?? [])),
            trim((string) ($in['caption'] ?? '')),
            trim((string) ($in['hashtags'] ?? '')),
            session()->get('user_id') !== null ? (int) session()->get('user_id') : null,
            [
                'template_id' => (int) ($in['wa_template_id'] ?? 0),
                'tag_ids'     => (array) ($in['wa_tag_ids'] ?? []),
                'all'         => ! empty($in['wa_all']),
                'caption_var' => ! empty($in['wa_caption_var']),
            ],
            (array) ($in['captions'] ?? [])
        ));
    }

    public function textPost(): ResponseInterface
    {
        if ($denied = $this->requirePermission('social.create')) {
            return $denied;
        }

        $in = $this->request->getPost();

        return $this->respond((new SocialScheduleService())->createText(
            array_map('intval', (array) ($in['accounts'] ?? [])),
            (string) ($in['when'] ?? ''),
            trim((string) ($in['caption'] ?? '')),
            trim((string) ($in['hashtags'] ?? '')),
            session()->get('user_id') !== null ? (int) session()->get('user_id') : null,
            [
                'template_id' => (int) ($in['wa_template_id'] ?? 0),
                'tag_ids'     => (array) ($in['wa_tag_ids'] ?? []),
                'all'         => ! empty($in['wa_all']),
                'caption_var' => ! empty($in['wa_caption_var']),
            ]
        ));
    }

    public function captions(): ResponseInterface
    {
        if ($denied = $this->requirePermission('social.create')) {
            return $denied;
        }

        $r = (new SocialCaptionService())->suggest(
            (string) $this->request->getPost('topic'),
            (string) $this->request->getPost('platform'),
            (string) $this->request->getPost('tone'),
            (string) $this->request->getPost('language')
        );
        if ($r['ok']) {
            log_activity('social_ai_captions', 'social', 'Generated AI caption suggestions');
        }

        return $this->respond($r);
    }

    public function reelScript(): ResponseInterface
    {
        if ($denied = $this->requirePermission('social.create')) {
            return $denied;
        }
        session_write_close();

        $r = (new SocialCaptionService())->reelScript(
            (string) $this->request->getPost('topic'),
            (string) $this->request->getPost('platform'),
            (string) $this->request->getPost('tone'),
            (string) $this->request->getPost('language'),
            (int) $this->request->getPost('seconds')
        );
        if ($r['ok']) {
            log_activity('social_ai_script', 'social', 'Generated an AI reel/video script');
        }

        return $this->respond($r);
    }

    public function rewriteCaption(): ResponseInterface
    {
        if ($denied = $this->requirePermission('social.create')) {
            return $denied;
        }
        session_write_close();

        $r = (new SocialCaptionService())->rewrite(
            (string) $this->request->getPost('text'),
            (string) $this->request->getPost('action'),
            (string) $this->request->getPost('platform'),
            (string) $this->request->getPost('tone'),
            (string) $this->request->getPost('language')
        );
        if ($r['ok']) {
            log_activity('social_ai_rewrite', 'social', 'Rewrote a caption with AI', ['action' => (string) $this->request->getPost('action')]);
        }

        return $this->respond($r);
    }

    /**
     * One file's captions (one per platform). The browser calls this once per file so 20 files never sit in one slow request.
     */
    public function fileCaptions(): ResponseInterface
    {
        if ($denied = $this->requirePermission('social.create')) {
            return $denied;
        }
        $asset = model(\App\Models\SocialMediaAssetModel::class)->find((int) $this->request->getPost('asset_id'));
        if (! is_array($asset)) {
            return $this->jsonResponse(false, null, 'File not found.', [], 404);
        }
        session_write_close();

        $r = (new SocialCaptionService())->forFile(
            $asset,
            array_map('strval', (array) $this->request->getPost('platforms')),
            (string) $this->request->getPost('tone'),
            (string) $this->request->getPost('language'),
            (string) $this->request->getPost('topic')
        );
        if ($r['ok']) {
            log_activity('social_ai_file_captions', 'social', 'Generated AI captions for a library file', ['asset_id' => (int) $asset['id']]);
        }

        return $this->respond($r);
    }

    public function cancel(string|int $id): ResponseInterface
    {
        $id = (int) $id;
        if ($denied = $this->requirePermission('social.create')) {
            return $denied;
        }

        return $this->respond((new SocialScheduleService())->cancel($id));
    }

    public function delete(string|int $id): ResponseInterface
    {
        $id = (int) $id;
        if ($denied = $this->requirePermission('social.create')) {
            return $denied;
        }

        return $this->respond((new SocialScheduleService())->delete($id, (string) $this->request->getPost('remote') === '1'));
    }

    public function reschedule(string|int $id): ResponseInterface
    {
        $id = (int) $id;
        if ($denied = $this->requirePermission('social.create')) {
            return $denied;
        }

        return $this->respond((new SocialScheduleService())->reschedule($id, (string) $this->request->getPost('when')));
    }

    public function retry(string|int $id): ResponseInterface
    {
        $id = (int) $id;
        if ($denied = $this->requirePermission('social.create')) {
            return $denied;
        }

        return $this->respond((new SocialScheduleService())->retry($id));
    }

    /**
     * @param array<string, mixed> $r service result
     */
    private function respond(array $r): ResponseInterface
    {
        return $this->jsonResponse((bool) $r['ok'], $r['data'] ?? null, (string) $r['message'], [], (int) ($r['ok'] ? 200 : max(400, (int) $r['status'])));
    }
}
