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
                    'description' => 'Create or update a customer with name, email, tags, and custom attributes. A new contact is opted in automatically (opt_in 1, source api) unless the body sends opt_in false. An existing contact keeps its consent unless opt_in is sent. Triggers contact automations.',
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
                    'title'       => 'Get Conversation Thread (Inbox)',
                    'method'      => 'GET',
                    'path'        => '/api/v1/messages?phone=+917744010738&limit=50',
                    'description' => 'Latest WhatsApp messages (sent + customer replies) for one phone number, oldest first, with live status (sent / delivered / read / failed / received) and 24-hour window flag. Poll it to build an inbox with WhatsApp-style ticks. Requires ability messages:read.',
                    'headers'     => [
                        'X-API-Key' => 'sc_live_your_api_key',
                    ],
                    'response' => [
                        'status'  => 'success',
                        'message' => 'Conversation retrieved.',
                        'data'    => [
                            'contact' => [
                                'id'            => 1042,
                                'name'          => 'Mangesh Darade',
                                'phone'         => '917744010738',
                                'last_reply_at' => '2026-10-03 07:16:05',
                                'within_24h'    => true,
                            ],
                            'messages' => [
                                [
                                    'id'         => 850,
                                    'direction'  => 'outbound',
                                    'type'       => 'template',
                                    'content'    => 'Template: order_confirmation',
                                    'media_url'  => null,
                                    'status'     => 'read',
                                    'error'      => null,
                                    'created_at' => '2026-10-03 07:15:00',
                                ],
                                [
                                    'id'         => 851,
                                    'direction'  => 'inbound',
                                    'type'       => 'text',
                                    'content'    => 'Thanks, when will it be delivered?',
                                    'media_url'  => null,
                                    'status'     => 'received',
                                    'error'      => null,
                                    'created_at' => '2026-10-03 07:16:05',
                                ],
                            ],
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
                [
                    'title'       => 'Submit New WhatsApp Template',
                    'method'      => 'POST',
                    'path'        => '/api/v1/templates',
                    'description' => 'Submit a new template to Meta for review (status PENDING, approval takes time). Same validation as the Templates screen. Sync or GET /templates later to see APPROVED.',
                    'headers'     => ['X-API-Key' => 'sc_live_your_api_key', 'Content-Type' => 'application/json'],
                    'body'        => ['name' => 'diwali_offer', 'category' => 'MARKETING', 'language' => 'en_US', 'body' => 'Hi {{1}}, enjoy 20% off this Diwali.', 'body_examples' => 'Rahul', 'footer' => 'Reply STOP to opt out'],
                    'response'    => ['status' => 'success', 'message' => 'Template submitted (PENDING).', 'data' => ['name' => 'diwali_offer', 'status' => 'PENDING']],
                ],
                [
                    'title'       => 'Contact Tags (Audience Groups)',
                    'method'      => 'GET',
                    'path'        => '/api/v1/contacts/tags',
                    'description' => 'Tags with contact counts. Use the ids as tag_ids in campaigns.',
                    'headers'     => ['X-API-Key' => 'sc_live_your_api_key', 'Content-Type' => 'application/json'],
                    'response'    => ['status' => 'success', 'message' => 'OK', 'data' => ['tags' => [['id' => '4', 'name' => 'VIP', 'contacts' => '214']]]],
                ],
                [
                    'title'       => 'Campaign: List',
                    'method'      => 'GET',
                    'path'        => '/api/v1/campaigns',
                    'description' => 'Recent WhatsApp campaigns with status and delivery counts.',
                    'headers'     => ['X-API-Key' => 'sc_live_your_api_key', 'Content-Type' => 'application/json'],
                    'response'    => ['status' => 'success', 'message' => 'OK', 'data' => ['campaigns' => [['id' => '26', 'name' => 'Test', 'status' => 'completed', 'sent_count' => '10']]]],
                ],
                [
                    'title'       => 'Campaign: Status',
                    'method'      => 'GET',
                    'path'        => '/api/v1/campaigns/{id}',
                    'description' => 'One campaign with sent, delivered, read, failed and reply counts.',
                    'headers'     => ['X-API-Key' => 'sc_live_your_api_key', 'Content-Type' => 'application/json'],
                    'response'    => ['status' => 'success', 'message' => 'OK', 'data' => ['id' => '26', 'status' => 'completed']],
                ],
                [
                    'title'       => 'Campaign: Create Draft',
                    'method'      => 'POST',
                    'path'        => '/api/v1/campaigns',
                    'description' => 'Create a draft from an approved template and an audience. Sends nothing.',
                    'headers'     => ['X-API-Key' => 'sc_live_your_api_key', 'Content-Type' => 'application/json'],
                    'body'        => ['name' => 'Diwali offer', 'template_id' => 143, 'variables' => ['1' => 'name'], 'tag_ids' => [4]],
                    'response'    => ['status' => 'success', 'message' => 'OK', 'data' => ['id' => 27, 'status' => 'draft', 'audience' => ['contacts' => []]]],
                ],
                [
                    'title'       => 'Campaign: Start / Schedule / Pause / Resume / Cancel',
                    'method'      => 'POST',
                    'path'        => '/api/v1/campaigns/{id}/{start|schedule|pause|resume|cancel}',
                    'description' => 'start and schedule send real messages: the first call returns the audience (409), repeat with confirm true. schedule needs scheduled_at (YYYY-MM-DD HH:MM).',
                    'headers'     => ['X-API-Key' => 'sc_live_your_api_key', 'Content-Type' => 'application/json'],
                    'body'        => ['confirm' => true, 'scheduled_at' => '2026-11-05 10:00'],
                    'response'    => ['status' => 'success', 'message' => 'OK', 'data' => ['id' => 27]],
                ],
                [
                    'title'       => 'Report Summary',
                    'method'      => 'GET',
                    'path'        => '/api/v1/reports/summary?from=2026-11-01&to=2026-11-30',
                    'description' => 'Outbound and inbound message totals and campaign delivery, read and fail rates. Default range is this month.',
                    'headers'     => ['X-API-Key' => 'sc_live_your_api_key', 'Content-Type' => 'application/json'],
                    'response'    => ['status' => 'success', 'message' => 'OK', 'data' => ['messages' => ['outbound' => 120, 'inbound' => 34], 'campaigns' => ['delivery_rate' => 97.5]]],
                ],
                [
                    'title'       => 'Ads: List',
                    'method'      => 'GET',
                    'path'        => '/api/v1/ads',
                    'description' => 'Ad campaigns with status, spend and chats.',
                    'headers'     => ['X-API-Key' => 'sc_live_your_api_key', 'Content-Type' => 'application/json'],
                    'response'    => ['status' => 'success', 'message' => 'OK', 'data' => ['ads' => [], 'total' => 0]],
                ],
                [
                    'title'       => 'Ads: Pause',
                    'method'      => 'POST',
                    'path'        => '/api/v1/ads/{id}/pause',
                    'description' => 'Pause an ad campaign. Launching ads stays on the Ads screen.',
                    'headers'     => ['X-API-Key' => 'sc_live_your_api_key', 'Content-Type' => 'application/json'],
                    'response'    => ['status' => 'success', 'message' => 'OK', 'data' => []],
                ],
                [
                    'title'       => 'Social: AI Content Plan',
                    'method'      => 'POST',
                    'path'        => '/api/v1/social/plans',
                    'description' => 'AI writes a multi-day Instagram/Facebook plan (nothing posted). Then GET /social/plans/{id}, POST /social/plans/items/{id}/image, POST /social/plans/{id}/approve with confirm true.',
                    'headers'     => ['X-API-Key' => 'sc_live_your_api_key', 'Content-Type' => 'application/json'],
                    'body'        => ['category' => 'Retail ERP', 'brief' => 'Free demo', 'days' => 7, 'start_date' => '2026-11-05', 'post_time' => '10:00', 'accounts' => [3, 4], 'tone' => 'friendly', 'language' => 'English'],
                    'response'    => ['status' => 'success', 'message' => 'OK', 'data' => ['plan_id' => 1]],
                ],
                [
                    'title'       => 'Workflow: Get One',
                    'method'      => 'GET',
                    'path'        => '/api/v1/automations/{id}',
                    'description' => 'Workflow with trigger, canvas graph (flow_graph) and rules. GET /automations?all=1 lists inactive ones too.',
                    'headers'     => ['X-API-Key' => 'sc_live_your_api_key', 'Content-Type' => 'application/json'],
                    'response'    => ['status' => 'success', 'message' => 'OK', 'data' => ['id' => 134]],
                ],
                [
                    'title'       => 'Workflow: Create',
                    'method'      => 'POST',
                    'path'        => '/api/v1/automations',
                    'description' => 'Create a workflow. It stays off until activated. flow_graph is the canvas JSON {nodes, edges}, validated like the builder.',
                    'headers'     => ['X-API-Key' => 'sc_live_your_api_key', 'Content-Type' => 'application/json'],
                    'body'        => ['name' => 'Welcome flow', 'trigger_type' => 'incoming_message', 'priority' => 10],
                    'response'    => ['status' => 'success', 'message' => 'OK', 'data' => ['id' => 134]],
                ],
                [
                    'title'       => 'Workflow: Update',
                    'method'      => 'POST',
                    'path'        => '/api/v1/automations/{id}',
                    'description' => 'Change name, trigger_type, trigger_config, priority, is_active and/or flow_graph.',
                    'headers'     => ['X-API-Key' => 'sc_live_your_api_key', 'Content-Type' => 'application/json'],
                    'body'        => ['name' => 'Welcome flow v2', 'is_active' => true],
                    'response'    => ['status' => 'success', 'message' => 'OK', 'data' => ['id' => 134]],
                ],
                [
                    'title'       => 'Workflow: Duplicate',
                    'method'      => 'POST',
                    'path'        => '/api/v1/automations/{id}/duplicate',
                    'description' => 'Copy a workflow with its rules. The copy stays off.',
                    'headers'     => ['X-API-Key' => 'sc_live_your_api_key', 'Content-Type' => 'application/json'],
                    'response'    => ['status' => 'success', 'message' => 'OK', 'data' => ['id' => 134]],
                ],
                [
                    'title'       => 'Workflow: Switch On/Off',
                    'method'      => 'POST',
                    'path'        => '/api/v1/automations/{id}/toggle',
                    'description' => 'Toggle is_active.',
                    'headers'     => ['X-API-Key' => 'sc_live_your_api_key', 'Content-Type' => 'application/json'],
                    'response'    => ['status' => 'success', 'message' => 'OK', 'data' => ['id' => 134]],
                ],
                [
                    'title'       => 'Social: Publish Picture (one call)',
                    'method'      => 'POST',
                    'path'        => '/api/v1/social/publish',
                    'description' => 'Post a picture to accounts in one call: asset_id from the library or a public https image_url. when is YYYY-MM-DD HH:MM, default in 2 minutes.',
                    'headers'     => ['X-API-Key' => 'sc_live_your_api_key', 'Content-Type' => 'application/json'],
                    'body'        => ['image_url' => 'https://example.com/offer.png', 'accounts' => [3, 4], 'caption' => 'Free demo: elintom.io', 'hashtags' => '#RetailERP'],
                    'response'    => ['status' => 'success', 'message' => 'OK', 'data' => ['asset_id' => 6]],
                ],
                [
                    'title'       => 'Social: AI Picture',
                    'method'      => 'POST',
                    'path'        => '/api/v1/social/ai/image',
                    'description' => 'The app image AI (key set in Social Setup or Settings, AI) makes a picture and adds it to the library. Returns asset_id.',
                    'headers'     => ['X-API-Key' => 'sc_live_your_api_key', 'Content-Type' => 'application/json'],
                    'body'        => ['prompt' => 'Clean flat illustration of a retail store dashboard'],
                    'response'    => ['status' => 'success', 'message' => 'OK', 'data' => ['asset_id' => 6]],
                ],
                [
                    'title'       => 'Social: List Connected Accounts',
                    'method'      => 'GET',
                    'path'        => '/api/v1/social/accounts',
                    'description' => 'Connected Facebook, Instagram, YouTube, LinkedIn and WhatsApp broadcast accounts with their ids.',
                    'headers'     => ['X-API-Key' => 'sc_live_your_api_key', 'Content-Type' => 'application/json'],
                    'response'    => ['status' => 'success', 'message' => 'OK', 'data' => ['accounts' => [['id' => '3', 'platform' => 'facebook', 'name' => 'My Page', 'status' => 'active']], 'total' => 1]],
                ],
                [
                    'title'       => 'Social: List Media Library',
                    'method'      => 'GET',
                    'path'        => '/api/v1/social/library',
                    'description' => 'Photos and videos available to post, with readiness tips and how many posts use each.',
                    'headers'     => ['X-API-Key' => 'sc_live_your_api_key', 'Content-Type' => 'application/json'],
                    'response'    => ['status' => 'success', 'message' => 'OK', 'data' => ['assets' => [['id' => 6, 'filename' => 'offer.png', 'mime' => 'image/png', 'width' => 1080, 'height' => 1350, 'used' => 0]], 'total' => 1]],
                ],
                [
                    'title'       => 'Social: Upload Picture',
                    'method'      => 'POST',
                    'path'        => '/api/v1/social/media',
                    'description' => 'Add a JPEG or PNG (max 10 MB) made outside the app: image_base64, or a public https image_url. Returns asset_id to use in Schedule Media Posts.',
                    'headers'     => ['X-API-Key' => 'sc_live_your_api_key', 'Content-Type' => 'application/json'],
                    'body'        => ['image_base64' => '<base64 or data:image/png;base64,...>', 'filename' => 'offer.png'],
                    'response'    => ['status' => 'success', 'message' => 'OK', 'data' => ['asset_id' => 6]],
                ],
                [
                    'title'       => 'Social: Schedule Media Posts',
                    'method'      => 'POST',
                    'path'        => '/api/v1/social/schedule',
                    'description' => 'Schedule library photos/videos on accounts. times are local HH:MM, one slot per asset.',
                    'headers'     => ['X-API-Key' => 'sc_live_your_api_key', 'Content-Type' => 'application/json'],
                    'body'        => ['assets' => [6], 'accounts' => [3, 4], 'start_date' => '2026-11-03', 'times' => ['13:11'], 'caption' => 'Free demo: elintom.io', 'hashtags' => '#RetailERP'],
                    'response'    => ['status' => 'success', 'message' => 'OK', 'data' => ['created' => 2, 'skipped' => []]],
                ],
                [
                    'title'       => 'Social: Schedule Text Post',
                    'method'      => 'POST',
                    'path'        => '/api/v1/social/text-post',
                    'description' => 'Schedule a text-only post (Facebook, LinkedIn, WhatsApp broadcast; not Instagram or YouTube).',
                    'headers'     => ['X-API-Key' => 'sc_live_your_api_key', 'Content-Type' => 'application/json'],
                    'body'        => ['accounts' => [3], 'when' => '2026-11-03 18:00', 'caption' => 'Hello!', 'hashtags' => ''],
                    'response'    => ['status' => 'success', 'message' => 'OK', 'data' => ['created' => 1]],
                ],
                [
                    'title'       => 'Social: List Posts',
                    'method'      => 'GET',
                    'path'        => '/api/v1/social/posts?from=2026-11-01&to=2026-11-30',
                    'description' => 'Scheduled and published post targets between two dates.',
                    'headers'     => ['X-API-Key' => 'sc_live_your_api_key', 'Content-Type' => 'application/json'],
                    'response'    => ['status' => 'success', 'message' => 'OK', 'data' => [['id' => '10', 'status' => 'scheduled', 'scheduled_at' => '2026-11-03 13:11:00', 'platform' => 'facebook']]],
                ],
                [
                    'title'       => 'Social: Cancel / Retry / Delete / Reschedule a Post',
                    'method'      => 'POST',
                    'path'        => '/api/v1/social/targets/{id}/{cancel|retry|delete|reschedule}',
                    'description' => 'Act on one post target. reschedule needs body {when: YYYY-MM-DD HH:MM}.',
                    'headers'     => ['X-API-Key' => 'sc_live_your_api_key', 'Content-Type' => 'application/json'],
                    'body'        => ['when' => '2026-11-04 10:00'],
                    'response'    => ['status' => 'success', 'message' => 'OK', 'data' => []],
                ],
                [
                    'title'       => 'Social: AI Caption Suggestions',
                    'method'      => 'POST',
                    'path'        => '/api/v1/social/ai/captions',
                    'description' => 'Generate caption ideas for a topic with the configured AI.',
                    'headers'     => ['X-API-Key' => 'sc_live_your_api_key', 'Content-Type' => 'application/json'],
                    'body'        => ['topic' => 'Retail ERP demo', 'platform' => 'instagram', 'tone' => 'friendly', 'language' => 'English'],
                    'response'    => ['status' => 'success', 'message' => 'OK', 'data' => ['captions' => ['...']]],
                ],
                [
                    'title'       => 'MCP: Remote Server (ChatGPT, Claude, etc.)',
                    'method'      => 'POST',
                    'path'        => '/api/v1/mcp?tenant=your_client_key',
                    'description' => 'JSON-RPC 2.0 MCP endpoint (initialize, tools/list, tools/call). Auth: Bearer token or X-API-Key; URL-based clients (ChatGPT) use OAuth 2.1 + PKCE, discovered from /.well-known/oauth-protected-resource. Each tool call is checked against the normal API permissions.',
                    'headers'     => ['X-API-Key' => 'sc_live_your_api_key', 'Content-Type' => 'application/json'],
                    'body'        => ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
                    'response'    => ['status' => 'success', 'message' => 'OK', 'data' => ['tools' => [['name' => 'social_list_accounts']]]],
                ],
            ],
        ];
    }
}
