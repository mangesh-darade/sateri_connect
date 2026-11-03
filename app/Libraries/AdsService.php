<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\AdCampaignModel;
use App\Models\AdCreativeModel;
use Config\Services;
use Throwable;

/**
 * Creates, launches, pauses and ends Click-to-WhatsApp campaigns on Meta (Marketing API).
 *
 * Money rules (do not loosen): everything is created PAUSED first; launching needs the tenant's
 * daily-budget cap (AdsBudgetGuard) and a creative with no [placeholder] left; nothing is retried
 * blindly; AI never changes spend.
 *
 * Two modes, setting `ads_mode`: `mock` (default) fakes Meta with local ids so the whole flow can be
 * shown and tested; `live` calls Meta with the connection saved in Ads settings. The live path
 * has NOT been run against Meta yet (needs approved ads_management access and a test ad account).
 * Live v1 limits: targeting is countries, age and gender (cities and interests need Meta targeting
 * search); the ad must boost a published post (`post_external_id`).
 */
class AdsService
{
    private const GRAPH = 'https://graph.facebook.com/v21.0';

    private AdCampaignModel $campaigns;
    private AdCreativeModel $creatives;
    private SettingsService $settings;

    public function __construct(?SettingsService $settings = null)
    {
        $this->settings  = $settings ?? service('settingsService');
        $this->campaigns = new AdCampaignModel();
        $this->creatives = new AdCreativeModel();
    }

    public function isLive(): bool
    {
        return strtolower((string) $this->settings->get('ads_mode', 'mock')) === 'live';
    }

    /**
     * Campaigns, newest first, with the totals synced from Meta (empty until `ads:sync` runs).
     *
     * @return list<array<string, mixed>>
     */
    public function listWithMetrics(int $limit = 100): array
    {
        return db_connect()->query(
            'SELECT c.id, c.name, c.status, c.is_mock, c.daily_budget_minor, c.currency, c.starts_at, c.ends_at, c.last_error, c.created_at,
                    COALESCE(SUM(m.spend_minor), 0) AS spend_minor,
                    COALESCE(SUM(m.conversations), 0) AS conversations
               FROM ad_campaigns c
               LEFT JOIN ad_metrics_daily m ON m.campaign_id = c.id
              GROUP BY c.id, c.name, c.status, c.is_mock, c.daily_budget_minor, c.currency, c.starts_at, c.ends_at, c.last_error, c.created_at
              ORDER BY c.id DESC
              LIMIT ' . max(1, min(500, $limit))
        )->getResultArray();
    }

    /**
     * What the review screen shows before Launch.
     *
     * @return array{ok: bool, status: int, message: string, data?: array<string, mixed>}
     */
    public function summary(int $campaignId): array
    {
        $c = $this->campaigns->find($campaignId);
        if (! $c) {
            return $this->err(404, 'Campaign not found.');
        }
        $creative = $this->selectedCreative($campaignId);
        $cap      = (new AdsBudgetGuard($this->settings))->capMinor();

        return $this->ok('OK', [
            'campaign'        => $c,
            'creative'        => $creative,
            'mode'            => $this->isLive() ? 'live' : 'mock',
            'daily_budget'    => (int) $c['daily_budget_minor'],
            'cap_minor'       => $cap,
            'max_total_minor' => self::estimateMaxTotal($c),
            'problems'        => $this->launchProblems($c, $creative),
        ]);
    }

