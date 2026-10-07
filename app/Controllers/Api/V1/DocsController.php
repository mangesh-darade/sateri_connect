<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * REST API Documentation Controller.
 * Serves developer guide, interactive cURL examples, and OpenAPI-style JSON specification.
 */
class DocsController extends BaseController
{
    /**
     * View developer documentation page.
     * GET /api/docs
     */
    public function index(): string|ResponseInterface
    {
        $accept = (string) $this->request->getHeaderLine('Accept');
        if (str_contains($accept, 'application/json') || $this->request->getGet('format') === 'json') {
            return $this->response->setJSON($this->getApiSpec());
        }

        $data = [
            'title'       => 'Developer API Reference | Sateri Connect',
            'baseUrl'     => rtrim(site_url(), '/'),
            'spec'        => $this->getApiSpec(),
            'userTokens'  => [],
        ];

        if (session('user_id')) {
            $data['userTokens'] = model(\App\Models\ApiTokenModel::class)
                ->where('user_id', (int) session('user_id'))
                ->orderBy('id', 'DESC')
                ->findAll(5);
        }

        return view('api_docs/index', $data);
    }

    /**
     * Return JSON specification for Postman / Swagger / SDK generators.
     * GET /api/v1/spec
     */
    public function spec(): ResponseInterface
    {
        return $this->response->setJSON($this->getApiSpec());
    }

