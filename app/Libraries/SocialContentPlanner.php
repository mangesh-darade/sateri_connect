<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\SocialAccountModel;
use App\Models\SocialAiItemModel;
use App\Models\SocialAiPlanModel;
use App\Models\SocialMediaAssetModel;
use App\Models\SocialPostModel;
use DateTimeImmutable;
use Throwable;

/**
 * AI Content Planner. Claude plans one post per day for a category and goal (topic, picture description, caption,
 * hashtags), an image AI draws each picture, the user reviews, and approving turns every item into a normal scheduled
 * social post that social:publish sends. Nothing is scheduled before approval.
 * Every method returns ['ok','status','message','data'?].
 */
class SocialContentPlanner
{
    public const MAX_DAYS = 14;

    private const TONES     = ['friendly', 'professional', 'funny', 'inspiring', 'urgent'];
    private const LANGUAGES = ['English', 'Hindi', 'Marathi', 'Hinglish'];

    private SocialAiPlanModel $plans;
    private SocialAiItemModel $items;
    private SettingsService $settings;

    public function __construct(?SettingsService $settings = null)
    {
        $this->settings = $settings ?? new SettingsService();
        $this->plans    = model(SocialAiPlanModel::class);
        $this->items    = model(SocialAiItemModel::class);
    }

    /**
     * Claude writes the plan; the pictures are made later, one request each.
     *
     * @param array<string, mixed> $in category, brief, days, start_date, post_time, accounts[], tone, language
     * @return array<string, mixed>
     */
    public function createPlan(array $in, ?int $userId): array
    {
        $category = trim(mb_substr((string) ($in['category'] ?? ''), 0, 191));
        $brief    = trim(mb_substr((string) ($in['brief'] ?? ''), 0, 1500));
        $days     = max(1, min(self::MAX_DAYS, (int) ($in['days'] ?? 7)));
        $time     = trim((string) ($in['post_time'] ?? '10:00'));
        $tone     = in_array($in['tone'] ?? '', self::TONES, true) ? (string) $in['tone'] : 'friendly';
        $language = in_array($in['language'] ?? '', self::LANGUAGES, true) ? (string) $in['language'] : 'English';
        $start    = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($in['start_date'] ?? ''));