    /**
     * Create on Meta (paused) if needed, then activate. One call for the wizard's Launch button.
     * Needs `ads.activate`, which the controller checks.
     *
     * @return array{ok: bool, status: int, message: string, data?: array<string, mixed>}
     */
    public function launch(int $campaignId, ?int $userId): array
    {
        $c = $this->campaigns->find($campaignId);
        if (! $c) {
            return $this->err(404, 'Campaign not found.');
        }
        if (in_array($c['status'], ['active', 'ended'], true)) {
            return $this->err(409, 'This campaign is already ' . $c['status'] . '.');
        }

        $creative = $this->selectedCreative($campaignId);
        if ($problems = $this->launchProblems($c, $creative)) {
            return $this->err(422, $problems[0]);
        }

        $live = $this->isLive();
        $conn = $live ? $this->connection() : null;
        if ($live && $conn === null) {
            return $this->err(422, 'Connect your ad account in Ads settings first.');
        }

        $created = $conn !== null ? $this->createLive($c, $creative, $conn) : $this->createMock($c);
        if (! $created['ok']) {
            $this->campaigns->update($campaignId, ['status' => 'error', 'last_error' => mb_substr($created['message'], 0, 255)]);
            log_activity('launch_failed', 'ads', 'Ad campaign could not be launched', ['campaign_id' => $campaignId]);

            return $created;
        }

        $c = $this->campaigns->find($campaignId);
        if ($conn !== null) {
            $act = $this->setStatus($c, 'ACTIVE', $conn['token']);
            if (! $act['ok']) {
                $this->campaigns->update($campaignId, ['status' => 'paused', 'last_error' => mb_substr($act['message'], 0, 255)]);

                return $act;
            }
        }

        $this->campaigns->update($campaignId, ['status' => 'active', 'approved_by' => $userId, 'last_error' => null]);
        log_activity('launch', 'ads', 'Ad campaign launched', ['campaign_id' => $campaignId, 'daily_budget_minor' => (int) $c['daily_budget_minor'], 'mode' => $live ? 'live' : 'mock']);

        return $this->ok($live ? 'Campaign launched.' : 'Campaign launched in test mode (nothing was sent to Meta).', ['id' => $campaignId]);
    }

    /** @return array{ok: bool, status: int, message: string} */
    public function pause(int $campaignId): array
    {
        return $this->stop($campaignId, 'paused', 'Campaign paused.', 'pause');
    }

    /** @return array{ok: bool, status: int, message: string} */
    public function end(int $campaignId): array
    {
        return $this->stop($campaignId, 'ended', 'Campaign ended.', 'end');
    }

    /**
     * Pure helpers, kept public so they can be tested without Meta or a database.
     */

    /**
     * Upper bound of spend: daily budget x days. Meta may spend up to 25% above the daily budget on
     * a single day, so the total is never promised lower than this. Null when there is no end date.
     *
     * @param array<string, mixed> $campaign
     */
    public static function estimateMaxTotal(array $campaign): ?int
    {
        $start = strtotime((string) ($campaign['starts_at'] ?? '')) ?: time();
        $end   = strtotime((string) ($campaign['ends_at'] ?? ''));
        if (! $end || $end <= $start) {
            return null;
        }
        $days = (int) ceil(($end - $start) / 86400);

        return $days * (int) ($campaign['daily_budget_minor'] ?? 0);
    }

    /**
     * Meta targeting from a saved audience. Countries must be ISO codes (names are resolved by the caller).
     *
     * @param array<string, mixed> $audience
     * @param list<string>         $countryCodes
     *
     * @return array<string, mixed>
     */
    public static function buildTargeting(array $audience, array $countryCodes): array
    {
        $targeting = [
            'geo_locations' => ['countries' => array_values(array_unique($countryCodes))],
            'age_min'       => max(18, (int) ($audience['age_min'] ?? 18)),
            'age_max'       => min(65, (int) ($audience['age_max'] ?? 65)),
        ];
        $gender = (string) ($audience['gender'] ?? 'all');
        if ($gender === 'male') {
            $targeting['genders'] = [1];
        } elseif ($gender === 'female') {
            $targeting['genders'] = [2];
        }

        return $targeting;
    }

