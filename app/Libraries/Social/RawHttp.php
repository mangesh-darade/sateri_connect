<?php

declare(strict_types=1);

namespace App\Libraries\Social;

/**
 * Small cURL helper for APIs that need response headers or a file body (YouTube resumable upload,
 * LinkedIn uploads). CI's curlrequest cannot stream a file range, so plain cURL is used here.
 */
trait RawHttp
{
    /**
     * @param list<string>                                    $headers  "Name: value" lines
     * @param array{path: string, offset: int, length: int}|null $file  stream this byte range as the body
     * @return array{status: int, headers: array<string, string>, body: string, error: string}
     */
    protected function raw(string $method, string $url, array $headers = [], ?string $body = null, ?array $file = null, int $timeout = 600, ?callable $onProgress = null): array
    {
        $respHeaders = [];
        $ch          = curl_init($url);
        $opts        = [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$respHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ];

        $fh = null;
        if ($file !== null) {
            $fh = fopen($file['path'], 'rb');
            if ($fh === false) {
                return ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'File could not be read.'];
            }
            fseek($fh, $file['offset']);
            $opts[CURLOPT_UPLOAD]     = true;
            $opts[CURLOPT_INFILE]     = $fh;
            $opts[CURLOPT_INFILESIZE] = $file['length'];
            $opts[CURLOPT_READFUNCTION] = static function ($c, $in, int $len) use ($file, &$sent): string {
                $sent = (int) $sent;
                $left = $file['length'] - $sent;
                if ($left <= 0) {
                    return '';
                }
                $chunk = (string) fread($in, min($len, $left));
                $sent += strlen($chunk);

                return $chunk;
            };
            if ($onProgress !== null) {
                $opts[CURLOPT_NOPROGRESS]       = false;
                $opts[CURLOPT_PROGRESSFUNCTION] = static function ($c, $dlTotal, $dlNow, $ulTotal, $ulNow) use ($onProgress): int {
                    if ($ulTotal > 0) {
                        $onProgress((int) floor($ulNow * 100 / $ulTotal));
                    }

                    return 0;
                };
            }
        } elseif ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }

        // Local WAMP often lacks a CA bundle; only then fall back to unverified TLS (matches AiService).
        $ca = ini_get('curl.cainfo') ?: ini_get('openssl.cafile') ?: '';
        if ($ca === '' || ! is_file($ca)) {
            $opts[CURLOPT_SSL_VERIFYPEER] = ENVIRONMENT === 'production';
            $opts[CURLOPT_SSL_VERIFYHOST] = ENVIRONMENT === 'production' ? 2 : 0;
        }

        curl_setopt_array($ch, $opts);
        $out    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);
        if (is_resource($fh)) {
            fclose($fh);
        }

        return ['status' => $status, 'headers' => $respHeaders, 'body' => is_string($out) ? $out : '', 'error' => $err];
    }
}