        if ($category === '') {
            return $this->err(422, 'Enter the category of the posts, like "Real estate" or "Fitness studio".');
        }
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
            return $this->err(422, 'Enter the posting time like 10:00.');
        }
        if ($start === false) {
            return $this->err(422, 'Choose a start date.');
        }
        $accountIds = array_values(array_unique(array_filter(array_map('intval', (array) ($in['accounts'] ?? [])))));
        $accounts   = $accountIds === [] ? [] : model(SocialAccountModel::class)->whereIn('id', $accountIds)->where('status', 'active')->findAll();
        if ($accounts === [] || count($accounts) !== count($accountIds)) {
            return $this->err(422, 'Choose at least one connected Instagram or Facebook account.');
        }
        foreach ($accounts as $acc) {
            if (! in_array((string) $acc['platform'], ['instagram', 'facebook'], true)) {
                return $this->err(422, 'The planner makes picture posts for Instagram and Facebook only.');
            }
        }

        $client = new SocialAiClient($this->settings);
        $system = 'You are the social media manager of ' . $this->businessName() . ". Plan {$days} picture posts, one per day, for Instagram and Facebook.\n"
            . "Category: {$category}. Language of captions: {$language}. Tone: {$tone}.\n"
            . "Make every day a different angle (tips, offer, behind the scenes, customer question, myth, story, call to action) so the week feels varied.\n"
            . "For each day return: topic (under 80 characters), image_prompt (in English, 2 to 3 sentences describing one clean photo or illustration: subject, setting, light, style, square format; no words, letters, logos or real people's faces in the picture), caption (starts with a scroll-stopping first line, ends with a clear next step when it fits), hashtags (one string, 5 to 8 relevant tags).\n"
            . "Be honest: no false claims, no fake urgency, no invented prices or statistics. Use [placeholders] for facts you do not know.\n"
            . 'Return ONLY valid JSON: {"items":[{"topic":"...","image_prompt":"...","caption":"...","hashtags":"#a #b"}]} with exactly ' . $days . ' items. No markdown.';
        $res = $client->generate($system, "What this is about:\n" . ($brief !== '' ? $brief : 'Regular posts for the category above.'), null, 6000);
        if (! $res['ok']) {
            return $res;
        }

        $rows = [];
        foreach ((array) ($this->decode((string) $res['text'])['items'] ?? []) as $o) {
            if (is_array($o) && trim((string) ($o['caption'] ?? '')) !== '' && trim((string) ($o['image_prompt'] ?? '')) !== '') {
                $rows[] = $o;
            }
        }
        if ($rows === []) {
            return $this->err(502, 'The AI returned an unexpected plan. Please try again.');
        }

        $db = db_connect();
        $db->transStart();
        try {
            $planId = (int) $this->plans->insert([
                'title'       => mb_substr($category . ' - ' . $start->format('d M Y'), 0, 191),
                'category'    => $category,
                'brief'       => $brief,
                'days'        => count($rows),
                'start_date'  => $start->format('Y-m-d'),
                'post_time'   => $time,
                'account_ids' => implode(',', $accountIds),
                'tone'        => $tone,
                'language'    => $language,
                'status'      => 'draft',
                'created_by'  => $userId,
            ], true);
            foreach (array_slice($rows, 0, $days) as $i => $o) {
                $this->items->insert([
                    'plan_id'      => $planId,
                    'day_no'       => $i + 1,
                    'post_date'    => $start->modify('+' . $i . ' day')->format('Y-m-d'),
                    'topic'        => mb_substr(trim((string) ($o['topic'] ?? 'Day ' . ($i + 1))) ?: 'Day ' . ($i + 1), 0, 191),
                    'image_prompt' => mb_substr(trim((string) $o['image_prompt']), 0, 1500),
                    'caption'      => mb_substr(trim((string) $o['caption']), 0, 2000),
                    'hashtags'     => mb_substr(trim((string) ($o['hashtags'] ?? '')), 0, 500),
                    'status'       => 'planned',
                ]);
            }
            $db->transComplete();
        } catch (Throwable $e) {
            $db->transRollback();
            log_message('error', 'SocialContentPlanner::createPlan ' . $e::class . ': ' . $e->getMessage());

            return $this->err(500, 'Could not save the plan. Please try again.');
        }
        log_activity('social_ai_plan_created', 'social', 'Created an AI content plan', ['plan_id' => $planId, 'days' => count($rows)]);

        return ['ok' => true, 'status' => 200, 'message' => 'Plan ready. Now the pictures are being made.', 'data' => ['plan_id' => $planId]];
    }

    /**
     * "Post something today" in plain words. Claude either asks one short question (usually which product) or, once it
     * knows enough, makes a one-post plan for a few minutes from now. The browser then makes the picture and shows the
     * preview; nothing is scheduled until the user approves.
     *
     * @param list<array{role: string, text: string}> $history earlier turns of this chat
     * @param list<int>                               $accountIds
     * @return array<string, mixed> data.action = 'ask' (data.question) or 'created' (data.plan_id)
     */
    public function quick(array $history, string $message, array $accountIds, ?int $userId): array
    {
        $message = trim(mb_substr($message, 0, 500));
        if ($message === '') {
            return $this->err(422, 'Tell me what you want to post.');
        }

        $turns = [];
        foreach (array_slice($history, -8) as $h) {
            $text = trim(mb_substr((string) ($h['text'] ?? ''), 0, 500));
            if ($text !== '') {
                $turns[] = ['role' => ($h['role'] ?? '') === 'assistant' ? 'assistant' : 'user', 'text' => $text];
            }
        }
        $turns[]  = ['role' => 'user', 'text' => $message];
        $userMsgs = array_values(array_map(static fn (array $t): string => $t['text'], array_filter($turns, static fn (array $t): bool => $t['role'] === 'user')));
        $mustMake = count($userMsgs) >= 3;

        $transcript = '';
        foreach ($turns as $t) {
            $transcript .= ($t['role'] === 'user' ? 'User: ' : 'You: ') . $t['text'] . "\n";
        }
        $system = 'You are the social media assistant of ' . $this->businessName() . ". The user wants one Instagram and Facebook post made today. They may write in English, Hindi, Marathi or Hinglish.\n"
            . "If you do not yet know WHICH product or offer the post is about, reply with ONE short friendly question in the user's own language and style. Never ask more than one question.\n"
            . ($mustMake ? "You have asked enough: you must now create the post from what you know.\n" : '')
            . "Once you know the product or offer, create the post. Return category (the product type, under 60 characters), brief (2 to 4 English sentences: the product, what to highlight, the audience, the mood of a good eye-catching design), language (English, Hindi, Marathi or Hinglish: the language the user wrote in, English if unsure), tone (friendly, professional, funny, inspiring or urgent).\n"
            . 'Return ONLY valid JSON: {"action":"ask","question":"..."} or {"action":"create","category":"...","brief":"...","language":"English","tone":"friendly"}. No markdown.';
        $res = (new SocialAiClient($this->settings))->generate($system, $transcript, null, 700);
        if (! $res['ok']) {
            return $res;
        }

        $d = $this->decode((string) $res['text']);
        if (($d['action'] ?? '') === 'ask' && ! $mustMake && trim((string) ($d['question'] ?? '')) !== '') {
            return ['ok' => true, 'status' => 200, 'message' => 'OK', 'data' => ['action' => 'ask', 'question' => mb_substr(trim((string) $d['question']), 0, 300)]];
        }
        $category = trim((string) ($d['category'] ?? ''));
        if (($d['action'] ?? '') !== 'create' || $category === '') {
            $category = mb_substr($userMsgs[count($userMsgs) - 1], 0, 60);
            $d        = ['brief' => implode('. ', $userMsgs), 'language' => 'English', 'tone' => 'friendly'];
        }

        // A few minutes from now in the client's own time zone.
        $at = new DateTimeImmutable('now', AppDateTime::appZone());
        $at = $at->modify('+3 minutes');

        $r = $this->createPlan([
            'category'   => $category,
            'brief'      => (string) ($d['brief'] ?? ''),
            'days'       => 1,
            'start_date' => $at->format('Y-m-d'),
            'post_time'  => $at->format('H:i'),
            'tone'       => $d['tone'] ?? 'friendly',
            'language'   => $d['language'] ?? 'English',
            'accounts'   => $accountIds,
        ], $userId);
        if (! $r['ok']) {
            return $r;
        }

        return ['ok' => true, 'status' => 200, 'message' => 'Making your post...', 'data' => ['action' => 'created', 'plan_id' => $r['data']['plan_id']]];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(int $planId): array
    {
        $plan = $this->plans->find($planId);
        if (! is_array($plan)) {
            return $this->err(404, 'Plan not found.');
        }
        $items = $this->items->where('plan_id', $planId)->orderBy('day_no')->findAll();

        return ['ok' => true, 'status' => 200, 'message' => 'OK', 'data' => ['plan' => $plan, 'items' => $items]];
    }

    /**
     * @return array<string, mixed>
     */
    public function generateImage(int $itemId): array
    {
        $item = $this->items->find($itemId);
        if (! is_array($item)) {
            return $this->err(404, 'Post not found.');
        }
        if (in_array($item['status'], ['approved', 'rejected'], true)) {
            return $this->err(409, 'This post is already ' . $item['status'] . '.');
        }

        $r = (new SocialImageGenerator($this->settings))->generate((string) $item['image_prompt']);
        if (! $r['ok']) {
            $this->items->update($itemId, ['status' => 'failed', 'error' => mb_substr($r['message'], 0, 255)]);

            return $r;
        }
        $this->removeFile((string) ($item['image_file'] ?? ''));
        $this->items->update($itemId, ['image_file' => $r['data']['file'], 'status' => 'ready', 'error' => null]);

        return ['ok' => true, 'status' => 200, 'message' => 'Picture ready.', 'data' => ['file' => $r['data']['file']]];
    }

    /**
     * @param array<string, mixed> $in topic, caption, hashtags, image_prompt
     * @return array<string, mixed>
     */
    public function updateItem(int $itemId, array $in): array
    {
        $item = $this->items->find($itemId);
        if (! is_array($item) || in_array($item['status'], ['approved', 'rejected'], true)) {
            return $this->err(404, 'This post can no longer be edited.');
        }
        $data = [
            'topic'        => mb_substr(trim((string) ($in['topic'] ?? $item['topic'])), 0, 191),
            'caption'      => mb_substr(trim((string) ($in['caption'] ?? $item['caption'])), 0, 2000),
            'hashtags'     => mb_substr(trim((string) ($in['hashtags'] ?? $item['hashtags'])), 0, 500),
            'image_prompt' => mb_substr(trim((string) ($in['image_prompt'] ?? $item['image_prompt'])), 0, 1500),
        ];
        if ($data['topic'] === '' || $data['caption'] === '') {
            return $this->err(422, 'The topic and caption cannot be empty.');
        }
        $this->items->update($itemId, $data);

        return ['ok' => true, 'status' => 200, 'message' => 'Saved.'];
    }

    /**
     * @return array<string, mixed>
     */
    public function reject(int $itemId): array
    {
        $item = $this->items->find($itemId);
        if (! is_array($item) || $item['status'] === 'approved') {
            return $this->err(404, 'This post cannot be removed.');
        }
        $this->removeFile((string) ($item['image_file'] ?? ''));
        $this->items->update($itemId, ['status' => 'rejected', 'image_file' => null]);

        return ['ok' => true, 'status' => 200, 'message' => 'Post removed from the plan.'];
    }

    /**
     * Turns every ready item into a library asset plus a scheduled post on the plan's accounts.
     *
     * @return array<string, mixed>
     */
    public function approve(int $planId, ?int $userId): array
    {
        $plan = $this->plans->find($planId);
        if (! is_array($plan)) {
            return $this->err(404, 'Plan not found.');
        }
        if ($plan['status'] === 'approved') {
            return $this->err(409, 'This plan is already approved.');
        }
        $items = $this->items->where('plan_id', $planId)->whereIn('status', ['ready', 'planned', 'failed'])->orderBy('day_no')->findAll();
        foreach ($items as $it) {
            if ($it['status'] !== 'ready') {
                return $this->err(422, 'Day ' . $it['day_no'] . ' has no picture yet. Make it, or remove that post, then approve.');
            }
        }
        if ($items === []) {
            return $this->err(422, 'There is nothing to approve.');
        }

        $accountIds = array_values(array_filter(array_map('intval', explode(',', (string) $plan['account_ids']))));
        $schedule   = new SocialScheduleService();
        $assets     = model(SocialMediaAssetModel::class);
        $posts      = model(SocialPostModel::class);
        $done       = 0;
        $failed     = [];

        foreach ($items as $it) {
            $path = SocialImageGenerator::dir() . $it['image_file'];
            if (! is_file($path)) {
                $this->items->update((int) $it['id'], ['status' => 'failed', 'error' => 'The picture file is missing. Make it again.']);
                $failed[] = 'Day ' . $it['day_no'] . ': picture missing';
                continue;
            }
            $size    = @getimagesize($path) ?: [0, 0];
            $assetId = (int) $assets->insert([
                'drive_file_id' => 'local:' . $it['image_file'],
                'filename'      => 'ai-plan' . $planId . '-day' . $it['day_no'] . '.' . pathinfo((string) $it['image_file'], PATHINFO_EXTENSION),
                'mime'          => (string) ($size['mime'] ?? 'image/jpeg'),
                'size_bytes'    => (int) filesize($path),
                'width'         => (int) $size[0],
                'height'        => (int) $size[1],
                'validation'    => json_encode(['reel_ready' => false, 'issues' => []]),
                'status'        => 'ready',
            ], true);

            // A "post today" item whose minute passed while the user reviewed it goes out a couple of minutes from now.
            $date = (string) $it['post_date'];
            $time = (string) $plan['post_time'];
            $now  = new DateTimeImmutable('now', AppDateTime::appZone());
            if ($date === $now->format('Y-m-d') && $time <= $now->modify('+1 minute')->format('H:i')) {
                $soon = $now->modify('+2 minutes');
                $date = $soon->format('Y-m-d');
                $time = $soon->format('H:i');
            }

            $r = $schedule->createBulk(
                [$assetId],
                $accountIds,
                $date,
                [$time],
                (string) $it['caption'],
                (string) $it['hashtags'],
                $userId,
                [],
                [$assetId => ['_title' => (string) $it['topic']]]
            );
            if (! $r['ok']) {
                $assets->delete($assetId);
                $this->items->update((int) $it['id'], ['status' => 'failed', 'error' => mb_substr((string) $r['message'], 0, 255)]);
                $failed[] = 'Day ' . $it['day_no'] . ': ' . $r['message'];
                continue;
            }
            $post = $posts->where('asset_id', $assetId)->orderBy('id', 'DESC')->first();
            $this->items->update((int) $it['id'], ['status' => 'approved', 'asset_id' => $assetId, 'post_id' => is_array($post) ? (int) $post['id'] : null, 'error' => null]);
            $done++;
        }

        if ($done === 0) {
            return $this->err(422, 'Nothing was scheduled. ' . implode('; ', array_slice($failed, 0, 3)));
        }
        if ($failed === []) {
            $this->plans->update($planId, ['status' => 'approved']);
        }
        log_activity('social_ai_plan_approved', 'social', 'Approved an AI content plan', ['plan_id' => $planId, 'scheduled' => $done, 'failed' => count($failed)]);

        return ['ok' => true, 'status' => 200, 'message' => $done . ' post(s) scheduled.' . ($failed !== [] ? ' Could not schedule ' . count($failed) . ': ' . implode('; ', array_slice($failed, 0, 3)) : ''), 'data' => ['scheduled' => $done, 'failed' => $failed]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentPlans(): array
    {
        $rows = $this->plans->orderBy('id', 'DESC')->findAll(20);

        return is_array($rows) ? $rows : [];
    }

    private function removeFile(string $file): void
    {
        if (preg_match('/^[a-f0-9]{32}\.(jpg|png)$/', $file) === 1) {
            @unlink(SocialImageGenerator::dir() . $file);
        }
    }

    private function businessName(): string
    {
        return trim((string) ($this->settings->getAiConfig()['business_name'] ?: 'our business'));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $text): array
    {
        $clean   = trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($text)));
        $decoded = json_decode($clean, true);
        if (! is_array($decoded) && preg_match('/\{.*\}/s', $clean, $m) === 1) {
            $decoded = json_decode($m[0], true);
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function err(int $status, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'message' => $message];
    }
}