    /**
     * @param array<string, mixed> $campaign
     * @param array<string, mixed> $targeting
     *
     * @return array<string, mixed> Ad set parameters, created PAUSED.
     */
    public static function buildAdSetParams(array $campaign, string $metaCampaignId, array $targeting, string $pageId, string $whatsappNumber): array
    {
        $params = [
            'name'              => mb_substr((string) $campaign['name'], 0, 150),
            'campaign_id'       => $metaCampaignId,
            'daily_budget'      => (int) $campaign['daily_budget_minor'],
            'billing_event'     => 'IMPRESSIONS',
            'optimization_goal' => 'CONVERSATIONS',
            'destination_type'  => 'WHATSAPP',
            'bid_strategy'      => 'LOWEST_COST_WITHOUT_CAP',
            'promoted_object'   => json_encode(['page_id' => $pageId, 'whatsapp_phone_number' => $whatsappNumber]),
            'targeting'         => json_encode($targeting),
            'status'            => 'PAUSED',
        ];
        if (! empty($campaign['starts_at'])) {
            $params['start_time'] = gmdate('c', (int) strtotime((string) $campaign['starts_at']));
        }
        if (! empty($campaign['ends_at'])) {
            $params['end_time'] = gmdate('c', (int) strtotime((string) $campaign['ends_at']));
        }

        return $params;
    }

    /**
     * Problems that stop a launch, most important first. Empty when it is safe to go on.
     *
     * @param array<string, mixed>      $campaign
     * @param array<string, mixed>|null $creative
     *
     * @return list<string>
     */
    private function launchProblems(array $campaign, ?array $creative): array
    {
        $problems = [];
        $budget   = (new AdsBudgetGuard($this->settings))->check((int) $campaign['daily_budget_minor']);
        if (! $budget['ok']) {
            $problems[] = $budget['message'];
        }
        if ($creative === null) {
            $problems[] = 'Pick the ad text to run.';
        } elseif (preg_match('/\[[^\]]+\]/', $creative['headline'] . ' ' . $creative['primary_text'])) {
            $problems[] = 'Replace the [placeholder] text in your ad before launching.';
        }
        $audience = json_decode((string) ($campaign['audience'] ?? ''), true);
        if (! is_array($audience) || (empty($audience['countries']) && empty($audience['cities']))) {
            $problems[] = 'Choose where the ad should be shown.';
        }
        if ($this->isLive() && $creative !== null && empty($creative['post_external_id'])) {
            $problems[] = 'Pick a published post to boost. Ads from a new photo are not supported yet.';
        }

        return $problems;
    }

    /**
     * @param array<string, mixed> $c
     *
     * @return array{ok: bool, status: int, message: string}
     */
    private function createMock(array $c): array
    {
        if (empty($c['meta_campaign_id'])) {
            $this->campaigns->update((int) $c['id'], [
                'is_mock'          => 1,
                'meta_campaign_id' => 'mock_c_' . $c['id'],
                'meta_adset_id'    => 'mock_s_' . $c['id'],
                'meta_ad_id'       => 'mock_a_' . $c['id'],
            ]);
        }

        return $this->ok('OK');
    }

