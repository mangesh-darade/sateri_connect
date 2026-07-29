<?php

/**
 * Public asset URL helpers (cache-bust by filemtime).
 */

if (! function_exists('asset_url')) {
    /**
     * URL for a file under public/, with ?v={mtime} so deploys bust browser cache.
     * Pass a path relative to public/, e.g. assets/css/app.css
     */
    function asset_url(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        $url  = base_url($path);

        $full = FCPATH . str_replace('/', DIRECTORY_SEPARATOR, $path);
        $v    = is_file($full) ? (string) filemtime($full) : (string) time();

        return $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . $v;
    }
}
