<?php

declare(strict_types=1);

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * In-app guides from markdown + screenshots.
 */
class Guide extends BaseController
{
    /**
     * @var array<string, array{file: string, title: string, subtitle: string}>
     */
    protected array $guides = [
        'local' => [
            'file'     => 'GUIDE_LOCAL.md',
            'title'    => 'Local Guide',
            'subtitle' => 'Test on your PC with WAMP — step by step with screenshots.',
        ],
        'production' => [
            'file'     => 'GUIDE_PRODUCTION.md',
            'title'    => 'Production Guide',
            'subtitle' => 'Put the app live on a real server with HTTPS and your WhatsApp provider (External or Meta).',
        ],
        'meta-official' => [
            'file'     => 'META_OFFICIAL_DEVELOPER_TO_PUBLISH_GUIDE.md',
            'title'    => 'Meta Publish Guide',
            'subtitle' => 'Official Meta path from developer account to App Review, Advanced Access, Live mode, and production.',
        ],
        'meta-screenshots' => [
            'file'     => 'META_OFFICIAL_SCREENSHOT_GUIDE.md',
            'title'    => 'Meta Screenshot Guide',
            'subtitle' => 'Visual walkthrough for Meta setup, webhook validation, template sync, and go-live checks.',
        ],
        'automations' => [
            'file'     => '',
            'title'    => 'Automation Flows',
            'subtitle' => 'Catalog flows by trigger, condition, and action — active status and how to test on Meta.',
        ],
    ];

    public function index(): ResponseInterface
    {
        if ($denied = $this->denyGuide()) {
            return $denied;
        }

        return redirect()->to(site_url($this->guidePath('local')));
    }

    public function show(string $type = 'local'): string|ResponseInterface
    {
        if ($denied = $this->denyGuide()) {
            return $denied;
        }

        $type = strtolower($type);
        if (! isset($this->guides[$type])) {
            return redirect()->to(site_url($this->guidePath('local')));
        }

        if ($type === 'automations') {
            return $this->automationsGuide();
        }

        $meta = $this->guides[$type];
        $path = ROOTPATH . 'docs' . DIRECTORY_SEPARATOR . $meta['file'];

        if (! is_file($path)) {
            return $this->render('guide/index', $this->guideViewData([
                'pageTitle'  => $meta['title'],
                'subtitle'   => $meta['subtitle'],
                'guideType'  => $type,
                'guideNav'   => $this->guideTabs(),
                'guideTitle' => $meta['title'],
                'guideSub'   => $meta['subtitle'],
                'guideHtml'  => '<div class="alert alert-warning">Guide file not found (<code>docs/'
                    . esc($meta['file']) . '</code>).</div>',
                'toc'        => [],
            ]));
        }

        $markdown  = (string) file_get_contents($path);
        $imageBase = rtrim(base_url('assets/guide'), '/') . '/';

        $markdown = preg_replace_callback(
            '#\!\[([^\]]*)\]\(images/([^)\s]+)\)#',
            static function (array $m) use ($imageBase): string {
                $file = $m[2] ?? '';
                $published = ROOTPATH . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'guide'
                    . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file);
                $version = is_file($published) ? ('?v=' . (string) filemtime($published)) : '';

                return '![' . ($m[1] ?? '') . '](' . $imageBase . $file . $version . ')';
            },
            $markdown
        ) ?? $markdown;

        $markdown = preg_replace(
            '/```mermaid\n[\s\S]*?```/',
            "> *(See the roadmap screenshot below for the full flow.)*\n",
            $markdown
        ) ?? $markdown;

