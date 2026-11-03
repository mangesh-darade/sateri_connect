<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\AdCampaignModel;
use Throwable;

/**
 * Step 1 of the ads wizard: reads the client's website and works out what the AI does not need to
 * ask (campaign name, budget, audience) from past campaigns and the contact list.
 *
 * The page fetch is SSRF-guarded: https only, every redirect hop is re-validated, the connection is
 * pinned to the validated IP and the download is size-capped.
 */
class AdsBriefService
{
    private const MAX_BYTES     = 1500000;
    private const MAX_REDIRECTS = 3;
    private const DEFAULT_BUDGET_MINOR = 50000;

    /**
     * Facts about the page for the AI planner.
     *
     * @return array{ok: bool, status: int, message: string, data?: array{url: string, title: string, description: string, lang: string, headings: list<string>, text: string}}
     */
    public function readPage(string $url): array
    {
        $url = trim($url);
        if ($url !== '' && ! preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = 'https://' . $url;
        }

        try {
            $current = $url;
            $html    = '';
            for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
                if (($err = OutgoingWebhooks::validateUrl($current)) !== null) {
                    return ['ok' => false, 'status' => 422, 'message' => $err];
                }
                $res = $this->fetch($current);
                if ($res['redirect'] !== null) {
                    $current = $this->absolute($current, $res['redirect']);
                    continue;
                }
                if ($res['http'] < 200 || $res['http'] >= 300) {
                    return ['ok' => false, 'status' => 502, 'message' => 'We could not open that page. Check the link and try again.'];
                }
                $html = $res['body'];
                break;
            }
            if ($html === '') {
                return ['ok' => false, 'status' => 502, 'message' => 'We could not read that page. Check the link and try again.'];
            }

            return ['ok' => true, 'status' => 200, 'message' => 'OK', 'data' => ['url' => $current] + $this->extract($html)];
        } catch (Throwable $e) {
            log_message('error', 'AdsBriefService::readPage failed: ' . $e->getMessage());

            return ['ok' => false, 'status' => 502, 'message' => 'We could not read that page. Check the link and try again.'];
        }
    }

    /**
     * What can be pre-filled for the wizard. Never opens the network.
     *
     * @return array{ok: bool, status: int, message: string, data: array{name: string, daily_budget_minor: int, cap_minor: int, audience: ?array<string, mixed>, audience_source: string, based_on: ?array{id: int, name: string, cost_per_conversation_minor: int}, countries: list<string>}}
     */
    public function suggestDefaults(?string $pageTitle = null): array
    {
        $cap      = (new AdsBudgetGuard())->capMinor();
        $best     = $this->bestCampaign();
        $budget   = $best !== null && (int) $best['daily_budget_minor'] > 0 ? (int) $best['daily_budget_minor'] : self::DEFAULT_BUDGET_MINOR;
        $budget   = $cap > 0 ? min($budget, $cap) : 0;
        $audience = null;
        $source   = 'none';
        $basedOn  = null;

        if ($best !== null) {
            $decoded = json_decode((string) $best['audience'], true);
            if (is_array($decoded) && $decoded !== []) {
                $audience = $decoded;
                $source   = 'past_campaign';
                $basedOn  = [
                    'id'                          => (int) $best['id'],
                    'name'                        => (string) $best['name'],
                    'cost_per_conversation_minor' => (int) ($best['cost_per_conversation_minor'] ?? 0),
                ];
            }
        }

        $countries = $this->topCountries();
        if ($audience === null && $countries !== []) {
            $audience = ['countries' => $countries];
            $source   = 'contacts';
        }

        return [
            'ok'      => true,
            'status'  => 200,
            'message' => 'OK',
            'data'    => [
                'name'               => $this->suggestName($pageTitle),
                'daily_budget_minor' => $budget,
                'cap_minor'          => $cap,
                'audience'           => $audience,
                'audience_source'    => $source,
                'based_on'           => $basedOn,
                'countries'          => $countries,
            ],
        ];
    }

    /**
     * Page facts plus pre-fill suggestions in one call (the wizard's "Continue" button).
     *
     * @return array{ok: bool, status: int, message: string, data?: array<string, mixed>}
     */
    public function build(string $url): array
    {
        $page = $this->readPage($url);
        if (! $page['ok']) {
            return $page;
        }
        $defaults = $this->suggestDefaults($page['data']['title']);

        return ['ok' => true, 'status' => 200, 'message' => 'OK', 'data' => ['page' => $page['data'], 'defaults' => $defaults['data']]];
    }

    public function suggestName(?string $pageTitle): string
    {
        $base = trim((string) $pageTitle);
        $base = trim((string) preg_split('/\s[|\-–—:]\s/u', $base, 2)[0]);
        $base = $base !== '' ? mb_substr($base, 0, 60) : 'New campaign';

        return $base . ' - ' . date('M Y');
    }

    /**
     * Past campaign worth copying: lowest cost per chat (at least 5 chats), else the latest one that
     * actually ran. Needs a saved audience.
     *
     * @return array<string, mixed>|null
     */
    private function bestCampaign(): ?array
    {
        $db = db_connect();
        if (! $db->tableExists('ad_campaigns')) {
            return null;
        }

        $row = $db->query(
            "SELECT c.id, c.name, c.audience, c.daily_budget_minor,
                    ROUND(SUM(m.spend_minor) / SUM(m.conversations)) AS cost_per_conversation_minor
               FROM ad_campaigns c
               JOIN ad_metrics_daily m ON m.campaign_id = c.id
              WHERE c.audience IS NOT NULL AND c.audience <> ''
              GROUP BY c.id, c.name, c.audience, c.daily_budget_minor
             HAVING SUM(m.conversations) >= 5
              ORDER BY cost_per_conversation_minor ASC
              LIMIT 1"
        )->getRowArray();
        if ($row) {
            return $row;
        }

        $row = (new AdCampaignModel())
            ->where('audience IS NOT NULL', null, false)
            ->where('audience <>', '')
            ->whereIn('status', ['active', 'paused', 'ended'])
            ->orderBy('id', 'DESC')
            ->first();

        return $row ?: null;
    }

    /**
     * @return list<string> Up to 3 countries most contacts are from.
     */
    private function topCountries(): array
    {
        $rows = db_connect()->query(
            "SELECT country, COUNT(*) AS n FROM contacts
              WHERE deleted_at IS NULL AND country IS NOT NULL AND country <> ''
              GROUP BY country ORDER BY n DESC LIMIT 3"
        )->getResultArray();

        return array_values(array_map(static fn (array $r): string => (string) $r['country'], $rows));
    }

    /**
     * @return array{http: int, body: string, redirect: ?string}
     */
    private function fetch(string $url): array
    {
        $p    = parse_url($url);
        $host = (string) ($p['host'] ?? '');
        $ip   = OutgoingWebhooks::publicIp($host);
        if ($ip === null) {
            return ['http' => 0, 'body' => '', 'redirect' => null];
        }

        $body = '';
        $ch   = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_RESOLVE        => [$host . ':443:' . $ip],
            CURLOPT_USERAGENT      => 'SateriConnect-AdsBrief/1',
            CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml'],
            CURLOPT_WRITEFUNCTION  => static function ($ch, string $chunk) use (&$body): int {
                $body .= $chunk;

                return strlen($body) > self::MAX_BYTES ? 0 : strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $http     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $redirect = $http >= 300 && $http < 400 ? (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL) : '';
        curl_close($ch);

        return ['http' => $http, 'body' => $body, 'redirect' => $redirect !== '' ? $redirect : null];
    }

    private function absolute(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $p = parse_url($base);

        return 'https://' . ($p['host'] ?? '') . '/' . ltrim($location, '/');
    }

    /**
     * @return array{title: string, description: string, lang: string, headings: list<string>, text: string}
     */
    private function extract(string $html): array
    {
        $dom = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $xp = new \DOMXPath($dom);

        $clean = static fn (string $s): string => trim((string) preg_replace('/\s+/u', ' ', $s));

        $title = $clean($xp->evaluate('string(//title)'));
        $desc  = $clean($xp->evaluate('string(//meta[@name="description"]/@content)'));
        if ($desc === '') {
            $desc = $clean($xp->evaluate('string(//meta[@property="og:description"]/@content)'));
        }
        $lang = strtolower(substr($clean($xp->evaluate('string(/html/@lang)')), 0, 5));

        $headings = [];
        foreach ($xp->query('//h1|//h2') as $h) {
            $t = $clean($h->textContent);
            if ($t !== '' && count($headings) < 8) {
                $headings[] = mb_substr($t, 0, 120);
            }
        }

        foreach (iterator_to_array($xp->query('//script|//style|//noscript|//nav|//footer')) as $n) {
            $n->parentNode?->removeChild($n);
        }
        $parts = [];
        foreach ($xp->query('//body//text()') as $node) {
            $parts[] = $node->nodeValue;
        }
        $text = mb_substr($clean(implode(' ', $parts)), 0, 2000);

        return [
            'title'       => mb_substr($title, 0, 150),
            'description' => mb_substr($desc, 0, 300),
            'lang'        => $lang,
            'headings'    => $headings,
            'text'        => $text,
        ];
    }
}
