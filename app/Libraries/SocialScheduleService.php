<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Libraries\Social\WhatsAppBroadcastPublisher;
use App\Models\SocialAccountModel;
use App\Models\SocialMediaAssetModel;
use App\Models\SocialPostModel;
use App\Models\SocialPostTargetModel;
use DateTimeImmutable;
use Throwable;

/**
 * Bulk scheduling ("20 posts over 20 days"), calendar data and per-target actions.
 * Guards: no asset is scheduled twice, a per-account daily cap, and nothing in the past.
 */
class SocialScheduleService
{
    public const MAX_PER_ACCOUNT_PER_DAY = 5;
    public const MAX_BATCH               = 100;

    private SocialPostModel $posts;
    private SocialPostTargetModel $targets;
    private SocialAccountModel $accounts;
    private SocialMediaAssetModel $assets;

    public function __construct()
    {
        $this->posts    = model(SocialPostModel::class);
        $this->targets  = model(SocialPostTargetModel::class);
        $this->accounts = model(SocialAccountModel::class);
        $this->assets   = model(SocialMediaAssetModel::class);
    }

    /**
     * @param list<int>    $assetIds
     * @param list<int>    $accountIds
     * @param list<string> $times  HH:MM slots used each day, e.g. ['10:00','18:00']
     * @param array<string, mixed> $whatsapp template_id, tag_ids, all, caption_var (needed only when a WhatsApp target is selected)
     * @param array<int|string, mixed> $captions [assetId][platform] => full caption text (AI or edited); replaces the shared caption for that file on that platform
     * @return array<string, mixed>
     */
    public function createBulk(array $assetIds, array $accountIds, string $startDate, array $times, string $caption, string $hashtags, ?int $userId, array $whatsapp = [], array $captions = []): array
    {
        $own = [];
        foreach ($captions as $assetKey => $perPlatform) {
            foreach ((array) $perPlatform as $platform => $text) {
                $text = mb_substr(trim((string) $text), 0, 2200);
                // "_title" is the post title for that file (YouTube title, calendar name); the other keys are platforms.
                if ($text !== '' && preg_match('/^(_title|[a-z]{3,12})$/', (string) $platform) === 1) {
                    $own[(int) $assetKey][(string) $platform] = $platform === '_title' ? mb_substr($text, 0, 100) : $text;
                }
            }
        }

        $assetIds   = array_values(array_unique(array_filter(array_map('intval', $assetIds))));
        $accountIds = array_values(array_unique(array_filter(array_map('intval', $accountIds))));
        $times      = array_values(array_filter(array_map('trim', $times), static fn ($t): bool => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) === 1));