        return $this->render('guide/index', $this->guideViewData([
            'pageTitle'  => $meta['title'],
            'subtitle'   => $meta['subtitle'],
            'guideType'  => $type,
            'guideNav'   => $this->guideTabs(),
            'guideTitle' => $meta['title'],
            'guideSub'   => $meta['subtitle'],
            'guideHtml'  => $this->markdownToHtml($markdown),
            'toc'        => $this->extractToc($markdown),
        ]));
    }

    /**
     * Dynamic catalog of [Flow] automations with trigger + active status.
     */
    protected function automationsGuide(): string
    {
        $provider = 'cheerio';
        $flows    = [];
        try {
            $settings = new \App\Libraries\SettingsService();
            $provider = $settings->getWhatsAppProvider();
            $prefix   = \App\Commands\SeedAutomationCatalogFlows::PREFIX;
            $flows = model(\App\Models\AutomationModel::class)
                ->like('name', $prefix, 'after')
                ->orderBy('name', 'ASC')
                ->findAll();
        } catch (\Throwable $e) {
            log_message('warning', 'Automation guide catalog skipped: {msg}', ['msg' => $e->getMessage()]);
        }

        $stats = [
            'total'      => count($flows),
            'active'     => 0,
            'triggers'   => 0,
            'conditions' => 0,
            'actions'    => 0,
        ];
        foreach ($flows as $f) {
            if (! empty($f['is_active'])) {
                $stats['active']++;
            }
            $name = (string) ($f['name'] ?? '');
            if (str_contains($name, 'Trigger:')) {
                $stats['triggers']++;
            } elseif (str_contains($name, 'Condition:')) {
                $stats['conditions']++;
            } elseif (str_contains($name, 'Action:')) {
                $stats['actions']++;
            }
        }

        return $this->render('guide/automations', $this->guideViewData([
            'pageTitle'     => 'Automation Flows Guide',
            'subtitle'      => $this->guides['automations']['subtitle'],
            'guideType'     => 'automations',
            'provider'      => $provider,
            'providerLabel' => $provider === 'meta' ? 'Meta Cloud API' : 'External Direct API',
            'flows'         => $flows,
            'stats'         => $stats,
        ]));
    }

    protected function isPlatformGuide(): bool
    {
        return $this->request->getUri()->getSegment(1) === 'platform';
    }

    protected function guidePath(string $type): string
    {
        return ($this->isPlatformGuide() ? 'platform/guide/' : 'guide/') . $type;
    }

    /**
     * Workspace users need guide.view. Platform super admin is already behind platformAuth.
     */
    protected function denyGuide(): ?ResponseInterface
    {
        if ($this->isPlatformGuide() && session()->get('platform_admin_id')) {
            return null;
        }

        return $this->requirePermission('guide.view');
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function guideViewData(array $data): array
    {
        $data['platformGuide'] = $this->isPlatformGuide();
        $data['guideBase']     = $this->isPlatformGuide() ? 'platform/guide/' : 'guide/';
        $data['guideNav']      = $data['guideNav'] ?? $this->guideTabs();
        if (! $data['platformGuide']) {
            return $data;
        }

        $data['navActive'] = 'guide';
        $data['waAccount'] = [
            'provider'            => 'cheerio',
            'display_name'        => '',
            'phone'               => '',
            'profile_picture_url' => '',
            'connected'           => false,
            'needs_refresh'       => false,
        ];
        $data['notifications']          = [];
        $data['unread_notifications']   = 0;
        $data['waIdentityNeedsRefresh'] = false;

        return $data;
    }

    /**
     * @return list<array{type: string, label: string}>
     */
    protected function guideTabs(): array
    {
        return [
            ['type' => 'local', 'label' => 'Local'],
            ['type' => 'production', 'label' => 'Production'],
            ['type' => 'meta-official', 'label' => 'Meta Publish'],
            ['type' => 'meta-screenshots', 'label' => 'Meta Screenshots'],
            ['type' => 'automations', 'label' => 'Automations'],
        ];
    }

    protected function markdownToHtml(string $markdown): string
    {
        $lines = preg_split("/\r\n|\n|\r/", $markdown) ?: [];
        $html  = '';
        $i     = 0;
        $n     = count($lines);

        while ($i < $n) {
            $line = $lines[$i];

            // Fenced code
            if (preg_match('/^```/', $line)) {
                $i++;
                $buf = [];
                while ($i < $n && ! preg_match('/^```/', $lines[$i])) {
                    $buf[] = $lines[$i];
                    $i++;
                }
                $i++; // closing ```
                $code = htmlspecialchars(implode("\n", $buf), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $html .= '<pre class="guide-code"><code>' . $code . '</code></pre>' . "\n";
                continue;
            }

            // Table block
            if (preg_match('/^\s*\|.+\|\s*$/', $line)) {
                $rows = [];
                while ($i < $n && preg_match('/^\s*\|.+\|\s*$/', $lines[$i])) {
                    $cols = array_map('trim', explode('|', trim($lines[$i], " \t|")));
                    $rows[] = $cols;
                    $i++;
                }
                $html .= $this->renderTable($rows);
                continue;
            }

            if (trim($line) === '') {
                $i++;
                continue;
            }

            if (preg_match('/^>\s?(.*)$/', $line)) {
                $parts = [];
                while ($i < $n && preg_match('/^>\s?(.*)$/', $lines[$i], $bm)) {
                    $parts[] = $this->inline($bm[1]);
                    $i++;
                }
                $html .= '<blockquote class="guide-callout"><p>' . implode('<br>', $parts) . "</p></blockquote>\n";
                continue;
            }

            if (preg_match('/^(#{1,3})\s+(.+)$/', $line, $m)) {
                $level = strlen($m[1]);
                $text  = trim($m[2]);
                $id    = $this->slug($text);
                if (preg_match('/^Step\s+(\d+)\s*[.\x{2014}\x{2013}:\-]+\s*(.+)$/u', $text, $step)) {
                    $html .= '<h' . $level . ' id="' . esc($id, 'attr') . '" class="guide-h guide-step">'
                        . '<span class="guide-step-num">' . (int) $step[1] . '</span>'
                        . '<span class="guide-step-title">' . $this->inline($step[2]) . '</span>'
                        . '</h' . $level . ">\n";
                } elseif (preg_match('/^PART\s+([A-Z])\b/u', $text)) {
                    $html .= '<h' . $level . ' id="' . esc($id, 'attr') . '" class="guide-h guide-part">'
                        . $this->inline($text) . '</h' . $level . ">\n";
                } else {
                    $html .= '<h' . $level . ' id="' . esc($id, 'attr') . '" class="guide-h">'
                        . $this->inline($text) . '</h' . $level . ">\n";
                }
                $i++;
                continue;
            }

            if (preg_match('/^---+$/', trim($line))) {
                $html .= "<hr class=\"guide-hr\">\n";
                $i++;
                continue;
            }

            if (preg_match('/^\!\[([^\]]*)\]\(([^)]+)\)\s*$/', trim($line), $m)) {
                $alt = htmlspecialchars($m[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $src = htmlspecialchars($m[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $html .= '<figure class="guide-figure">'
                    . '<a href="' . $src . '" target="_blank" rel="noopener">'
                    . '<img src="' . $src . '" alt="' . $alt . '" class="img-fluid guide-shot" loading="lazy">'
                    . '</a>'
                    . ($alt !== '' ? '<figcaption>' . $alt . '</figcaption>' : '')
                    . "</figure>\n";
                $i++;
                continue;
            }

            // Checklist / ul / ol groups
            if (preg_match('/^[-*]\s+\[([ xX])\]\s+(.+)$/', $line)
                || preg_match('/^[-*]\s+(.+)$/', $line)
                || preg_match('/^\d+\.\s+(.+)$/', $line)
            ) {
                $isOl = (bool) preg_match('/^\d+\.\s+/', $line);
                $isCheck = (bool) preg_match('/^[-*]\s+\[[ xX]\]\s+/', $line);
                $tag = $isOl ? 'ol' : 'ul';
                $cls = $isCheck ? ' class="guide-checklist"' : '';
                $html .= '<' . $tag . $cls . ">\n";
                while ($i < $n) {
                    $l = $lines[$i];
                    if ($isCheck && preg_match('/^[-*]\s+\[([ xX])\]\s+(.+)$/', $l, $m)) {
                        $checked = strtolower($m[1]) === 'x' ? ' checked' : '';
                        $html .= '<li><label><input type="checkbox" disabled' . $checked . '> '
                            . $this->inline($m[2]) . '</label></li>' . "\n";
                        $i++;
                        continue;
                    }
                    if (! $isOl && ! $isCheck && preg_match('/^[-*]\s+(.+)$/', $l, $m)) {
                        $html .= '<li>' . $this->inline($m[1]) . "</li>\n";
                        $i++;
                        continue;
                    }
                    if ($isOl && preg_match('/^\d+\.\s+(.+)$/', $l, $m)) {
                        $html .= '<li>' . $this->inline($m[1]) . "</li>\n";
                        $i++;
                        continue;
                    }
                    break;
                }
                $html .= '</' . $tag . ">\n";
                continue;
            }

            $html .= '<p>' . $this->inline($line) . "</p>\n";
            $i++;
        }

        return $html;
    }

    /**
     * @param list<list<string>> $rows
     */
    protected function renderTable(array $rows): string
    {
        if ($rows === []) {
            return '';
        }

        $html = '<div class="table-responsive"><table class="table table-bordered table-sm guide-table">';
        $headerDone = false;
        $bodyOpen   = false;

        foreach ($rows as $cols) {
            $isSep = true;
            foreach ($cols as $c) {
                if (! preg_match('/^:?-+:?$/', $c)) {
                    $isSep = false;
                    break;
                }
            }
            if ($isSep) {
                continue;
            }

            if (! $headerDone) {
                $html .= '<thead><tr>';
                foreach ($cols as $c) {
                    $html .= '<th>' . $this->inline($c) . '</th>';
                }
                $html .= '</tr></thead>';
                $headerDone = true;
                continue;
            }

            if (! $bodyOpen) {
                $html .= '<tbody>';
                $bodyOpen = true;
            }
            $html .= '<tr>';
            foreach ($cols as $c) {
                $html .= '<td>' . $this->inline($c) . '</td>';
            }
            $html .= '</tr>';
        }

        if ($bodyOpen) {
            $html .= '</tbody>';
        }
        $html .= '</table></div>' . "\n";

        return $html;
    }

    protected function inline(string $text): string
    {
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = preg_replace(
            '/!\[([^\]]*)\]\(([^)]+)\)/',
            '<img src="$2" alt="$1" class="img-fluid guide-shot-inline">',
            $text
        ) ?? $text;
        $text = preg_replace(
            '/\[([^\]]+)\]\(([^)]+)\)/',
            '<a href="$2">$1</a>',
            $text
        ) ?? $text;
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text) ?? $text;

        return $text;
    }

    protected function slug(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? $text;

        return trim($text, '-') ?: 'section';
    }

    /**
     * @return list<array{id: string, text: string, level: int}>
     */
    protected function extractToc(string $markdown): array
    {
        $toc = [];
        if (preg_match_all('/^(#{1,2})\s+(.+)$/m', $markdown, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $text  = trim($m[2]);
                $toc[] = [
                    'id'    => $this->slug($text),
                    'text'  => $text,
                    'level' => strlen($m[1]),
                ];
            }
        }

        return $toc;
    }
}
