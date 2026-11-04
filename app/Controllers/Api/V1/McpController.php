<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Remote MCP endpoint (JSON-RPC over HTTP) for ChatGPT and other URL-based MCP clients.
 * POST /api/v1/mcp with the same API key as the REST API (Bearer token from OAuth, or X-API-Key); the route sits under apiAuth. URL-based clients add ?tenant= when the host does not identify the client. Each tool call is forwarded to the matching
 * /api/v1 endpoint with that key, so permissions, consent and tenant rules stay where they already live.
 * The stdio connectors in tools/ expose the same tools for desktop clients.
 */
class McpController extends BaseV1Controller
{
    private const PROTOCOL = '2025-03-26';

    /** Versions we can speak; the client's own choice is echoed back when it is one of these. */
    private const VERSIONS = ['2024-11-05', '2025-03-26', '2025-06-18'];

    /** @return array<string, array{0: string, 1: string, 2: string, 3: array<string, mixed>, 4?: list<string>}> name => [method, path, description, properties, required] */
    private function tools(): array
    {
        $str = ['type' => 'string'];
        $int = ['type' => 'integer'];
        $ids = ['type' => 'array', 'items' => $int];
        // ChatGPT passes an attached/generated picture as {download_url, file_id} when the tool declares it in _meta (see toolList).
        $file = ['type' => 'object', 'description' => 'A picture from the chat (ChatGPT file).', 'properties' => ['download_url' => $str, 'file_id' => $str]];

        return [
            'contacts_search'        => ['GET', 'contacts/search', 'Search contacts by name/phone/email.', ['q' => $str, 'page' => $int, 'per_page' => $int], ['q']],
            'contacts_get'           => ['GET', 'contacts/{id}', 'Get one contact by id or phone.', ['id' => $str], ['id']],
            'contacts_upsert'        => ['POST', 'contacts/upsert', 'Create or update a contact by phone (with country code).', ['phone' => $str, 'name' => $str, 'email' => $str, 'tags' => ['type' => 'array', 'items' => $str], 'notes' => $str, 'opt_in' => ['type' => 'boolean'], 'opt_in_source' => $str], ['phone']],
            'templates_list'         => ['GET', 'templates', 'List WhatsApp templates (default status APPROVED).', ['status' => $str, 'category' => $str, 'language' => $str, 'search' => $str]],
            'templates_create'       => ['POST', 'templates', 'Submit a NEW WhatsApp template to Meta for review (starts PENDING). category MARKETING|UTILITY. Use {{1}} placeholders with body_examples. Confirm with the user first.', ['name' => $str, 'category' => $str, 'language' => $str, 'body' => $str, 'header' => $str, 'footer' => $str, 'body_examples' => $str, 'template_buttons' => ['type' => 'array', 'items' => ['type' => 'object']]], ['name', 'category', 'body']],
            'contacts_tags'          => ['GET', 'contacts/tags', 'List tags (audience groups) with contact counts; use ids as tag_ids in campaigns.', []],
            'campaigns_list'         => ['GET', 'campaigns', 'List recent WhatsApp campaigns with status and delivery counts.', []],
            'campaign_status'        => ['GET', 'campaigns/{id}', 'Status and counts (sent/delivered/read/failed/replies) of one campaign.', ['id' => $int], ['id']],
            'campaign_create'        => ['POST', 'campaigns', 'Create a campaign DRAFT from an approved template and an audience (tag_ids, contact_ids or all). Sends nothing.', ['name' => $str, 'template_id' => $int, 'variables' => ['type' => 'object'], 'tag_ids' => $ids, 'contact_ids' => $ids, 'all' => ['type' => 'boolean']], ['name', 'template_id']],
            'campaign_start'         => ['POST', 'campaigns/{id}/start', 'Start a draft campaign NOW (real WhatsApp messages). First call without confirm returns the audience; ask the user, then repeat with confirm=true.', ['id' => $int, 'confirm' => ['type' => 'boolean']], ['id']],
            'campaign_schedule'      => ['POST', 'campaigns/{id}/schedule', 'Schedule a draft campaign ("YYYY-MM-DD HH:MM"). Needs confirm=true after the user agrees.', ['id' => $int, 'scheduled_at' => $str, 'confirm' => ['type' => 'boolean']], ['id', 'scheduled_at']],
            'campaign_pause'         => ['POST', 'campaigns/{id}/pause', 'Pause a running campaign.', ['id' => $int], ['id']],
            'campaign_cancel'        => ['POST', 'campaigns/{id}/cancel', 'Cancel a campaign.', ['id' => $int], ['id']],
            'reports_summary'        => ['GET', 'reports/summary', 'Message totals and campaign delivery/read/fail rates between two dates (default this month).', ['from' => $str, 'to' => $str]],
            'ads_list'               => ['GET', 'ads', 'Ad campaigns with status, spend and chats.', []],
            'ads_pause'              => ['POST', 'ads/{id}/pause', 'Pause an ad campaign.', ['id' => $int], ['id']],
            'automations_list'       => ['GET', 'automations', 'List workflows (automations) with trigger and on/off state. all=1 includes inactive.', ['all' => ['type' => 'integer']]],
            'automation_schema'      => ['GET', 'automations/schema', 'READ THIS FIRST before building a workflow: the exact flow_graph format (nodes, edges, ports), every action node with its data keys, condition presets, WhatsApp limits and a complete example.', []],
            'agents_list'            => ['GET', 'automations/agents', 'Team members (id, name, role) a workflow can assign a chat to. Use an id as user_id in an assign_agent node.', []],
            'automation_get'         => ['GET', 'automations/{id}', 'One workflow with its trigger, canvas graph (flow_graph) and rules. An old workflow with no saved graph gets one rebuilt from its rules (flow_graph_derived = true), so it can serve as an example.', ['id' => $int], ['id']],
            'automation_create'      => ['POST', 'automations', 'Create a workflow (stays OFF until activated). trigger_type e.g. incoming_message; optional trigger_config object and flow_graph {nodes, edges}. Call automation_schema first for the exact node format and a working example.', ['name' => $str, 'trigger_type' => $str, 'trigger_config' => ['type' => 'object'], 'priority' => $int, 'flow_graph' => ['type' => 'object']], ['name']],
            'automation_update'      => ['POST', 'automations/{id}', 'Change a workflow: name, trigger_type, trigger_config, priority, is_active and/or flow_graph.', ['id' => $int, 'name' => $str, 'trigger_type' => $str, 'trigger_config' => ['type' => 'object'], 'priority' => $int, 'is_active' => ['type' => 'boolean'], 'flow_graph' => ['type' => 'object']], ['id']],
            'automation_duplicate'   => ['POST', 'automations/{id}/duplicate', 'Duplicate a workflow with its rules; the copy stays off.', ['id' => $int], ['id']],
            'automation_toggle'      => ['POST', 'automations/{id}/toggle', 'Switch a workflow on or off.', ['id' => $int], ['id']],
            'automations_trigger'    => ['POST', 'automations/trigger', 'Fire an automation event for a contact (phone or contact_id).', ['event' => $str, 'phone' => $str, 'contact_id' => $int, 'name' => $str, 'data' => ['type' => 'object']], ['event']],
            'social_create_image'    => ['POST', 'social/ai/image', 'Make a picture with the app image AI and add it to the library; returns asset_id. Needs an image AI key in the app.', ['prompt' => $str], ['prompt']],
            'social_publish_image'   => ['POST', 'social/publish', 'ONE call to post a picture to Instagram/Facebook: asset_id (library) or a public https image_url, accounts ids, caption, hashtags, optional when "YYYY-MM-DD HH:MM" (default: in 2 minutes). Real post: confirm with the user first.', ['asset_id' => $int, 'image_file' => $file, 'image_url' => $str, 'accounts' => $ids, 'caption' => $str, 'hashtags' => $str, 'when' => $str], ['accounts', 'caption']],
            'social_create_plan'     => ['POST', 'social/plans', 'AI writes a multi-day Instagram/Facebook post plan (nothing is posted). Needs category, brief, days, start_date, post_time HH:MM, accounts, tone, language.', ['category' => $str, 'brief' => $str, 'days' => $int, 'start_date' => $str, 'post_time' => $str, 'accounts' => $ids, 'tone' => $str, 'language' => $str], ['category', 'start_date', 'accounts']],
            'social_get_plan'        => ['GET', 'social/plans/{id}', 'Show a plan and its day-by-day items.', ['id' => $int], ['id']],
            'social_plan_image'      => ['POST', 'social/plans/items/{id}/image', 'Make the AI picture for one plan item.', ['id' => $int], ['id']],
            'social_approve_plan'    => ['POST', 'social/plans/{id}/approve', 'Schedule every post of a plan. Real posts: confirm with the user, then pass confirm=true.', ['id' => $int, 'confirm' => ['type' => 'boolean']], ['id']],
            'template_get'           => ['GET', 'templates/{id}', 'One template by id or name: body, variables, status.', ['id' => $str], ['id']],
            'contact_consent'        => ['POST', 'contacts/{id}/consent', 'Record WhatsApp opt-in or opt-out for a contact (id or phone). Only with the real consent of the contact.', ['id' => $str, 'opt_in' => ['type' => 'boolean'], 'source' => $str], ['id', 'opt_in']],
            'send_media_message'     => ['POST', 'messages/send-media', 'Send an image/document/video/audio by public https URL (24h window). Real message: confirm with the user first.', ['to' => $str, 'type' => $str, 'url' => $str, 'caption' => $str, 'filename' => $str], ['to', 'url']],
            'send_template_message'  => ['POST', 'messages/send-template', 'Send an approved WhatsApp template. Sends a real message: confirm with the user first.', ['to' => $str, 'template_name' => $str, 'language' => $str, 'variables' => ['type' => 'array', 'items' => $str], 'header_media_url' => $str], ['to', 'template_name']],
            'send_text_message'      => ['POST', 'messages/send-text', 'Send free-form text (only inside the 24h window). Sends a real message: confirm with the user first.', ['to' => $str, 'text' => $str], ['to', 'text']],
            'inbox_list'             => ['GET', 'messages', 'List recent messages; direction=inbound for incoming only, or filter by phone.', ['phone' => $str, 'direction' => $str, 'after_id' => $int, 'limit' => $int]],
            'message_status'         => ['GET', 'messages/{id}/status', 'Delivery status of a sent message.', ['id' => $str], ['id']],
            'social_list_accounts'   => ['GET', 'social/accounts', 'List connected social accounts with their ids.', []],
            'social_list_library'    => ['GET', 'social/library', 'List photos/videos in the media library.', []],
            'social_list_posts'      => ['GET', 'social/posts', 'List scheduled/published posts between two dates.', ['from' => $str, 'to' => $str]],
            'social_upload_image'    => ['POST', 'social/media', 'Add a picture (JPEG/PNG, max 10 MB) to the library from a public https image_url or image_base64; returns asset_id for social_schedule_media_post.', ['image_file' => $file, 'image_url' => $str, 'image_base64' => $str, 'filename' => $str]],
            'social_schedule_text_post'  => ['POST', 'social/text-post', 'Schedule a text-only post (Facebook, LinkedIn, WhatsApp; not Instagram/YouTube).', ['accounts' => $ids, 'when' => $str, 'caption' => $str, 'hashtags' => $str], ['accounts', 'when', 'caption']],
            'social_schedule_media_post' => ['POST', 'social/schedule', 'Schedule library photo/video posts on accounts. times are "HH:MM", start_date "YYYY-MM-DD".', ['assets' => $ids, 'accounts' => $ids, 'start_date' => $str, 'times' => ['type' => 'array', 'items' => $str], 'caption' => $str, 'hashtags' => $str], ['assets', 'accounts', 'start_date', 'times']],
            'social_reschedule_post' => ['POST', 'social/targets/{target_id}/reschedule', 'Move a scheduled post target to a new time ("YYYY-MM-DD HH:MM").', ['target_id' => $int, 'when' => $str], ['target_id', 'when']],
            'social_cancel_post'     => ['POST', 'social/targets/{target_id}/cancel', 'Cancel a scheduled post target.', ['target_id' => $int], ['target_id']],
            'social_retry_post'      => ['POST', 'social/targets/{target_id}/retry', 'Retry a failed post target.', ['target_id' => $int], ['target_id']],
            'social_delete_post'     => ['POST', 'social/targets/{target_id}/delete', 'Delete a post target from the app (not from the platform).', ['target_id' => $int], ['target_id']],
            'social_suggest_captions' => ['POST', 'social/ai/captions', 'Generate AI caption suggestions for a topic.', ['topic' => $str, 'platform' => $str, 'tone' => $str, 'language' => $str], ['topic']],
        ];
    }

