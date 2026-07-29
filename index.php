<?php

/**
 * Front controller when DocumentRoot is the project root (not public/).
 * Prefer pointing DocumentRoot at public/ when the host allows it.
 *
 * On nginx/Plesk (no .htaccess), this file also:
 *  - creates assets/uploads links into public/ on first PHP hit
 *  - serves static files from public/ when the request falls through to PHP
 */

use CodeIgniter\Boot;
use Config\Paths;

$minPhpVersion = '8.2';
if (version_compare(PHP_VERSION, $minPhpVersion, '<')) {
    $message = sprintf(
        'Your PHP version must be %s or higher to run CodeIgniter. Current version: %s',
        $minPhpVersion,
        PHP_VERSION,
    );

    header('HTTP/1.1 503 Service Unavailable.', true, 503);
    echo $message;

    exit(1);
}

define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);

/**
 * Ensure /assets and /uploads resolve for nginx when DocumentRoot is project root.
 *
 * Prefer symlink → public/. If the host blocks symlinks (common on Plesk),
 * copy public/assets (and keep CSS/JS in sync after deploys) so /assets/*.css
 * does not 404 or serve a stale published copy.
 */
(static function (): void {
    $root = __DIR__;

    $copyTree = static function (string $src, string $dest): void {
        if (! is_dir($src)) {
            return;
        }
        if (! is_dir($dest) && ! @mkdir($dest, 0755, true)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen($src) + 1);
            $target   = $dest . DIRECTORY_SEPARATOR . $relative;

            if ($item->isDir()) {
                if (! is_dir($target)) {
                    @mkdir($target, 0755, true);
                }
                continue;
            }

            $parent = dirname($target);
            if (! is_dir($parent)) {
                @mkdir($parent, 0755, true);
            }
            @copy($item->getPathname(), $target);
        }
    };

    $links = [
        'assets'  => $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets',
        'uploads' => $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads',
    ];

    foreach ($links as $name => $target) {
        $linkPath = $root . DIRECTORY_SEPARATOR . $name;

        if (is_link($linkPath) || ! is_dir($target)) {
            continue;
        }

        // Already a working symlink/dir pointing at public — leave it.
        if (is_dir($linkPath)) {
            $realLink = realpath($linkPath);
            $realTarget = realpath($target);
            if ($realLink !== false && $realTarget !== false && $realLink === $realTarget) {
                continue;
            }
        }

        if (! file_exists($linkPath) && @symlink($target, $linkPath)) {
            continue;
        }

        // No symlink support: publish (or refresh) a real copy under the docroot.
        // Sync when any watched public asset is newer/missing (not only app.css).
        if ($name === 'assets') {
            $watch = [
                'css' . DIRECTORY_SEPARATOR . 'app.css',
                'css' . DIRECTORY_SEPARATOR . 'sidebar.css',
                'js' . DIRECTORY_SEPARATOR . 'app.js',
            ];
            $needsPublish = ! is_dir($linkPath);
            if (! $needsPublish) {
                foreach ($watch as $rel) {
                    $pub = $target . DIRECTORY_SEPARATOR . $rel;
                    $rootFile = $linkPath . DIRECTORY_SEPARATOR . $rel;
                    if (! is_file($pub)) {
                        continue;
                    }
                    if (
                        ! is_file($rootFile)
                        || filemtime($pub) > filemtime($rootFile)
                        || filesize($pub) !== filesize($rootFile)
                    ) {
                        $needsPublish = true;
                        break;
                    }
                }
            }

            if ($needsPublish) {
                $copyTree($target, $linkPath);
            }
            continue;
        }

        // uploads: first-time copy, then refresh when public branding is newer/missing.
        if (! is_dir($linkPath)) {
            $copyTree($target, $linkPath);
            continue;
        }

        $brandingPublic = $target . DIRECTORY_SEPARATOR . 'branding';
        $brandingRoot   = $linkPath . DIRECTORY_SEPARATOR . 'branding';
        if (! is_dir($brandingPublic)) {
            continue;
        }
        if (! is_dir($brandingRoot)) {
            $copyTree($brandingPublic, $brandingRoot);
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($brandingPublic, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen($brandingPublic) + 1);
            $destItem = $brandingRoot . DIRECTORY_SEPARATOR . $relative;
            if ($item->isDir()) {
                if (! is_dir($destItem)) {
                    @mkdir($destItem, 0755, true);
                }
                continue;
            }
            $needsCopy = ! is_file($destItem)
                || filemtime($item->getPathname()) > filemtime($destItem)
                || filesize($item->getPathname()) !== filesize($destItem);
            if ($needsCopy) {
                $parent = dirname($destItem);
                if (! is_dir($parent)) {
                    @mkdir($parent, 0755, true);
                }
                @copy($item->getPathname(), $destItem);
            }
        }
    }

    foreach (['favicon.ico', 'robots.txt'] as $file) {
        $dest = $root . DIRECTORY_SEPARATOR . $file;
        $src  = $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . $file;
        if (! file_exists($dest) && is_file($src)) {
            @copy($src, $dest);
        }
    }
})();

/**
 * Serve an existing file from public/ when PHP receives the request
 * (nginx try_files → index.php, or Apache rewrite).
 */
(static function (): void {
    $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (! is_string($uriPath) || $uriPath === '' || $uriPath === '/') {
        return;
    }

    $uriPath = rawurldecode($uriPath);
    $rel     = ltrim($uriPath, '/');

    if ($rel === '' || str_starts_with($rel, 'index.php')) {
        return;
    }

    // Never serve app/writable/vendor/env via this fallback (case-insensitive).
    $blocked = ['app/', 'writable/', 'vendor/', 'tests/', 'docs/', 'tools/', '.env', 'spark', 'composer.json', 'composer.lock'];
    $relLower = strtolower($rel);
    foreach ($blocked as $prefix) {
        if (str_starts_with($relLower, strtolower($prefix))) {
            return;
        }
    }

    $publicRoot = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'public');
    if ($publicRoot === false) {
        return;
    }

    $candidate = realpath($publicRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel));
    if ($candidate === false || ! is_file($candidate) || ! str_starts_with($candidate, $publicRoot)) {
        return;
    }

    // Do not execute PHP through the static fallback.
    if (str_ends_with(strtolower($candidate), '.php')) {
        return;
    }

    $mime = 'application/octet-stream';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $detected = finfo_file($finfo, $candidate);
            finfo_close($finfo);
            if (is_string($detected) && $detected !== '') {
                $mime = $detected;
            }
        }
    } else {
        $map = [
            'css'  => 'text/css; charset=UTF-8',
            'js'   => 'application/javascript; charset=UTF-8',
            'mjs'  => 'application/javascript; charset=UTF-8',
            'json' => 'application/json; charset=UTF-8',
            'svg'  => 'image/svg+xml',
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'ico'  => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2'=> 'font/woff2',
            'ttf'  => 'font/ttf',
            'map'  => 'application/json',
            'txt'  => 'text/plain; charset=UTF-8',
            'html' => 'text/html; charset=UTF-8',
        ];
        $ext = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
        if (isset($map[$ext])) {
            $mime = $map[$ext];
        }
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($candidate));
    header('X-Content-Type-Options: nosniff');
    readfile($candidate);

    exit(0);
})();

if (getcwd() . DIRECTORY_SEPARATOR !== FCPATH) {
    chdir(FCPATH);
}

require FCPATH . '../app/Config/Paths.php';

$paths = new Paths();

require $paths->systemDirectory . '/Boot.php';

exit(Boot::bootWeb($paths));