    /**
     * Each step is skipped when its id is already stored, so a failed launch continues where it stopped.
     *
     * @param array<string, mixed> $c
     * @param array<string, mixed> $creative
     * @param array{account: string, page: string, whatsapp: string, token: string} $conn
     *
     * @return array{ok: bool, status: int, message: string}
     */
    private function createLive(array $c, array $creative, array $conn): array
    {
        $id  = (int) $c['id'];
        $act = str_starts_with($conn['account'], 'act_') ? $conn['account'] : 'act_' . $conn['account'];

        if (empty($c['meta_campaign_id'])) {
            $special = $c['special_category'] ? [strtoupper((string) $c['special_category'])] : [];
            $r       = $this->graph('POST', $act . '/campaigns', $conn['token'], [
                'name'                  => mb_substr((string) $c['name'], 0, 150),
                'objective'             => 'OUTCOME_ENGAGEMENT',
                'status'                => 'PAUSED',
                'special_ad_categories' => json_encode($special),
            ]);
            if (! $r['ok']) {
                return $r;
            }
            $c['meta_campaign_id'] = (string) ($r['data']['id'] ?? '');
            $this->campaigns->update($id, ['is_mock' => 0, 'meta_campaign_id' => $c['meta_campaign_id'], 'ad_account_id' => $conn['account']]);
        }

        if (empty($c['meta_adset_id'])) {
            $audience  = json_decode((string) $c['audience'], true) ?: [];
            $countries = $this->countryCodes((array) ($audience['countries'] ?? []));
            if ($countries === []) {
                return $this->err(422, 'Choose at least one country for the ad.');
            }
            $r = $this->graph('POST', $act . '/adsets', $conn['token'], self::buildAdSetParams(
                $c,
                $c['meta_campaign_id'],
                self::buildTargeting($audience, $countries),
                $conn['page'],
                $conn['whatsapp']
            ));
            if (! $r['ok']) {
                return $r;
            }
            $c['meta_adset_id'] = (string) ($r['data']['id'] ?? '');
            $this->campaigns->update($id, ['meta_adset_id' => $c['meta_adset_id']]);
        }

        if (empty($c['meta_ad_id'])) {
            $cr = $this->graph('POST', $act . '/adcreatives', $conn['token'], [
                'name'            => mb_substr((string) $c['name'], 0, 100),
                'object_story_id' => (string) $creative['post_external_id'],
            ]);
            if (! $cr['ok']) {
                return $cr;
            }
            $ad = $this->graph('POST', $act . '/ads', $conn['token'], [
                'name'     => mb_substr((string) $c['name'], 0, 100),
                'adset_id' => $c['meta_adset_id'],
                'creative' => json_encode(['creative_id' => (string) ($cr['data']['id'] ?? '')]),
                'status'   => 'PAUSED',
            ]);
            if (! $ad['ok']) {
                return $ad;
            }
            $this->campaigns->update($id, ['meta_ad_id' => (string) ($ad['data']['id'] ?? '')]);
        }

        return $this->ok('OK');
    }

    /**
     * @param array<string, mixed> $c
     *
     * @return array{ok: bool, status: int, message: string}
     */
    private function setStatus(array $c, string $status, string $token): array
    {
        foreach (['meta_ad_id', 'meta_adset_id', 'meta_campaign_id'] as $field) {
            if (empty($c[$field])) {
                continue;
            }
            $r = $this->graph('POST', (string) $c[$field], $token, ['status' => $status]);
            if (! $r['ok']) {
                return $r;
            }
        }

        return $this->ok('OK');
    }

    /** @return array{ok: bool, status: int, message: string} */
    private function stop(int $campaignId, string $newStatus, string $message, string $action): array
    {
        $c = $this->campaigns->find($campaignId);
        if (! $c) {
            return $this->err(404, 'Campaign not found.');
        }
        if ($c['status'] === 'ended') {
            return $this->err(409, 'This campaign has already ended.');
        }

        if (! (int) $c['is_mock'] && ! empty($c['meta_campaign_id'])) {
            $conn = $this->connection();
            if ($conn === null) {
                return $this->err(422, 'Connect your ad account in Ads settings first.');
            }
            $r = $this->graph('POST', (string) $c['meta_campaign_id'], $conn['token'], ['status' => 'PAUSED']);
            if (! $r['ok']) {
                return $r;
            }
        }

        $this->campaigns->update($campaignId, ['status' => $newStatus]);
        log_activity($action, 'ads', 'Ad campaign ' . $newStatus, ['campaign_id' => $campaignId]);

        return $this->ok($message);
    }

    /** @return array<string, mixed>|null */
    private function selectedCreative(int $campaignId): ?array
    {
        $row = $this->creatives->where('campaign_id', $campaignId)->where('is_selected', 1)->first();

        return $row ?: null;
    }

    /**
     * Read from Meta (GET), for the sync job.
     *
     * @param array<string, mixed> $params
     *
     * @return array{ok: bool, status: int, message: string, data: array<string, mixed>}
     */
    public function read(string $path, string $token, array $params = []): array
    {
        return $this->graph('GET', $path, $token, $params);
    }