        if ($assetIds === [] || $accountIds === []) {
            return $this->err(422, 'Select at least one file and one account.');
        }
        if (count($assetIds) > self::MAX_BATCH) {
            return $this->err(422, 'You can schedule at most ' . self::MAX_BATCH . ' files at once.');
        }
        if ($times === []) {
            return $this->err(422, 'Add at least one posting time, like 10:00.');
        }
        if (count($times) > self::MAX_PER_ACCOUNT_PER_DAY) {
            return $this->err(422, 'At most ' . self::MAX_PER_ACCOUNT_PER_DAY . ' posts per account per day.');
        }
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate);
        if ($start === false) {
            return $this->err(422, 'Choose a valid start date.');
        }
        sort($times);

        $accounts = $this->accounts->whereIn('id', $accountIds)->where('status', 'active')->findAll();
        if (count($accounts) !== count($accountIds)) {
            return $this->err(422, 'One of the selected accounts is not connected anymore.');
        }
        $wa = $this->whatsAppOptions($accounts, $whatsapp);
        if (! $wa['ok']) {
            return $this->err(422, $wa['message']);
        }
        $waOptions = $wa['json'];
        $assets = $this->assets->whereIn('id', $assetIds)->findAll();
        $byId   = array_column($assets, null, 'id');

        // YouTube takes video only: stop here instead of letting the post fail at publish time.
        $hasYouTube = false;
        foreach ($accounts as $acc) {
            $hasYouTube = $hasYouTube || ($acc['platform'] ?? '') === 'youtube';
        }
        if ($hasYouTube) {
            foreach ($assets as $asset) {
                if (! str_starts_with((string) ($asset['mime'] ?? ''), 'video/')) {
                    return $this->err(422, 'YouTube needs videos. "' . $asset['filename'] . '" is a photo; untick the YouTube account or choose only videos.');
                }
            }
        }

        // Instagram feed posts must be between 4:5 and 1.91:1: stop here instead of failing at publish time.
        $hasInstagram = false;
        foreach ($accounts as $acc) {
            $hasInstagram = $hasInstagram || ($acc['platform'] ?? '') === 'instagram';
        }
        if ($hasInstagram) {
            foreach ($assets as $asset) {
                $w = (int) ($asset['width'] ?? 0);
                $h = (int) ($asset['height'] ?? 0);
                if (str_starts_with((string) ($asset['mime'] ?? ''), 'image/') && $w > 0 && $h > 0 && ($w / $h < 0.8 || $w / $h > 1.91)) {
                    return $this->err(422, 'Instagram needs a picture between 4:5 (portrait) and 1.91:1 (wide). "' . $asset['filename'] . '" is ' . $w . 'x' . $h . '; crop it, or untick the Instagram account.');
                }
            }
        }

        $used = $this->posts->select('asset_id')->whereIn('asset_id', $assetIds)->where('status !=', 'failed')->findColumn('asset_id') ?? [];
        $used = array_map('intval', $used);

        $created = 0;
        $skipped = [];
        $slot    = 0;
        $now     = time();

        $db = db_connect();
        $db->transStart();
        try {
            foreach ($assetIds as $aid) {
                $asset = $byId[$aid] ?? null;
                if (! is_array($asset)) {
                    continue;
                }
                if (in_array($aid, $used, true)) {
                    $skipped[] = $asset['filename'] . ' (already scheduled)';
                    continue;
                }

                // slotTime is wall time in the client's timezone; the database stores UTC.
                $at = AppDateTime::localToStorage(self::slotTime($start, $times, $slot)) ?? '';
                $slot++;
                if ($at === '' || strtotime($at . ' UTC') <= $now) {
                    $skipped[] = $asset['filename'] . ' (time is in the past)';
                    continue;
                }

                $isVideo = str_starts_with((string) $asset['mime'], 'video/');
                $title   = trim((string) preg_replace('/[_\-]+/', ' ', pathinfo((string) $asset['filename'], PATHINFO_FILENAME)));
                $postId  = (int) $this->posts->insert([
                    'asset_id'   => $aid,
                    'type'       => $isVideo ? 'reel' : 'post',
                    'title'      => mb_substr($own[$aid]['_title'] ?? $title, 0, 191),
                    'caption'    => str_replace('{title}', $title, $caption),
                    'hashtags'   => mb_substr($hashtags, 0, 500),
                    'status'     => 'scheduled',
                    'created_by' => $userId,
                ], true);

                foreach ($accounts as $acc) {
                    // Instagram has no text-only or photo+video mixup: videos become Reels, images become posts. Both are fine.
                    $this->targets->insert([
                        'post_id'      => $postId,
                        'account_id'   => (int) $acc['id'],
                        'caption_override' => $own[$aid][(string) ($acc['platform'] ?? '')] ?? null,
                        'scheduled_at' => $at,
                        'status'       => 'scheduled',
                        'options'      => ($acc['platform'] ?? '') === 'whatsapp' ? $waOptions : null,
                    ]);
                }
                $created++;
            }
            $db->transComplete();
        } catch (Throwable $e) {
            $db->transRollback();
            log_message('error', 'SocialScheduleService::createBulk ' . $e::class . ': ' . $e->getMessage());

            return $this->err(500, 'Could not schedule the posts. Please try again.');
        }

        if ($created === 0) {
            return $this->err(422, 'Nothing was scheduled. ' . implode('; ', array_slice($skipped, 0, 3)));
        }
        log_activity('social_bulk_scheduled', 'social', 'Scheduled ' . $created . ' social post(s)', ['posts' => $created, 'accounts' => $accountIds, 'skipped' => count($skipped)]);

        return ['ok' => true, 'status' => 200, 'message' => $created . ' post(s) scheduled.' . ($skipped !== [] ? ' Skipped: ' . count($skipped) . '.' : ''), 'data' => ['created' => $created, 'skipped' => $skipped]];
    }

    /**
     * One text-only post at one time. Instagram and YouTube need media, so only Facebook, LinkedIn and WhatsApp are allowed.
     *
     * @param list<int>            $accountIds
     * @param array<string, mixed> $whatsapp
     * @return array<string, mixed>
     */
    public function createText(array $accountIds, string $when, string $caption, string $hashtags, ?int $userId, array $whatsapp = []): array
    {
        $accountIds = array_values(array_unique(array_filter(array_map('intval', $accountIds))));
        $when       = (string) AppDateTime::localToStorage($when);
        $ts         = $when === '' ? false : strtotime($when . ' UTC');
        if (trim($caption) === '') {
            return $this->err(422, 'Write the post text.');
        }
        if ($accountIds === []) {
            return $this->err(422, 'Select at least one account.');
        }
        if ($ts === false || $ts <= time()) {
            return $this->err(422, 'Choose a time in the future.');
        }

        $accounts = $this->accounts->whereIn('id', $accountIds)->where('status', 'active')->findAll();
        if (count($accounts) !== count($accountIds)) {
            return $this->err(422, 'One of the selected accounts is not connected anymore.');
        }
        foreach ($accounts as $acc) {
            if (in_array($acc['platform'], ['instagram', 'youtube'], true)) {
                return $this->err(422, ucfirst((string) $acc['platform']) . ' needs a photo or video. Use the Media Library for it.');
            }
        }
        $wa = $this->whatsAppOptions($accounts, $whatsapp);
        if (! $wa['ok']) {
            return $this->err(422, $wa['message']);
        }

        $at = $when;
        $db = db_connect();
        $db->transStart();
        try {
            $postId = (int) $this->posts->insert([
                'type'       => 'post',
                'title'      => mb_substr(trim($caption), 0, 60),
                'caption'    => $caption,
                'hashtags'   => mb_substr($hashtags, 0, 500),
                'status'     => 'scheduled',
                'created_by' => $userId,
            ], true);
            foreach ($accounts as $acc) {
                $this->targets->insert([
                    'post_id'      => $postId,
                    'account_id'   => (int) $acc['id'],
                    'scheduled_at' => $at,
                    'status'       => 'scheduled',
                    'options'      => $acc['platform'] === 'whatsapp' ? $wa['json'] : null,
                ]);
            }
            $db->transComplete();
        } catch (Throwable $e) {
            $db->transRollback();
            log_message('error', 'SocialScheduleService::createText ' . $e::class . ': ' . $e->getMessage());

            return $this->err(500, 'Could not schedule the post. Please try again.');
        }
        log_activity('social_text_scheduled', 'social', 'Scheduled a text post', ['post_id' => $postId, 'accounts' => $accountIds]);

        return ['ok' => true, 'status' => 200, 'message' => 'Post scheduled.', 'data' => ['post_id' => $postId]];
    }

    /**
     * Validates and encodes the WhatsApp broadcast settings when a WhatsApp account is among the selected ones.
     *
     * @param list<array<string, mixed>> $accounts
     * @param array<string, mixed>       $whatsapp
     * @return array{ok: bool, message: string, json: ?string}
     */
    private function whatsAppOptions(array $accounts, array $whatsapp): array
    {
        foreach ($accounts as $acc) {
            if (($acc['platform'] ?? '') !== 'whatsapp') {
                continue;
            }
            $check = WhatsAppBroadcastPublisher::validateOptions($whatsapp);
            if (! $check['ok']) {
                return ['ok' => false, 'message' => $check['message'], 'json' => null];
            }

            return ['ok' => true, 'message' => '', 'json' => (string) json_encode([
                'template_id' => (int) $whatsapp['template_id'],
                'tag_ids'     => array_values(array_filter(array_map('intval', (array) ($whatsapp['tag_ids'] ?? [])))),
                'all'         => ! empty($whatsapp['all']),
                'caption_var' => ! empty($whatsapp['caption_var']),
            ])];
        }

        return ['ok' => true, 'message' => '', 'json' => null];
    }

    /**
     * Date-time of the Nth post: one file per time slot, moving to the next day after the last slot.
     *
     * @param list<string> $times sorted HH:MM slots
     */
    public static function slotTime(DateTimeImmutable $start, array $times, int $slot): string
    {
        $day = intdiv($slot, count($times));

        return $start->modify('+' . $day . ' days')->format('Y-m-d') . ' ' . $times[$slot % count($times)] . ':00';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function calendar(string $from, string $to): array
    {
        $range = AppDateTime::rangeBoundsUtc($from, $to);
        $rows  = $this->targets
            ->select('social_post_targets.id, social_post_targets.status, social_post_targets.scheduled_at, social_post_targets.external_url, social_post_targets.last_error, social_post_targets.attempts, social_post_targets.progress, social_post_targets.progress_stage, p.title, p.type, a.platform, a.name AS account_name')
            ->join('social_posts p', 'p.id = social_post_targets.post_id')
            ->join('social_accounts a', 'a.id = social_post_targets.account_id')
            ->where('social_post_targets.scheduled_at >=', $range[0])
            ->where('social_post_targets.scheduled_at <=', $range[1])
            ->orderBy('social_post_targets.scheduled_at', 'ASC')
            ->findAll(1000);

        $rows = is_array($rows) ? $rows : [];
        foreach ($rows as &$row) {
            // Shown in the client's timezone.
            $row['scheduled_at'] = AppDateTime::format($row['scheduled_at'], 'Y-m-d H:i:s');
        }
        unset($row);

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function cancel(int $targetId): array
    {
        $t = $this->targets->find($targetId);
        if (! is_array($t) || ! in_array($t['status'], ['scheduled', 'failed'], true)) {
            return $this->err(409, 'Only scheduled or failed posts can be cancelled.');
        }
        $this->targets->update($targetId, ['status' => 'skipped']);
        $this->rollUp((int) $t['post_id']);
        log_activity('social_target_cancelled', 'social', 'Cancelled social target #' . $targetId, ['target_id' => $targetId]);

        return ['ok' => true, 'status' => 200, 'message' => 'Post cancelled.'];
    }

    /**
     * Removes a post from the calendar. With $alsoOnPlatform a published post is deleted on the platform first;
     * if that fails nothing is removed here, so the calendar never claims it is gone while it is still live.
     *
     * @return array<string, mixed>
     */
    public function delete(int $targetId, bool $alsoOnPlatform = false): array
    {
        $t = $this->targets->find($targetId);
        if (! is_array($t)) {
            return $this->err(404, 'Post not found.');
        }
        if ($t['status'] === 'publishing') {
            return $this->err(409, 'This post is being published right now. Try again in a minute.');
        }
        $remote = $alsoOnPlatform && $t['status'] === 'published';
        if ($remote) {
            $r = (new SocialPublishService())->removeRemote($targetId);
            if (! $r['ok']) {
                return $r;
            }
        }

        $postId = (int) $t['post_id'];
        $this->targets->delete($targetId);
        if ($this->targets->where('post_id', $postId)->countAllResults() === 0) {
            $this->posts->delete($postId);
        } else {
            $this->rollUp($postId);
        }
        log_activity('social_target_deleted', 'social', 'Deleted social target #' . $targetId, ['target_id' => $targetId, 'on_platform' => $remote]);

        return ['ok' => true, 'status' => 200, 'message' => $remote ? 'Deleted from the platform and the calendar.' : 'Removed from the calendar.'];
    }

    /**
     * @return array<string, mixed>
     */
    public function reschedule(int $targetId, string $when): array
    {
        $when = (string) AppDateTime::localToStorage($when);
        $ts   = $when === '' ? false : strtotime($when . ' UTC');
        $t    = $this->targets->find($targetId);
        if ($ts === false || $ts <= time()) {
            return $this->err(422, 'Choose a time in the future.');
        }
        if (! is_array($t) || ! in_array($t['status'], ['scheduled', 'failed', 'skipped'], true)) {
            return $this->err(409, 'This post can no longer be rescheduled.');
        }
        $this->targets->update($targetId, ['status' => 'scheduled', 'scheduled_at' => $when, 'attempts' => 0, 'last_error' => null]);
        $this->posts->update((int) $t['post_id'], ['status' => 'scheduled']);
        log_activity('social_target_rescheduled', 'social', 'Rescheduled social target #' . $targetId, ['target_id' => $targetId, 'at' => date('c', $ts)]);

        return ['ok' => true, 'status' => 200, 'message' => 'Post rescheduled.'];
    }

    /**
     * @return array<string, mixed>
     */
    public function retry(int $targetId): array
    {
        $t = $this->targets->find($targetId);
        if (! is_array($t) || $t['status'] !== 'failed') {
            return $this->err(409, 'Only failed posts can be retried.');
        }
        $this->targets->update($targetId, ['status' => 'scheduled', 'scheduled_at' => date('Y-m-d H:i:s'), 'attempts' => 0, 'last_error' => null]);
        $this->posts->update((int) $t['post_id'], ['status' => 'scheduled']);
        log_activity('social_target_retried', 'social', 'Retried social target #' . $targetId, ['target_id' => $targetId]);

        return ['ok' => true, 'status' => 200, 'message' => 'Queued to publish within a minute.'];
    }

    private function rollUp(int $postId): void
    {
        $s = array_count_values(array_column($this->targets->select('status')->where('post_id', $postId)->findAll(), 'status'));
        if (($s['scheduled'] ?? 0) + ($s['publishing'] ?? 0) > 0) {
            return;
        }
        $this->posts->update($postId, ['status' => ($s['published'] ?? 0) > 0 ? 'published' : 'failed']);
    }

    /**
     * @return array<string, mixed>
     */
    private function err(int $status, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'message' => $message];
    }
}
