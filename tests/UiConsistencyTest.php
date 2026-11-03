<?php

/**
 * Static UI consistency check (see .agents/rules/ui-consistency-standard.md). Scans app/Views and
 * public/assets/js and fails on the patterns the standard forbids. No database, no network.
 *
 * Run: php tests/UiConsistencyTest.php
 */

$root  = dirname(__DIR__);
$views = $root . '/app/Views';
$js    = $root . '/public/assets/js';

/** Views that have their own look on purpose (public pages, auth, installer, invoices, errors). */
const SKIP_DIRS  = ['layouts', 'auth', 'install', 'platform', 'public', 'errors'];
const SKIP_FILES = ['smart_forms/public.php', 'wallet/invoice.php', 'api_docs/index.php', 'guide/index.php'];
/** Full-screen tools: no `.page-list` wrapper. */
const FULL_SCREEN = ['chat/index.php', 'automations/builder.php'];

$problems = [];

function rel(string $path, string $base): string
{
    return str_replace('\\', '/', substr($path, strlen($base) + 1));
}

function files(string $dir, string $ext): array
{
    $out = [];
    $it  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === $ext && ! str_contains($f->getFilename(), '.min.')) {
            $out[] = $f->getPathname();
        }
    }
    sort($out);

    return $out;
}

foreach (files($views, 'php') as $path) {
    $r = rel($path, $views);
    if (in_array(explode('/', $r)[0], SKIP_DIRS, true) || in_array($r, SKIP_FILES, true)) {
        continue;
    }
    $src = (string) file_get_contents($path);

    // Native dialogs in inline scripts.
    if (preg_match('/(?<![.\w])(alert|confirm|prompt)\s*\(/', $src, $m)) {
        $problems[] = "$r: native {$m[1]}() (use APP.toast / APP.confirm / APP.prompt)";
    }

    // Action button colours.
    if (preg_match('/\bbtn-(primary|success|info|dark|outline-primary|outline-success)\b/', $src, $m)) {
        $problems[] = "$r: button class btn-{$m[1]} (use btn-wa or btn-outline-secondary)";
    }

    if (! str_contains($src, "extend('layouts/main')")) {
        continue;
    }

    if (! in_array($r, FULL_SCREEN, true) && ! preg_match('/class="[^"]*\b(page-list|page-stack)\b/', $src)) {
        $problems[] = "$r: content is not wrapped in <div class=\"page-list\">";
    }
    if (preg_match('/<h1[\s>]/', $src)) {
        $problems[] = "$r: own <h1> (the layout prints the page title)";
    }
    if (preg_match("/section\('header_actions'\)(.*?)endSection/s", $src, $m)) {
        foreach (preg_split('/(?=<(?:a|button)\b)/', $m[1]) as $chunk) {
            if (preg_match('/^<(?:a|button)\b[^>]*class="[^"]*\bbtn\b(?![-\w])[^"]*"/', $chunk, $b) && ! str_contains($b[0], 'btn-sm')) {
                $problems[] = "$r: header_actions button without btn-sm";
                break;
            }
        }
    }
}

foreach (files($js, 'js') as $path) {
    $r   = rel($path, $js);
    $src = (string) file_get_contents($path);
    // Buttons built inside JS strings follow the same colour rule as the views.
    if (preg_match('/\bbtn-(primary|success|info|dark|outline-primary|outline-success)\b/', $src, $m)) {
        $problems[] = "js/$r: button class btn-{$m[1]} (use btn-wa or btn-outline-secondary)";
    }
    foreach (preg_split('/\R/', $src) as $i => $line) {
        // app.js keeps one alert() as the fallback when SweetAlert has not loaded.
        if ($r === 'app.js' && str_contains($line, 'alert(message)')) {
            continue;
        }
        if (preg_match('/^\s*(\/\/|\*|\/\*)/', $line)) {
            continue;
        }
        if (preg_match('/(?<![.\w])(alert|confirm|prompt)\s*\(/', $line, $m)) {
            $problems[] = $r . ':' . ($i + 1) . ': native ' . $m[1] . '() (use APP.toast / APP.confirm / APP.prompt)';
        }
    }
}

if ($problems === []) {
    echo "[PASS] UI consistency: no violations\n";
    exit(0);
}

foreach ($problems as $p) {
    echo '[FAIL] ' . $p . PHP_EOL;
}
echo PHP_EOL . count($problems) . " violation(s)\n";
exit(1);