    /**
     * @return array{account: string, page: string, whatsapp: string, token: string}|null
     */
    public function connection(): ?array
    {
        $conn = [
            'account'  => trim((string) $this->settings->get('ads_meta_ad_account_id', '')),
            'page'     => trim((string) $this->settings->get('ads_meta_page_id', '')),
            'whatsapp' => preg_replace('/\D+/', '', (string) $this->settings->get('ads_meta_whatsapp_number', '')),
            'token'    => trim((string) $this->settings->get('ads_meta_access_token', '')),
        ];

        return in_array('', $conn, true) ? null : $conn;
    }

    /**
     * @param list<string> $names ISO codes or country names
     *
     * @return list<string>
     */
    private function countryCodes(array $names): array
    {
        $codes = [];
        foreach ($names as $n) {
            $n = trim((string) $n);
            if (preg_match('/^[A-Za-z]{2}$/', $n)) {
                $codes[] = strtoupper($n);
                continue;
            }
            $row = db_connect()->table('countries')->select('iso2')->where('name', $n)->get()->getRowArray();
            if ($row) {
                $codes[] = strtoupper((string) $row['iso2']);
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * One Graph call. Raw provider errors are logged, never shown. Not retried: a failed step is shown
     * to the user and the launch can be run again to continue.
     *
     * @param array<string, mixed> $params
     *
     * @return array{ok: bool, status: int, message: string, data: array<string, mixed>}
     */
    protected function graph(string $method, string $path, string $token, array $params = []): array
    {
        try {
            $client   = Services::curlrequest(['timeout' => 60, 'http_errors' => false], null, null, false);
            $response = $client->request($method, self::GRAPH . '/' . ltrim($path, '/'), [
                'headers'                                  => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
                strtoupper($method) === 'GET' ? 'query' : 'form_params' => $params,
            ]);
            $status = $response->getStatusCode();
            $body   = json_decode((string) $response->getBody(), true);
            $body   = is_array($body) ? $body : [];

            if ($status >= 200 && $status < 300 && ! isset($body['error'])) {
                return ['ok' => true, 'status' => $status, 'message' => 'OK', 'data' => $body];
            }

            $err = MetaApiErrorMapper::parseDecoded($body);
            log_message('error', 'AdsService::graph failed path=' . preg_replace('/\d{6,}/', '#', $path) . ' http=' . $status . ' code=' . (int) ($err['code'] ?? 0));

            return ['ok' => false, 'status' => $status >= 400 ? $status : 502, 'message' => $this->friendly($body, $status), 'data' => []];
        } catch (Throwable $e) {
            log_message('error', 'AdsService::graph exception ' . $e::class);

            return ['ok' => false, 'status' => 502, 'message' => 'Could not reach Meta. Please try again.', 'data' => []];
        }
    }

    /**
     * Plain-language reason for common Meta ad errors. Anything else gets a generic message.
     *
     * @param array<string, mixed> $body
     */
    private function friendly(array $body, int $status): string
    {
        $code = (int) ($body['error']['code'] ?? 0);

        return match (true) {
            $status === 401, $code === 190 => 'The Meta access token is invalid or expired. Reconnect your ad account in Ads settings.',
            $status === 403, $code === 10, $code === 200, $code === 294 => 'Your Meta account does not have permission to run ads. Check ad account access in Ads settings.',
            $code === 2635, $code === 1487851 => 'Meta rejected this ad. Review the text and the audience and try again.',
            $code === 4, $code === 17, $code === 32, $code === 613 => 'Meta rate limit reached. Try again in a few minutes.',
            default => 'Meta could not create the ad. Check your ad account settings and try again.',
        };
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{ok: bool, status: int, message: string, data?: array<string, mixed>}
     */
    private function ok(string $message, array $data = []): array
    {
        return ['ok' => true, 'status' => 200, 'message' => $message] + ($data !== [] ? ['data' => $data] : []);
    }

    /** @return array{ok: bool, status: int, message: string} */
    private function err(int $status, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'message' => $message];
    }
}
