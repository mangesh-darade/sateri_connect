<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\AdsBriefService;
use App\Libraries\AdsPlannerService;
use App\Libraries\AdsService;
use App\Libraries\AdsSettings;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Ads: the guided wizard (brief, generate, launch), the campaign list and ad settings.
 * Permissions: ads.view (list), ads.create (wizard + drafts), ads.activate (launch, pause, end),
 * ads.accounts (settings and the daily budget limit).
 */
class Ads extends BaseController
{
    public function index(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('ads.view')) {
            return $denied;
        }

        $ads = new AdsService();

        return $this->render('ads/index', [
            'pageTitle' => 'Ads',
            'campaigns' => $ads->listWithMetrics(),
            'mode'      => $ads->isLive() ? 'live' : 'mock',
        ]);
    }

    public function create(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('ads.create')) {
            return $denied;
        }

        $settings = (new AdsSettings())->current();

        return $this->render('ads/wizard', [
            'pageTitle' => 'New ad campaign',
            'mode'      => $settings['mode'],
            'cap'       => $settings['cap'],
        ]);
    }

    /** Step 1: read the website and pre-fill the rest. */
    public function brief(): ResponseInterface
    {
        if ($denied = $this->requirePermission('ads.create')) {
            return $denied;
        }

        $res = (new AdsBriefService())->build((string) ($this->requestInput()['url'] ?? ''));

        return $this->respond($res);
    }

    /** Step 2: AI writes the ad variants and the audience. */
    public function generate(): ResponseInterface
    {
        if ($denied = $this->requirePermission('ads.create')) {
            return $denied;
        }

        $in   = $this->requestInput();
        $page = json_decode((string) ($in['page'] ?? ''), true);
        $hint = json_decode((string) ($in['audience_hint'] ?? ''), true);

        $res = (new AdsPlannerService())->plan(is_array($page) ? $page : [], [
            'language'      => (string) ($in['language'] ?? ''),
            'city'          => (string) ($in['city'] ?? ''),
            'audience_hint' => is_array($hint) ? $hint : null,
        ]);

        return $this->respond($res);
    }

    /** Save what the user edited as a draft. Nothing is sent to Meta. */
    public function saveDraft(): ResponseInterface
    {
        if ($denied = $this->requirePermission('ads.create')) {
            return $denied;
        }

        $in   = $this->requestInput();
        $plan = json_decode((string) ($in['plan'] ?? ''), true);
        $page = json_decode((string) ($in['page'] ?? ''), true);

        $res = (new AdsPlannerService())->saveDraft([
            'name'               => (string) ($in['name'] ?? ''),
            'website_url'        => (string) ($in['url'] ?? ''),
            'daily_budget_minor' => (int) round(((float) str_replace(',', '', (string) ($in['daily_budget'] ?? '0'))) * 100),
            'starts_at'          => $this->date((string) ($in['starts_on'] ?? '')),
            'ends_at'            => $this->date((string) ($in['ends_on'] ?? '')),
            'brief'              => is_array($page) ? ['title' => (string) ($page['title'] ?? ''), 'language' => (string) ($in['language'] ?? ''), 'city' => (string) ($in['city'] ?? '')] : null,
        ], is_array($plan) ? $plan : [], $this->userId());

        return $this->respond($res);
    }

    /** What the review step shows. */
    public function summary(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('ads.view')) {
            return $denied;
        }

        return $this->respond((new AdsService())->summary($id));
    }

    public function launch(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('ads.activate')) {
            return $denied;
        }

        return $this->respond((new AdsService())->launch($id, $this->userId()));
    }

    public function pause(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('ads.activate')) {
            return $denied;
        }

        return $this->respond((new AdsService())->pause($id));
    }

    public function end(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('ads.activate')) {
            return $denied;
        }

        return $this->respond((new AdsService())->end($id));
    }

    public function settings(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('ads.accounts')) {
            return $denied;
        }

        return $this->render('ads/settings', [
            'pageTitle' => 'Ad settings',
            'values'    => (new AdsSettings())->current(),
        ]);
    }

    public function saveSettings(): ResponseInterface
    {
        if ($denied = $this->requirePermission('ads.accounts')) {
            return $denied;
        }

        $res = (new AdsSettings())->save($this->requestInput());

        return $res['ok']
            ? $this->jsonResponse(true, null, $res['message'])
            : $this->jsonResponse(false, null, $res['message'], $res['errors'] ?? [], $res['status']);
    }

    /**
     * @param array{ok: bool, status: int, message: string, data?: mixed} $res service result
     */
    private function respond(array $res): ResponseInterface
    {
        return $res['ok']
            ? $this->jsonResponse(true, $res['data'] ?? null, $res['message'])
            : $this->jsonResponse(false, null, $res['message'], [], $res['status']);
    }

    private function date(string $value): ?string
    {
        $value = trim($value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false ? $value . ' 00:00:00' : null;
    }
}
