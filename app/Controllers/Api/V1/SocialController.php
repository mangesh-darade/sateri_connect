<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Libraries\AppDateTime;
use App\Libraries\SocialAccountService;
use App\Libraries\SocialCaptionService;
use App\Libraries\SocialContentPlanner;
use App\Libraries\SocialLibraryService;
use App\Libraries\SocialScheduleService;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;

/**
 * REST API v1 Social: accounts, media library, calendar and scheduling (used by the Claude MCP connector).
 */
class SocialController extends BaseV1Controller
{
    /** GET /api/v1/social/accounts */
    public function accounts(): ResponseInterface
    {
        $rows = array_values(array_filter((new SocialAccountService())->list(), static fn (array $a): bool => $a['status'] === 'active'));

        return $this->respondSuccess(['accounts' => $rows, 'total' => count($rows)], 'Accounts loaded.');
    }

    /** GET /api/v1/social/library */
    public function library(): ResponseInterface
    {
        $assets = array_map(static fn (array $a): array => [
            'id'       => (int) $a['id'],
            'filename' => $a['filename'],
            'mime'     => $a['mime'],
            'duration' => $a['duration_sec'],
            'width'    => $a['width'],
            'height'   => $a['height'],
            'tips'     => $a['validation']['issues'] ?? [],
            'used'     => (int) $a['used_count'],
            'drive'    => 'https://drive.google.com/file/d/' . rawurlencode((string) $a['drive_file_id']) . '/view',
        ], (new SocialLibraryService())->assets());

        return $this->respondSuccess(['assets' => $assets, 'total' => count($assets)], 'Library loaded.');
    }

    /** POST /api/v1/social/plans  {category, brief, days, start_date, post_time, tone, language, accounts[]}: AI writes a multi-day plan (nothing is posted). */
    public function createPlan(): ResponseInterface
    {
        return $this->fromService((new SocialContentPlanner())->createPlan($this->getJsonPayload(), $this->currentUserId() ?: null));
    }

    /** GET /api/v1/social/plans/{id} */
    public function showPlan(string $id): ResponseInterface
    {
        return $this->fromService((new SocialContentPlanner())->show((int) $id));
    }

    /** POST /api/v1/social/plans/items/{id}/image: AI picture for one planned post. */
    public function planImage(string $id): ResponseInterface
    {
        return $this->fromService((new SocialContentPlanner())->generateImage((int) $id));
    }

    /** POST /api/v1/social/plans/{id}/approve  {confirm: true}: schedules every post of the plan. */
    public function approvePlan(string $id): ResponseInterface
    {
        if (empty($this->getJsonPayload()['confirm'])) {
            return $this->respondError('This schedules real posts. Repeat with {"confirm": true}.', 409);
        }

        return $this->fromService((new SocialContentPlanner())->approve((int) $id, $this->currentUserId() ?: null));
    }

    /** POST /api/v1/social/media  {image_base64 | image_url, filename} */
    public function uploadMedia(): ResponseInterface
    {
        $in  = $this->getJsonPayload();
        $svc = new SocialLibraryService();
        if (! empty($in['image_url'])) {
            return $this->fromService($svc->addImageFromUrl((string) $in['image_url'], (string) ($in['filename'] ?? '')));
        }
        $bytes = base64_decode(preg_replace('#^data:image/\w+;base64,#', '', (string) ($in['image_base64'] ?? '')) ?? '', true);

        return $this->fromService($svc->addImage($bytes === false ? '' : $bytes, (string) ($in['filename'] ?? 'picture')));
    }

    /** POST /api/v1/social/ai/image  {prompt}: the app's image AI makes a picture and adds it to the library. */
    public function aiImage(): ResponseInterface
    {
        return $this->fromService((new SocialLibraryService())->generateImage(trim((string) ($this->getJsonPayload()['prompt'] ?? ''))));
    }

