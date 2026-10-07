<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Libraries\SesIdentityService;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * REST API v1 — configure a new sending email / domain on Amazon SES and fetch its DNS records.
 *
 * POST /api/v1/email/identities                       initiate (domain, email, name)
 * GET  /api/v1/email/identities                       list configured domains
 * GET  /api/v1/email/identities/{domain}              verification status (+ DNS records)
 * GET  /api/v1/email/identities/{domain}/dns-records  DNS records to publish
 */
class EmailIdentitiesController extends BaseV1Controller
{
    public function create(): ResponseInterface
    {
        return $this->run(fn (SesIdentityService $svc) => $svc->configure($this->getJsonPayload()));
    }

    public function index(): ResponseInterface
    {
        try {
            return $this->respondSuccess(['identities' => (new SesIdentityService())->listIdentities()], 'Configured email domains retrieved.');
        } catch (Throwable $e) {
            log_message('error', 'API EmailIdentities::index ' . $e->getMessage());

            return $this->respondError('Unable to list email identities.', 500);
        }
    }

    public function show(string $domain): ResponseInterface
    {
        return $this->run(fn (SesIdentityService $svc) => $svc->refresh(urldecode($domain)));
    }

    public function dnsRecords(string $domain): ResponseInterface
    {
        $refresh = $this->request->getGet('refresh') !== '0';

        return $this->run(fn (SesIdentityService $svc) => $svc->dnsRecords(urldecode($domain), $refresh));
    }

    /**
     * @param callable(SesIdentityService): array{ok: bool, status: int, message: string, data?: mixed, errors?: array<string, string>} $action
     */
    protected function run(callable $action): ResponseInterface
    {
        try {
            $result = $action(new SesIdentityService());
        } catch (Throwable $e) {
            log_message('error', 'API EmailIdentities: ' . $e->getMessage());

            return $this->respondError('Unable to process email identity request.', 500);
        }

        if (! empty($result['errors'])) {
            return $this->respondValidationError($result['errors'], $result['message']);
        }

        return $result['ok']
            ? $this->respondSuccess($result['data'] ?? null, $result['message'], $result['status'])
            : $this->respondError($result['message'], $result['status']);
    }
}