    /**
     * Download official Postman Collection JSON with live host URL.
     * GET /api/v1/postman
     */
    public function postman(): ResponseInterface
    {
        $file = ROOTPATH . 'docs/sateri_connect_v1.postman_collection.json';
        if (! is_file($file)) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'Collection not found']);
        }

        $content = (string) file_get_contents($file);
        $data    = json_decode($content, true);

        // Dynamically compute exact API v1 base URL from current server host & protocol
        $apiBaseUrl = rtrim(site_url('api/v1'), '/');

        if (is_array($data)) {
            // Dynamically inject active server base_url variable
            if (isset($data['variable']) && is_array($data['variable'])) {
                foreach ($data['variable'] as &$var) {
                    if (($var['key'] ?? '') === 'base_url') {
                        $var['value'] = $apiBaseUrl;
                    }
                }
                unset($var);
            }

            $content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } else {
            // Fallback replacement for any hardcoded url pattern
            $content = preg_replace('/"value":\s*"https?:\/\/[^"]+\/api\/v1"/', '"value": "' . $apiBaseUrl . '"', $content);
        }

        return $this->response
            ->setHeader('Content-Type', 'application/json')
            ->setHeader('Content-Disposition', 'attachment; filename="sateri_connect_v1.postman_collection.json"')
            ->setBody($content);
    }

    /**
     * Full API endpoints specification.
     *
     * @return array<string, mixed>
     */
    protected function getApiSpec(): array
    {
        $baseUrl = rtrim(site_url(), '/');

        return [
            'api_name'    => 'Sateri Connect Public REST API',
            'version'     => 'v1',
            'base_url'    => $baseUrl . '/api/v1',
            'description' => 'Enterprise WhatsApp Automation, Customer CRM, and Template Messaging API.',
            'auth'        => [
                'type'        => 'API Key or Bearer Token',
                'header_name' => 'X-API-Key',
                'example'     => 'X-API-Key: sc_live_a1b2c3d4e5f6...',
                'bearer_auth' => 'Authorization: Bearer sc_live_...',
            ],
            'endpoints'   => [
                [
                    'title'       => 'Upsert Customer Contact',
                    'method'      => 'POST',
                    'path'        => '/api/v1/contacts/upsert',
                    'description' => 'Create or update a customer with name, email, tags, opt-in consent, and custom attributes. Triggers contact automations.',
                    'headers'     => [
                        'X-API-Key'    => 'sc_live_your_api_key',
                        'Content-Type' => 'application/json',
                    ],
                    'body' => [
                        'phone'             => '+917744010738',
                        'name'              => 'Mangesh Darade',
                        'email'             => 'mangesh@example.com',
                        'opt_in'            => true,
                        'opt_in_source'     => 'website_form',
                        'tags'              => ['VIP Customer', 'Website Lead'],
                        'custom_attributes' => [
                            'city'        => 'Pune',
                            'order_total' => '1500',
                        ],
                    ],
                    'response' => [
                        'status'  => 'success',
                        'message' => 'Customer contact updated successfully.',
                        'data'    => [
                            'id'            => 1042,
                            'phone'         => '+917744010738',
                            'name'          => 'Mangesh Darade',
                            'email'         => 'mangesh@example.com',
                            'status'        => 'active',
                            'opt_in'        => true,
                            'opt_in_source' => 'website_form',
                            'opt_in_at'     => '2026-10-06 12:45:00',
                            'is_opted_out'  => false,
                            'tags'          => ['VIP Customer', 'Website Lead'],
                            'custom_fields' => ['city' => 'Pune', 'order_total' => '1500'],
                            'is_new'        => false,
                        ],
                    ],
                ],
                [
                    'title'       => 'Update WhatsApp Consent (Opt-in / Opt-out)',
                    'method'      => 'POST',
                    'path'        => '/api/v1/contacts/{idOrPhone}/consent',
                    'description' => 'Explicitly record WhatsApp opt-in or opt-out consent for any contact via API.',
                    'headers'     => [
                        'X-API-Key'    => 'sc_live_your_api_key',
                        'Content-Type' => 'application/json',
                    ],
                    'body' => [
                        'opt_in' => true,
                        'source' => 'website_form',
                    ],
                    'response' => [
                        'status'  => 'success',
                        'message' => 'WhatsApp opt-in consent recorded successfully.',
                        'data'    => [
                            'id'            => 1042,
                            'phone'         => '+917744010738',
                            'name'          => 'Mangesh Darade',
                            'opt_in'        => true,
                            'opt_in_source' => 'website_form',
                            'opt_in_at'     => '2026-10-06 12:45:00',
                            'is_opted_out'  => false,
                            'opted_out_at'  => null,
                        ],
                    ],
                ],
                [
                    'title'       => 'Send Direct WhatsApp Text',
                    'method'      => 'POST',
                    'path'        => '/api/v1/messages/send-text',
                    'description' => 'Send direct WhatsApp text message to customer. (Valid within 24-hour customer service window).',
                    'headers'     => [
                        'X-API-Key'    => 'sc_live_your_api_key',
                        'Content-Type' => 'application/json',
                    ],
                    'body' => [
                        'to'   => '+917744010738',
                        'text' => 'Hello! Your support ticket #123 has been resolved.',
                    ],
                    'response' => [
                        'status'  => 'success',
                        'message' => 'Message sent successfully.',
                        'data'    => [
                            'message_id' => 849,
                            'wamid'      => 'wamid.HBgLMOTE5ODc2NTQzMjEwFQIAEhgWM0VCNTA...',
                            'to'         => '917744010738',
                            'status'     => 'sent',
                        ],
                    ],
                ],
                [
                    'title'       => 'Send WhatsApp Template Message',
                    'method'      => 'POST',
                    'path'        => '/api/v1/messages/send-template',
                    'description' => 'Send approved Meta template message anytime (even outside 24h window). Supports dynamic body variables.',
                    'headers'     => [
                        'X-API-Key'    => 'sc_live_your_api_key',
                        'Content-Type' => 'application/json',
                    ],
                    'body' => [
                        'to'            => '+917744010738',
                        'template_name' => 'order_confirmation',
                        'language'      => 'en_US',
                        'variables'     => ['Mangesh', 'ORD-9988', 'Rs. 1,499'],
                    ],
                    'response' => [
                        'status'  => 'success',
                        'message' => 'Template message dispatched successfully.',
                        'data'    => [
                            'message_id'    => 850,
                            'wamid'         => 'wamid.HBgLMOTE5ODc2NTQzMjEwFQIAEhgWM0VCNTA...',
                            'template_name' => 'order_confirmation',
                            'status'        => 'sent',
                        ],
                    ],
                ],
                [
                    'title'       => 'Check Message Status',
                    'method'      => 'GET',
                    'path'        => '/api/v1/messages/{idOrWamid}/status',
                    'description' => 'Check delivery and read status of any sent WhatsApp message.',
                    'headers'     => [
                        'X-API-Key' => 'sc_live_your_api_key',
                    ],
                    'response' => [
                        'status'  => 'success',
                        'message' => 'Message status retrieved.',
                        'data'    => [
                            'message_id'   => 850,
                            'wamid'        => 'wamid.HBgLMOTE5ODc2NTQzMjEwFQIAEhgWM0VCNTA...',
                            'status'       => 'read',
                            'sent_at'      => '2026-10-03 07:15:00',
                            'delivered_at' => '2026-10-03 07:15:02',
                            'read_at'      => '2026-10-03 07:15:10',
                        ],
                    ],
                ],
                [
                    'title'       => 'Trigger Automation Workflow',
                    'method'      => 'POST',
                    'path'        => '/api/v1/automations/trigger',
                    'description' => 'Trigger automated multi-step flows via custom events (e.g. order_placed, new_lead, payment_success).',
                    'headers'     => [
                        'X-API-Key'    => 'sc_live_your_api_key',
                        'Content-Type' => 'application/json',
                    ],
                    'body' => [
                        'event' => 'order_placed',
                        'phone' => '+917744010738',
                        'name'  => 'Mangesh',
                        'data'  => [
                            'order_id' => 'ORD-9988',
                            'total'    => 1499,
                            'product'  => 'Premium Plan',
                        ],
                    ],
                    'response' => [
                        'status'  => 'success',
                        'message' => 'Automation event triggered successfully.',
                        'data'    => [
                            'event'    => 'order_placed',
                            'matched'  => 1,
                            'executed' => 1,
                        ],
                    ],
                ],
                [
                    'title'       => 'Search Contacts',
                    'method'      => 'GET',
                    'path'        => '/api/v1/contacts/search?q=Mangesh&page=1&per_page=25',
                    'description' => 'Search existing contacts by name, phone number, or email with pagination.',
                    'headers'     => [
                        'X-API-Key' => 'sc_live_your_api_key',
                    ],
                    'response' => [
                        'status'  => 'success',
                        'message' => 'Contacts retrieved.',
                        'data'    => [
                            'items' => [
                                [
                                    'id'     => 1042,
                                    'name'   => 'Mangesh Darade',
                                    'phone'  => '+917744010738',
                                    'email'  => 'mangesh@example.com',
                                    'status' => 'active',
                                ],
                            ],
                            'total'        => 1,
                            'per_page'     => 25,
                            'current_page' => 1,
                        ],
                    ],
                ],
                [
                    'title'       => 'Send WhatsApp Media (Image / PDF / Video)',
                    'method'      => 'POST',
                    'path'        => '/api/v1/messages/send-media',
                    'description' => 'Send invoice PDFs, product photos, videos, or audio files with optional caption.',
                    'headers'     => [
                        'X-API-Key'    => 'sc_live_your_api_key',
                        'Content-Type' => 'application/json',
                    ],
                    'body' => [
                        'to'       => '+917744010738',
                        'type'     => 'document',
                        'url'      => 'https://example.com/invoice.pdf',
                        'caption'  => 'Invoice #INV-2026-001',
                        'filename' => 'invoice_001.pdf',
                    ],
                    'response' => [
                        'status'  => 'success',
                        'message' => 'Media message sent successfully.',
                        'data'    => [
                            'message_id' => 852,
                            'wamid'      => 'wamid.HBgLMOTE5ODc2NTQzMjEwFQIAEhgWM0VCNTA...',
                            'to'         => '917744010738',
                            'type'       => 'document',
                            'media_url'  => 'https://example.com/invoice.pdf',
                            'status'     => 'sent',
                        ],
                    ],
                ],
                [
                    'title'       => 'List WhatsApp Templates',
                    'method'      => 'GET',
                    'path'        => '/api/v1/templates?status=APPROVED&page=1&per_page=25',
                    'description' => 'Discover all approved Meta WhatsApp templates with their categories, parameters, and variable schema.',
                    'headers'     => [
                        'X-API-Key' => 'sc_live_your_api_key',
                    ],
                    'response' => [
                        'status'  => 'success',
                        'message' => 'Templates retrieved successfully.',
                        'data'    => [
                            'templates' => [
                                [
                                    'id'              => 12,
                                    'name'            => 'order_confirmation',
                                    'language'        => 'en_US',
                                    'category'        => 'UTILITY',
                                    'status'          => 'APPROVED',
                                    'body'            => 'Hello {{1}}, your order {{2}} has been confirmed for Rs. {{3}}.',
                                    'variables_count' => 3,
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'title'       => 'Configure New Sending Email / Domain (Amazon SES)',
                    'method'      => 'POST',
                    'path'        => '/api/v1/email/identities',
                    'description' => 'Initiates configuration of a new sending domain on Amazon SES (Easy DKIM + custom MAIL FROM) and returns the DNS records to publish: DKIM CNAMEs (also verify the domain), MAIL FROM MX + SPF, root SPF and DMARC. If the From email is outside the domain, AWS emails a verification link. Requires SES credentials in Settings.',
                    'headers'     => [
                        'X-API-Key'    => 'sc_live_your_api_key',
                        'Content-Type' => 'application/json',
                    ],
                    'body' => [
                        'domain'              => 'example.com',
                        'email'               => 'noreply@example.com',
                        'name'                => 'Example Store',
                        'mail_from_subdomain' => 'bounce',
                        'dmarc_policy'        => 'none',
                        'dmarc_report_email'  => 'dmarc@example.com',
                    ],
                    'response' => [
                        'status'  => 'success',
                        'message' => 'Domain registered with Amazon SES. Add the DNS records below, then call the status endpoint to verify.',
                        'data'    => [
                            'id'               => 1,
                            'domain'           => 'example.com',
                            'email'            => 'noreply@example.com',
                            'name'             => 'Example Store',
                            'provider'         => 'ses',
                            'region'           => 'ap-south-1',
                            'status'           => 'pending',
                            'mail_from_domain' => 'bounce.example.com',
                            'verification'     => [
                                'domain_status'        => 'PENDING',
                                'verified_for_sending' => false,
                                'dkim_status'          => 'PENDING',
                                'mail_from_status'     => 'PENDING',
                            ],
                            'dns_records' => [
                                ['key' => 'dkim_1', 'category' => 'DKIM / Domain verification', 'type' => 'CNAME', 'name' => 'abc123._domainkey.example.com', 'host' => 'abc123._domainkey', 'value' => 'abc123.dkim.amazonses.com', 'ttl' => 3600, 'required' => true, 'dns_found' => false],
                                ['key' => 'mail_from_mx', 'category' => 'Custom MAIL FROM', 'type' => 'MX', 'name' => 'bounce.example.com', 'host' => 'bounce', 'value' => 'feedback-smtp.ap-south-1.amazonses.com', 'priority' => 10, 'ttl' => 3600, 'required' => true, 'dns_found' => false],
                                ['key' => 'mail_from_spf', 'category' => 'SPF', 'type' => 'TXT', 'name' => 'bounce.example.com', 'host' => 'bounce', 'value' => 'v=spf1 include:amazonses.com ~all', 'ttl' => 3600, 'required' => true, 'dns_found' => false],
                                ['key' => 'root_spf', 'category' => 'SPF', 'type' => 'TXT', 'name' => 'example.com', 'host' => '@', 'value' => 'v=spf1 include:amazonses.com ~all', 'ttl' => 3600, 'required' => false, 'dns_found' => false],
                                ['key' => 'dmarc', 'category' => 'DMARC', 'type' => 'TXT', 'name' => '_dmarc.example.com', 'host' => '_dmarc', 'value' => 'v=DMARC1; p=none; rua=mailto:dmarc@example.com; fo=1', 'ttl' => 3600, 'required' => true, 'dns_found' => false],
                            ],
                            'next_steps' => [
                                'Add every record marked "required": true at your DNS provider.',
                                'Then call GET /api/v1/email/identities/example.com to refresh the status.',
                            ],
                        ],
                    ],
                ],
                [
                    'title'       => 'Get DNS Records for Email Domain',
                    'method'      => 'GET',
                    'path'        => '/api/v1/email/identities/{domain}/dns-records',
                    'description' => 'Returns the DKIM CNAME, SPF, MAIL FROM MX and DMARC records with live DNS check (dns_found) and AWS status. Add ?refresh=0 to skip the AWS/DNS lookup and return stored records.',
                    'headers'     => [
                        'X-API-Key' => 'sc_live_your_api_key',
                    ],
                    'response' => [
                        'status'  => 'success',
                        'message' => 'DNS records to publish at your DNS provider.',
                        'data'    => [
                            'domain'      => 'example.com',
                            'status'      => 'pending',
                            'dns_records' => ['…same shape as above…'],
                            'next_steps'  => ['…'],
                        ],
                    ],
                ],
                [
                    'title'       => 'Email Domain Verification Status',
                    'method'      => 'GET',
                    'path'        => '/api/v1/email/identities/{domain}',
                    'description' => 'Re-checks Amazon SES verification (domain, DKIM, MAIL FROM, From email) and returns the full identity with DNS records. Status: pending | verified | failed.',
                    'headers'     => [
                        'X-API-Key' => 'sc_live_your_api_key',
                    ],
                    'response' => [
                        'status'  => 'success',
                        'message' => 'Domain is verified and ready to send.',
                        'data'    => [
                            'domain'       => 'example.com',
                            'status'       => 'verified',
                            'verification' => [
                                'domain_status'        => 'SUCCESS',
                                'verified_for_sending' => true,
                                'dkim_status'          => 'SUCCESS',
                                'mail_from_status'     => 'SUCCESS',
                                'email_status'         => 'SUCCESS',
                            ],
                        ],
                    ],
                ],
                [
                    'title'       => 'List Configured Email Domains',
                    'method'      => 'GET',
                    'path'        => '/api/v1/email/identities',
                    'description' => 'Lists all Amazon SES sending domains configured for this account with their last known status.',
                    'headers'     => [
                        'X-API-Key' => 'sc_live_your_api_key',
                    ],
                    'response' => [
                        'status'  => 'success',
                        'message' => 'Configured email domains retrieved.',
                        'data'    => [
                            'identities' => [
                                ['id' => 1, 'domain' => 'example.com', 'status' => 'pending', 'region' => 'ap-south-1'],
                            ],
                        ],
                    ],
                ],
                [
                    'title'       => 'Account & WhatsApp Diagnostics',
                    'method'      => 'GET',
                    'path'        => '/api/v1/account',
                    'description' => 'Verify API token abilities, role, and connected WhatsApp Business Account (WABA) status.',
                    'headers'     => [
                        'X-API-Key' => 'sc_live_your_api_key',
                    ],
                    'response' => [
                        'status'  => 'success',
                        'message' => 'Account profile and WhatsApp status retrieved.',
                        'data'    => [
                            'account' => [
                                'user_id' => 1,
                                'name'    => 'Admin User',
                                'role'    => 'super-admin',
                            ],
                            'whatsapp' => [
                                'provider' => 'meta',
                                'status'   => 'connected',
                            ],
                            'system' => [
                                'version'  => 'v1.0.0',
                                'time_utc' => '2026-10-03 07:15:00 UTC',
                            ],
                        ],
                    ],
                ],
                [
                    'title'       => 'API Health Check',
                    'method'      => 'GET',
                    'path'        => '/api/v1/health',
                    'description' => 'Quick liveness check endpoint to monitor API service availability.',
                    'headers'     => [
                        'X-API-Key' => 'sc_live_your_api_key',
                    ],
                    'response' => [
                        'status'  => 'success',
                        'message' => 'Service is operational.',
                        'data'    => [
                            'status'   => 'healthy',
                            'time_utc' => '2026-10-03 07:15:00',
                        ],
                    ],
                ],
            ],
        ];
    }
}
