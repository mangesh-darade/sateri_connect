<?php

declare(strict_types=1);

namespace App\Libraries\Social;

use App\Libraries\MetaApiErrorMapper;
use Config\Services;
use Throwable;

/**
 * Shared Graph API plumbing for the Facebook and Instagram publishers.
 * No retry loop here: the cron re-queues temporary failures.
 */
abstract class MetaGraphPublisher implements SocialPublisherInterface
{
    protected string $graphBaseUrl = 'https://graph.facebook.com';
    protected string $apiVersion   = 'v21.0';

    /**
     * @param array<string, mixed> $params
     * @return array{ok: bool, status: int, body: array<string, mixed>, message: string, retry: bool}
     */
    protected function graph(string $method, string $path, string $token, array $params = []): array
    {
        try {
            $method  = strtoupper($method);
            $upload  = array_filter($params, static fn ($v): bool => $v instanceof \CURLFile) !== [];
            // A file upload (photo, video) can take minutes; normal API calls stay at 60 seconds.
            $client  = Services::curlrequest(['timeout' => $upload ? 600 : 60, 'http_errors' => false], null, null, false);
            $options = ['headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json']];
            $options[$method === 'GET' ? 'query' : ($upload ? 'multipart' : 'form_params')] = $params;

            $response = $client->request($method, $this->graphBaseUrl . '/' . $this->apiVersion . '/' . ltrim($path, '/'), $options);
            $status   = $response->getStatusCode();
            $body     = json_decode((string) $response->getBody(), true);
            $body     = is_array($body) ? $body : [];

            if ($status >= 200 && $status < 300 && ! isset($body['error'])) {
                return ['ok' => true, 'status' => $status, 'body' => $body, 'message' => '', 'retry' => false];
            }

            $err  = MetaApiErrorMapper::parseDecoded($body);
            $code = (int) ($err['code'] ?? 0);
            log_message('error', static::class . '::graph failed path=' . $path . ' http=' . $status . ' code=' . $code);

            return [
                'ok'      => false,
                'status'  => $status >= 400 ? $status : 502,
                'body'    => [],
                'message' => $this->friendlyMessage((string) ($err['message'] ?? ''), $status, $code),
                // 1/2/4/17/32/613 are transient or rate limits; 5xx is Meta side. Auth, policy and bad media are final.
                'retry'   => $status >= 500 || in_array($code, [1, 2, 4, 17, 32, 613], true),
            ];
        } catch (Throwable $e) {
            log_message('error', static::class . '::graph exception path=' . $path . ' ' . $e::class);

            return ['ok' => false, 'status' => 502, 'body' => [], 'message' => 'Could not reach the platform. Please try again.', 'retry' => true];
        }
    }

    /**
     * MetaApiErrorMapper words its messages for WhatsApp, so the common account errors are phrased for Facebook and Instagram here.
     */
    private function friendlyMessage(string $raw, int $status, int $code): string
    {
        $lower = strtolower($raw);
        if ($status === 401 || $code === 190 || str_contains($lower, 'session has expired') || str_contains($lower, 'invalid oauth')) {
            return 'The Facebook / Instagram access token is invalid or expired. Reconnect the account on Social > Accounts.';
        }
        if ($status === 403 || $code === 10 || $code === 200 || str_contains($lower, 'permission')) {
            return 'This account does not have permission to publish. Reconnect it on Social > Accounts and allow all the requested permissions.';
        }
        if ($code === 324 || $code === 9004 || $code === 2207052 || $code === 2207003) {
            return 'Facebook / Instagram could not fetch the photo or video from this site. The site address (app.baseURL) must be public HTTPS, not localhost.';
        }
        if ($status === 429 || $code === 4 || $code === 17 || $code === 32 || $code === 613) {
            return 'Facebook / Instagram rate limit reached. It will be tried again shortly.';
        }

        return MetaApiErrorMapper::humanize($raw, $status, $code);
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    protected function fail(array $r): array
    {
        return ['ok' => false, 'status' => (int) $r['status'], 'message' => (string) $r['message'], 'retry' => (bool) ($r['retry'] ?? false)];
    }
}
