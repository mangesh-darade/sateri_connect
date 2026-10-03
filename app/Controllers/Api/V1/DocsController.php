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
        $baseUrl = rtrim(site_url(), '/') . '/api/v1';
        $content = str_replace('http://localhost/sateri_connect/api/v1', $baseUrl, $content);

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
                    'description' => 'Create or update a customer with name, email, tags, and custom attributes. Triggers contact automations.',
                    'headers'     => [
                        'X-API-Key'    => 'sc_live_your_api_key',
                        'Content-Type' => 'application/json',
                    ],
                    'body' => [
                        'phone'             => '+919876543210',
                        'name'              => 'Mangesh Darade',
                        'email'             => 'mangesh@example.com',
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
                            'phone'         => '+919876543210',
                            'name'          => 'Mangesh Darade',
                            'email'         => 'mangesh@example.com',
                            'status'        => 'active',
                            'tags'          => ['VIP Customer', 'Website Lead'],
                            'custom_fields' => ['city' => 'Pune', 'order_total' => '1500'],
                            'is_new'        => false,
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
                        'to'   => '+919876543210',
                        'text' => 'Hello! Your support ticket #123 has been resolved.',
                    ],
                    'response' => [
                        'status'  => 'success',
                        'message' => 'Message sent successfully.',
                        'data'    => [
                            'message_id' => 849,
                            'wamid'      => 'wamid.HBgLMOTE5ODc2NTQzMjEwFQIAEhgWM0VCNTA...',
                            'to'         => '919876543210',
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
                        'to'            => '+919876543210',
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
                        'phone' => '+919876543210',
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
                                    'phone'  => '+919876543210',
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
                        'to'       => '+919876543210',
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
                            'to'         => '919876543210',
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
