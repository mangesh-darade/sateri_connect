<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\SocialAccountService;
use App\Libraries\SocialAiClient;
use App\Libraries\SocialContentPlanner;
use App\Libraries\SocialImageGenerator;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * AI Content Planner: give a category and goal, Claude plans a week of posts, an image AI draws each picture,
 * the user reviews and approves, and the posts are scheduled like any other (docs/SOCIAL_SCHEDULER.md).
 */
class SocialPlanner extends BaseController
{
    public function index(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('social.view')) {
            return $denied;
        }

        $accounts = array_values(array_filter(
            (new SocialAccountService())->list(),
            static fn (array $a): bool => $a['status'] === 'active' && in_array($a['platform'], ['instagram', 'facebook'], true)
        ));
        $ai    = new SocialAiClient();
        $image = new SocialImageGenerator();

        return view('social/planner', [
            'pageTitle'  => 'AI Content Planner',
            'accounts'   => $accounts,
            'plans'      => (new SocialContentPlanner())->recentPlans(),
            'aiLabel'    => $ai->label(),
            'aiReady'    => $ai->isConfigured(),
            'imageLabel' => $image->label(),
            'imageReady' => $image->provider() !== '',
            'canEdit'    => can('social.create'),
            'maxDays'    => SocialContentPlanner::MAX_DAYS,
        ]);
    }

    public function create(): ResponseInterface
    {
        if ($denied = $this->requirePermission('social.create')) {
            return $denied;
        }
        session_write_close();

        return $this->respond((new SocialContentPlanner())->createPlan([
            'category'   => $this->request->getPost('category'),
            'brief'      => $this->request->getPost('brief'),
            'days'       => $this->request->getPost('days'),
            'start_date' => $this->request->getPost('start_date'),
            'post_time'  => $this->request->getPost('post_time'),
            'tone'       => $this->request->getPost('tone'),
            'language'   => $this->request->getPost('language'),
            'accounts'   => (array) $this->request->getPost('accounts'),
        ], session()->get('user_id') !== null ? (int) session()->get('user_id') : null));
    }

    /** One chat turn of "post something today": Claude asks a question or creates the one-post plan. */
    public function quick(): ResponseInterface
    {
        if ($denied = $this->requirePermission('social.create')) {
            return $denied;
        }
        session_write_close();

        $history = json_decode((string) $this->request->getPost('history'), true);

        return $this->respond((new SocialContentPlanner())->quick(
            is_array($history) ? $history : [],
            (string) $this->request->getPost('message'),
            array_map('intval', (array) $this->request->getPost('accounts')),
            session()->get('user_id') !== null ? (int) session()->get('user_id') : null
        ));
    }

    public function data(string|int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('social.view')) {
            return $denied;
        }

        return $this->respond((new SocialContentPlanner())->show((int) $id));
    }

    /** One picture per request so a week of pictures never sits in one slow call. */
    public function makeImage(string|int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('social.create')) {
            return $denied;
        }
        session_write_close();
        set_time_limit(180);

        $r = (new SocialContentPlanner())->generateImage((int) $id);
        if ($r['ok']) {
            log_activity('social_ai_plan_image', 'social', 'Made an AI picture for a plan post', ['item_id' => (int) $id]);
        }

        return $this->respond($r);
    }

    public function image(string|int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('social.view')) {
            return $denied;
        }
        $item = model(\App\Models\SocialAiItemModel::class)->find((int) $id);
        $file = is_array($item) ? (string) ($item['image_file'] ?? '') : '';
        if (preg_match('/^[a-f0-9]{32}\.(jpg|png)$/', $file) !== 1 || ! is_file(SocialImageGenerator::dir() . $file)) {
            return $this->response->setStatusCode(404)->setBody('No picture');
        }
        session_write_close();

        return $this->response
            ->setHeader('Content-Type', str_ends_with($file, '.png') ? 'image/png' : 'image/jpeg')
            ->setHeader('Cache-Control', 'private, max-age=600')
            ->setBody((string) file_get_contents(SocialImageGenerator::dir() . $file));
    }

    public function updateItem(string|int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('social.create')) {
            return $denied;
        }

        return $this->respond((new SocialContentPlanner())->updateItem((int) $id, [
            'topic'        => $this->request->getPost('topic'),
            'caption'      => $this->request->getPost('caption'),
            'hashtags'     => $this->request->getPost('hashtags'),
            'image_prompt' => $this->request->getPost('image_prompt'),
        ]));
    }

    public function rejectItem(string|int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('social.create')) {
            return $denied;
        }

        return $this->respond((new SocialContentPlanner())->reject((int) $id));
    }

    public function approve(string|int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('social.create')) {
            return $denied;
        }
        session_write_close();

        return $this->respond((new SocialContentPlanner())->approve(
            (int) $id,
            session()->get('user_id') !== null ? (int) session()->get('user_id') : null
        ));
    }

    /**
     * @param array<string, mixed> $r
     */
    private function respond(array $r): ResponseInterface
    {
        return $this->jsonResponse((bool) $r['ok'], $r['data'] ?? null, (string) $r['message'], [], (int) ($r['ok'] ? 200 : max(400, (int) $r['status'])));
    }
}
