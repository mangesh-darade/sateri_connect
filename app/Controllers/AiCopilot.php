<?php

declare(strict_types=1);

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * Controller for Global AI Copilot.
 * Context-aware AI Assistant across Sateri Connect.
 */
class AiCopilot extends BaseController
{
    /**
     * Handle user instruction / prompt to Copilot.
     */
    public function ask(): ResponseInterface
    {
        if (! session('user_id')) {
            return $this->jsonResponse(false, null, 'Unauthenticated. Please log in.', [], 401);
        }

        // Rate limiting: max 15 requests/min per user, max 30 requests/min per IP
        $userId = (int) session('user_id');
        $cache = service('cache');

        $userThrottleKey = 'copilot_throttle_u_' . $userId;
        $userAttempts = (int) ($cache->get($userThrottleKey) ?? 0);
        if ($userAttempts >= 15) {
            return $this->jsonResponse(false, null, 'Rate limit exceeded. Please wait a minute before sending another prompt.', [], 429);
        }

        $ipThrottleKey = 'copilot_throttle_ip_' . md5($this->request->getIPAddress());
        $ipAttempts = (int) ($cache->get($ipThrottleKey) ?? 0);
        if ($ipAttempts >= 30) {
            return $this->jsonResponse(false, null, 'Too many requests from this IP. Please wait a minute.', [], 429);
        }

        $cache->save($userThrottleKey, $userAttempts + 1, 60);
        $cache->save($ipThrottleKey, $ipAttempts + 1, 60);

        $input = $this->requestInput();
        $prompt = trim((string) ($input['prompt'] ?? ''));
        // Sanitize control characters / null bytes
        $prompt = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $prompt);

        if ($prompt === '') {
            return $this->jsonResponse(false, null, 'Please provide an instruction or question.', [], 422);
        }

        // Prevent excessively large prompts (token exhaustion / DoS defense)
        if (mb_strlen($prompt) > 1000) {
            return $this->jsonResponse(false, null, 'Prompt exceeds maximum limit of 1000 characters.', [], 422);
        }

        // Validate context payload
        $context = is_array($input['context'] ?? null) ? $input['context'] : [];
        if (! empty($context['current_flow']['nodes']) && is_array($context['current_flow']['nodes'])) {
            // Cap nodes array to 60 to prevent payload stuffing / memory overload
            $context['current_flow']['nodes'] = array_slice($context['current_flow']['nodes'], 0, 60);
        }

        $aiService = service('aiService');
        $res = $aiService->copilotAssist($prompt, $context);

        $userId = (int) session('user_id');

        // Persist history to database
        try {
            $logModel = model(\App\Models\AiCopilotLogModel::class);
            $logModel->insert([
                'user_id'     => $userId,
                'screen'      => mb_substr((string) ($context['screen'] ?? 'general'), 0, 60),
                'page_url'    => mb_substr((string) ($context['page_url'] ?? ''), 0, 255),
                'prompt'      => $prompt,
                'reply'       => (string) ($res['reply'] ?? ''),
                'thinking'    => ! empty($res['thinking']) ? (string) $res['thinking'] : null,
                'action_type' => (string) ($res['action_type'] ?? 'none'),
                'action_data' => ! empty($res['action_data']) ? json_encode($res['action_data'], JSON_UNESCAPED_UNICODE) : null,
            ]);

            (new \App\Libraries\ActivityLogger())->log(
                'copilot_prompt',
                'ai_copilot',
                'AI Copilot Prompt: ' . mb_substr($prompt, 0, 80),
                ['action_type' => $res['action_type'] ?? 'none', 'screen' => $context['screen'] ?? 'general']
            );
        } catch (\Throwable $e) {
            log_message('error', 'Failed to save AI Copilot log: ' . $e->getMessage());
        }

        if (! empty($res['success'])) {
            return $this->jsonResponse(true, $res, $res['reply']);
        }

        return $this->jsonResponse(false, $res, $res['reply'] ?? 'Failed to process request.', [], 400);
    }

    /**
     * Get recent prompt history for logged-in user.
     */
    public function history(): ResponseInterface
    {
        if (! session('user_id')) {
            return $this->jsonResponse(false, null, 'Unauthenticated.', [], 401);
        }

        $userId = (int) session('user_id');
        $logModel = model(\App\Models\AiCopilotLogModel::class);
        $history = $logModel->getRecentHistory($userId, 15);

        return $this->jsonResponse(true, $history, 'History loaded.');
    }
}
