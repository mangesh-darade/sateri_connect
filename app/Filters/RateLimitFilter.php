<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Throwable;

/**
 * Rate limiter (cache preferred, DB fallback). Default 60 requests / minute.
 *
 * Arguments: [maxRequests, windowSeconds] e.g. rateLimit:120,60
 */
class RateLimitFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $max    = isset($arguments[0]) ? max(1, (int) $arguments[0]) : 60;
        $window = isset($arguments[1]) ? max(1, (int) $arguments[1]) : 60;

        $ip  = $request->getIPAddress() ?: 'unknown';
        $key = 'rl_' . md5($ip . '|' . $request->getMethod() . '|' . $request->getPath());

        try {
            if ($this->hitCache($key, $max, $window)) {
                return $this->tooMany($window);
            }
        } catch (Throwable $e) {
            log_message('debug', 'RateLimit cache unavailable, using DB: {msg}', ['msg' => $e->getMessage()]);
            if ($this->hitDatabase($key, $max, $window)) {
                return $this->tooMany($window);
            }
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }

    protected function hitCache(string $key, int $max, int $window): bool
    {
        $cache = Services::cache();
        $hits  = (int) $cache->get($key);

        if ($hits >= $max) {
            return true;
        }

        $cache->save($key, $hits + 1, $window);

        return false;
    }

    protected function hitDatabase(string $key, int $max, int $window): bool
    {
        $db  = db_connect();
        $now = time();

        try {
            $row = $db->table('rate_limits')->where('key', $key)->get()->getRowArray();
        } catch (Throwable $e) {
            log_message('error', 'RateLimit DB table unavailable: {msg}', ['msg' => $e->getMessage()]);

            return false;
        }

        if ($row === null) {
            $db->table('rate_limits')->insert([
                'key'          => $key,
                'hits'         => 1,
                'window_start' => $now,
            ]);

            return false;
        }

        $windowStart = (int) $row['window_start'];
        $hits        = (int) $row['hits'];

        if (($now - $windowStart) >= $window) {
            $db->table('rate_limits')->where('key', $key)->update([
                'hits'         => 1,
                'window_start' => $now,
            ]);

            return false;
        }

        if ($hits >= $max) {
            return true;
        }

        $db->table('rate_limits')->where('key', $key)->update([
            'hits' => $hits + 1,
        ]);

        return false;
    }

    protected function tooMany(int $window): ResponseInterface
    {
        return service('response')
            ->setStatusCode(429)
            ->setHeader('Retry-After', (string) $window)
            ->setJSON([
                'success' => false,
                'message' => 'Too many requests. Please try again later.',
            ]);
    }
}