    /**
     * POST /api/v1/social/publish  {asset_id | image_url, accounts[], caption, hashtags, when?}
     * One call: picture (from the library or a public link) to accounts, at `when` ("YYYY-MM-DD HH:MM") or in 2 minutes.
     */
    public function publish(): ResponseInterface
    {
        $in      = $this->getJsonPayload();
        $assetId = (int) ($in['asset_id'] ?? 0);
        if ($assetId < 1 && ! empty($in['image_url'])) {
            $up = (new SocialLibraryService())->addImageFromUrl((string) $in['image_url']);
            if (! $up['ok']) {
                return $this->fromService($up);
            }
            $assetId = (int) $up['data']['asset_id'];
        }
        if ($assetId < 1) {
            return $this->respondError('Send asset_id or a public image_url.', 422);
        }

        $when = trim((string) ($in['when'] ?? ''));
        $at   = $when !== '' ? DateTimeImmutable::createFromFormat('Y-m-d H:i', $when, AppDateTime::appZone()) : (new DateTimeImmutable('now', AppDateTime::appZone()))->modify('+2 minutes');
        if ($at === false) {
            return $this->respondError('when must look like 2026-11-05 10:30.', 422);
        }

        return $this->fromService((new SocialScheduleService())->createBulk(
            [$assetId],
            array_map('intval', (array) ($in['accounts'] ?? [])),
            $at->format('Y-m-d'),
            [$at->format('H:i')],
            trim((string) ($in['caption'] ?? '')),
            trim((string) ($in['hashtags'] ?? '')),
            $this->currentUserId() ?: null
        ));
    }

    /** GET /api/v1/social/posts?from=YYYY-MM-DD&to=YYYY-MM-DD */
    public function posts(): ResponseInterface
    {
        $from = (string) ($this->request->getGet('from') ?: date('Y-m-d'));
        $to   = (string) ($this->request->getGet('to') ?: date('Y-m-d', strtotime('+30 days')));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) !== 1) {
            return $this->respondValidationError(['range' => 'from and to must be YYYY-MM-DD.']);
        }

        return $this->respondSuccess((new SocialScheduleService())->calendar($from, $to), 'Posts loaded.');
    }

    /** POST /api/v1/social/text-post  {accounts:[id], when:"Y-m-d H:i", caption, hashtags} */
    public function textPost(): ResponseInterface
    {
        $in = $this->getJsonPayload();

        return $this->fromService((new SocialScheduleService())->createText(
            array_map('intval', (array) ($in['accounts'] ?? [])),
            (string) ($in['when'] ?? ''),
            trim((string) ($in['caption'] ?? '')),
            trim((string) ($in['hashtags'] ?? '')),
            $this->currentUserId() ?: null
        ));
    }

    /** POST /api/v1/social/schedule  {assets:[id], accounts:[id], start_date, times:["HH:MM"], caption, hashtags} */
    public function schedule(): ResponseInterface
    {
        $in = $this->getJsonPayload();

        return $this->fromService((new SocialScheduleService())->createBulk(
            array_map('intval', (array) ($in['assets'] ?? [])),
            array_map('intval', (array) ($in['accounts'] ?? [])),
            (string) ($in['start_date'] ?? ''),
            array_map('strval', (array) ($in['times'] ?? [])),
            trim((string) ($in['caption'] ?? '')),
            trim((string) ($in['hashtags'] ?? '')),
            $this->currentUserId() ?: null,
            [],
            (array) ($in['captions'] ?? [])
        ));
    }

    /** POST /api/v1/social/targets/{id}/{cancel|retry|delete|reschedule} */
    public function target(string $id, string $action): ResponseInterface
    {
        $svc = new SocialScheduleService();
        $id  = (int) $id;

        return match ($action) {
            'cancel'     => $this->fromService($svc->cancel($id)),
            'retry'      => $this->fromService($svc->retry($id)),
            'delete'     => $this->fromService($svc->delete($id, false)),
            'reschedule' => $this->fromService($svc->reschedule($id, (string) ($this->getJsonPayload()['when'] ?? ''))),
            default      => $this->respondError('Unknown action.', 404),
        };
    }

    /** POST /api/v1/social/ai/captions  {topic, platform, tone, language} */
    public function captions(): ResponseInterface
    {
        $in = $this->getJsonPayload();

        return $this->fromService((new SocialCaptionService())->suggest(
            (string) ($in['topic'] ?? ''),
            (string) ($in['platform'] ?? 'instagram'),
            (string) ($in['tone'] ?? ''),
            (string) ($in['language'] ?? '')
        ));
    }

    /**
     * @param array<string, mixed> $r service result
     */
    private function fromService(array $r): ResponseInterface
    {
        if ($r['ok']) {
            return $this->respondSuccess($r['data'] ?? null, (string) $r['message']);
        }

        return $this->respondError((string) $r['message'], (int) max(400, (int) $r['status']));
    }
}