    /** GET /api/v1/mcp: this server has no event stream; the spec allows 405. */
    public function stream(): ResponseInterface
    {
        return $this->response->setStatusCode(405)->setHeader('Allow', 'POST')->setJSON(['error' => 'Use POST.']);
    }

    /** POST /api/v1/mcp */
    public function handle(): ResponseInterface
    {
        if ($this->apiKey() === '') {
            return $this->response->setStatusCode(401)->setJSON(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32001, 'message' => 'Missing API key.']]);
        }
        $msg    = $this->request->getJSON(true);
        $method = is_array($msg) ? (string) ($msg['method'] ?? '') : '';
        if ($method === '') {
            return $this->rpcError(null, -32600, 'Invalid request.');
        }
        $id = $msg['id'] ?? null;
        if ($id === null) { // notification: no reply body
            return $this->response->setStatusCode(202);
        }

        return match ($method) {
            'initialize' => $this->rpcResult($id, [
                'protocolVersion' => in_array((string) ($msg['params']['protocolVersion'] ?? ''), self::VERSIONS, true) ? (string) $msg['params']['protocolVersion'] : self::PROTOCOL,
                'capabilities'    => ['tools' => (object) []],
                'serverInfo'      => ['name' => 'sateri-connect', 'version' => '1.0.0'],
            ]),
            'ping'       => $this->rpcResult($id, (object) []),
            'tools/list' => $this->rpcResult($id, ['tools' => $this->toolList()]),
            'tools/call' => $this->callTool($id, (array) ($msg['params'] ?? [])),
            default      => $this->rpcError($id, -32601, 'Method not found.'),
        };
    }

    /** @return list<array<string, mixed>> */
    private function toolList(): array
    {
        $out = [];
        foreach ($this->tools() as $name => $t) {
            $schema = ['type' => 'object', 'properties' => $t[3] === [] ? (object) [] : $t[3]];
            if (! empty($t[4])) {
                $schema['required'] = $t[4];
            }
            $tool = [
                'name'        => $name,
                'description' => $t[2],
                'inputSchema' => $schema,
                'annotations' => ['readOnlyHint' => $t[0] === 'GET', 'destructiveHint' => (bool) preg_match('/_(delete|cancel)(_|$)/', $name)],
            ];
            if (isset($t[3]['image_file'])) {
                $tool['_meta'] = ['openai/fileParams' => ['image_file']]; // ChatGPT sends the chat picture as {download_url, file_id}
            }
            $out[] = $tool;
        }

        return $out;
    }

    /** @param array<string, mixed> $params */
    private function callTool(mixed $id, array $params): ResponseInterface
    {
        $tool = $this->tools()[(string) ($params['name'] ?? '')] ?? null;
        if ($tool === null) {
            return $this->rpcError($id, -32602, 'Unknown tool.');
        }

        [$httpMethod, $path] = $tool;
        $args = (array) ($params['arguments'] ?? []);
        if (isset($args['image_file']['download_url']) && empty($args['image_url'])) {
            $args['image_url'] = (string) $args['image_file']['download_url'];
        }
        unset($args['image_file']);
        $path = (string) preg_replace_callback('/\{(\w+)\}/', static function (array $m) use (&$args): string {
            $v = rawurlencode((string) ($args[$m[1]] ?? ''));
            unset($args[$m[1]]);

            return $v;
        }, $path);

        $headers = ['X-API-Key' => $this->apiKey(), 'Accept' => 'application/json'];
        $tenant  = trim((string) ($this->request->getHeaderLine('X-Tenant-Key') ?: $this->request->getGet('tenant')));
        if ($tenant !== '') {
            $headers['X-Tenant-Key'] = $tenant;
        }

        try {
            $opts = ['headers' => $headers, 'timeout' => 60, 'http_errors' => false];
            if ($httpMethod === 'GET') {
                $url = site_url('api/v1/' . $path) . ($args !== [] ? '?' . http_build_query($args) : '');
            } else {
                $url          = site_url('api/v1/' . $path);
                $opts['json'] = (object) $args;
            }
            $res  = \Config\Services::curlrequest(['baseURI' => ''], null, null, false)->request($httpMethod, $url, $opts);
            $body = (string) $res->getBody();
            $ok   = $res->getStatusCode() < 400;
        } catch (Throwable $e) {
            log_message('error', 'McpController tool ' . $params['name'] . ' failed: ' . $e->getMessage());
            $body = '{"status":"error","message":"The tool could not reach the service."}';
            $ok   = false;
        }

        return $this->rpcResult($id, ['isError' => ! $ok, 'content' => [['type' => 'text', 'text' => $body]]]);
    }

    private function apiKey(): string
    {
        $key = trim((string) $this->request->getHeaderLine('X-API-Key'));
        if ($key === '' && preg_match('/Bearer\s+(\S+)/i', (string) $this->request->getHeaderLine('Authorization'), $m) === 1) {
            $key = $m[1];
        }

        return $key;
    }

    private function rpcResult(mixed $id, mixed $result): ResponseInterface
    {
        return $this->response->setJSON(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    private function rpcError(mixed $id, int $code, string $message): ResponseInterface
    {
        return $this->response->setJSON(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
    }
}
