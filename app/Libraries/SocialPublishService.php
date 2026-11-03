<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Libraries\Social\FacebookPublisher;
use App\Libraries\Social\SocialRemovableInterface;
use App\Libraries\Social\InstagramPublisher;
use App\Libraries\Social\LinkedInPublisher;
use App\Libraries\Social\WhatsAppBroadcastPublisher;
use App\Libraries\Social\YouTubePublisher;
use App\Libraries\Social\SocialPublisherInterface;
use App\Models\SocialAccountModel;
use App\Models\SocialMediaAssetModel;
use App\Models\SocialPostModel;
use App\Models\SocialPostTargetModel;
use Throwable;

/**
 * Publishes due social_post_targets (see docs/SOCIAL_SCHEDULER.md). Called by `social:publish` for one tenant at a time.
 * Each target is claimed with a status transition (scheduled -> publishing) so overlapping cron runs never double-post.
 */
class SocialPublishService
{
    public const MAX_ATTEMPTS = 3;

    /** @var array<string, SocialPublisherInterface> */
    private array $publishers = [];

    private SocialPostTargetModel $targets;
    private SocialPostModel $posts;
    private SocialAccountModel $accounts;
    private SocialMediaAssetModel $assets;
    private SocialTokenService $tokens;
    private SocialMediaDelivery $delivery;
    private ?string $staged = null;

    /**
     * @param list<SocialPublisherInterface>|null $publishers
     */
    public function __construct(?array $publishers = null)
    {
        $this->targets    = model(SocialPostTargetModel::class);
        $this->posts      = model(SocialPostModel::class);
        $this->accounts   = model(SocialAccountModel::class);
        $this->assets     = model(SocialMediaAssetModel::class);
        $this->tokens     = new SocialTokenService();
        $this->delivery   = new SocialMediaDelivery();

        foreach ($publishers ?? [new FacebookPublisher(), new InstagramPublisher(), new YouTubePublisher(), new LinkedInPublisher(), new WhatsAppBroadcastPublisher()] as $p) {
            $this->publishers[$p->platform()] = $p;
        }
    }

    /**
     * @return array{ok: bool, status: int, message: string, data: array{published: int, failed: int, retried: int}}
     */
    public function publishDue(int $limit = 20): array
    {
        $count = ['published' => 0, 'failed' => 0, 'retried' => 0];

        try {
            // A run that was killed mid-way leaves "publishing" behind; show it as failed so it can be retried.
            $this->targets->builder()
                ->where('status', 'publishing')
                ->where('updated_at <', date('Y-m-d H:i:s', time() - 900))
                ->update(['status' => 'failed', 'last_error' => 'Publishing was interrupted. Use Retry now.', 'updated_at' => date('Y-m-d H:i:s')]);

            $due = $this->targets
                ->where('status', 'scheduled')
                ->where('scheduled_at <=', date('Y-m-d H:i:s'))
                ->orderBy('scheduled_at', 'ASC')
                ->findAll(max(1, $limit));

            foreach ($due as $target) {
                if (! $this->claim((int) $target['id'])) {
                    continue;
                }
                try {
                    $outcome = $this->publishOne($target);
                } catch (Throwable $e) {
                    // Never leave a post stuck on "publishing": record the failure and carry on with the next one.
                    log_message('error', 'SocialPublishService::publishOne target=' . $target['id'] . ' ' . $e::class . ': ' . $e->getMessage());
                    if ($this->staged !== null) {
                        $this->delivery->release($this->staged);
                        $this->staged = null;
                    }
                    $outcome = $this->finish($target, (int) $target['attempts'] + 1, ['ok' => false, 'status' => 500, 'message' => 'Publishing failed unexpectedly. Please try again.']);
                }
                $count[$outcome]++;
            }
        } catch (Throwable $e) {
            log_message('error', 'SocialPublishService::publishDue ' . $e::class . ': ' . $e->getMessage());

            return ['ok' => false, 'status' => 500, 'message' => 'Publishing run failed.', 'data' => $count];
        }

        return ['ok' => true, 'status' => 200, 'message' => 'Done', 'data' => $count];
    }

    /**
     * Publishes one scheduled or failed post right now and reports the real outcome (no cron or waiting needed).
     *
     * @return array{ok: bool, status: int, message: string}
     */
    public function publishNow(int $targetId): array
    {
        try {
            $t = $this->targets->find($targetId);
            if (! is_array($t) || ! in_array($t['status'], ['scheduled', 'failed'], true)) {
                return ['ok' => false, 'status' => 409, 'message' => 'Only scheduled or failed posts can be published now.'];
            }
            $this->targets->update($targetId, ['status' => 'scheduled', 'attempts' => 0, 'last_error' => null]);
            if (! $this->claim($targetId)) {
                return ['ok' => false, 'status' => 409, 'message' => 'This post is already being published.'];
            }
            set_time_limit(170);
            $t = $this->targets->find($targetId);
            try {
                $this->publishOne($t);
            } catch (Throwable $e) {
                log_message('error', 'SocialPublishService::publishNow target=' . $targetId . ' ' . $e::class . ': ' . $e->getMessage());
                if ($this->staged !== null) {
                    $this->delivery->release($this->staged);
                    $this->staged = null;
                }
                $this->finish($t, 1, ['ok' => false, 'status' => 500, 'message' => 'Publishing failed unexpectedly. Please try again.']);
            }

            $after = $this->targets->find($targetId);
            if (is_array($after) && $after['status'] === 'published') {
                return ['ok' => true, 'status' => 200, 'message' => 'Published successfully.'];
            }
            $why = trim((string) ($after['last_error'] ?? '')) ?: 'Publishing failed.';

            return ['ok' => false, 'status' => 422, 'message' => 'Could not publish: ' . $why];
        } catch (Throwable $e) {
            log_message('error', 'SocialPublishService::publishNow ' . $e::class . ': ' . $e->getMessage());

            return ['ok' => false, 'status' => 500, 'message' => 'Could not publish right now. Please try again.'];
        }
    }

    /**
     * Deletes what this target published on the platform itself.
     *
     * @return array{ok: bool, status: int, message: string}
     */
    public function removeRemote(int $targetId): array
    {
        $t = $this->targets->find($targetId);
        if (! is_array($t) || $t['status'] !== 'published' || (string) ($t['external_id'] ?? '') === '') {
            return ['ok' => false, 'status' => 409, 'message' => 'Nothing was published for this post.'];
        }
        $account   = $this->accounts->find((int) $t['account_id']);
        $platform  = is_array($account) ? (string) $account['platform'] : '';
        $publisher = $this->publishers[$platform] ?? null;
        if (! $publisher instanceof SocialRemovableInterface) {
            return ['ok' => false, 'status' => 501, 'message' => ucfirst($platform !== '' ? $platform : 'This platform') . ' does not allow deleting from here. Delete the post in the app itself, then remove it from the calendar.'];
        }
        try {
            $account = $this->tokens->prepare($account);
            if ($account === null) {
                return ['ok' => false, 'status' => 401, 'message' => 'Account login expired. Reconnect it in Social Accounts.'];
            }

            return $publisher->remove($account, (string) $t['external_id']);
        } catch (Throwable $e) {
            log_message('error', 'SocialPublishService::removeRemote ' . $e::class);

            return ['ok' => false, 'status' => 502, 'message' => 'Could not delete it from the platform. Please try again.'];
        }
    }

    /** The fileinfo extension is not enabled everywhere (it is missing in some CLI builds), so fall back to the file extension. */
    private static function mimeFor(string $path): string
    {
        if (function_exists('mime_content_type') && is_file($path)) {
            $m = @mime_content_type($path);
            if (is_string($m) && $m !== '') {
                return $m;
            }
        }
        $map = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
            'mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'm4v' => 'video/mp4', 'webm' => 'video/webm', 'avi' => 'video/x-msvideo'];

        return $map[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? '';
    }

    private function claim(int $id): bool
    {
        $this->targets->builder()
            ->where('id', $id)
            ->where('status', 'scheduled')
            ->update(['status' => 'publishing', 'progress' => 0, 'progress_stage' => 'Starting', 'updated_at' => date('Y-m-d H:i:s')]);

        return db_connect()->affectedRows() === 1;
    }

    private int $lastProgress = -1;
    private float $lastProgressAt = 0.0;

    /**
     * Writes the live progress the calendar shows. Throttled to about one write per second, so a big upload does not flood the database.
     */
    private function report(int $id, int $percent, string $stage, bool $force = false): void
    {
        $percent = max(0, min(99, $percent));
        $now     = microtime(true);
        if (! $force && ($percent === $this->lastProgress || $now - $this->lastProgressAt < 1.0)) {
            return;
        }
        $this->lastProgress   = $percent;
        $this->lastProgressAt = $now;
        $this->targets->builder()->where('id', $id)->where('status', 'publishing')
            ->update(['progress' => $percent, 'progress_stage' => $stage, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * @param array<string, mixed> $target
     * @return 'published'|'failed'|'retried'
     */
    private function publishOne(array $target): string
    {
        $id       = (int) $target['id'];
        $attempts = (int) $target['attempts'] + 1;

        $post    = $this->posts->find((int) $target['post_id']);
        $account = $this->accounts->find((int) $target['account_id']);

        if (! is_array($post) || ! is_array($account)) {
            return $this->finish($target, $attempts, ['ok' => false, 'status' => 404, 'message' => 'Post or account no longer exists.']);
        }
        if (($account['status'] ?? '') !== 'active') {
            return $this->finish($target, $attempts, ['ok' => false, 'status' => 401, 'message' => 'Account needs to be reconnected.']);
        }
        $publisher = $this->publishers[(string) $account['platform']] ?? null;
        if ($publisher === null) {
            return $this->finish($target, $attempts, ['ok' => false, 'status' => 501, 'message' => 'Publishing to ' . $account['platform'] . ' is not available yet.']);
        }

        $this->lastProgress = -1;
        $platformName       = ucfirst((string) $account['platform']);
        $media              = null;
        if (! empty($post['asset_id'])) {
            // 0-40%: copying the file from Google Drive.
            $media = $this->resolveMediaUrl((int) $post['asset_id'], fn (int $p) => $this->report($id, (int) ($p * 0.4), 'Downloading from Google Drive'));
            if ($media === null) {
                return $this->finish($target, $attempts, ['ok' => false, 'status' => 503, 'message' => 'The media file could not be prepared from Google Drive.']);
            }
            // Local-file publishers (YouTube, LinkedIn) read the staged copy directly.
            $post['media_path'] = SocialMediaDelivery::dir() . $this->staged;
            $post['media_mime'] = self::mimeFor($post['media_path']);
        }

        // 40-95%: the platform upload. YouTube reports real bytes; the others move in one call, so they show the stage.
        $this->report($id, 40, 'Sending to ' . $platformName, true);
        $post['on_progress'] = fn (int $p) => $this->report($id, 40 + (int) ($p * 0.55), 'Uploading to ' . $platformName);

        $opts = json_decode((string) ($target['options'] ?? ''), true);
        $post['target_options'] = is_array($opts) ? $opts : [];

        try {
            $account = $this->tokens->prepare($account);
            if ($account === null) {
                $result = ['ok' => false, 'status' => 401, 'message' => 'Account login expired. Reconnect it in Social Accounts.'];
            } else {
                $result = $publisher->publish($account, $post, $this->buildCaption($post, $target), $media);
            }
        } catch (Throwable $e) {
            log_message('error', 'SocialPublishService::publishOne target=' . $id . ' ' . $e::class);
            $result = ['ok' => false, 'status' => 502, 'message' => 'Publishing failed. Please try again.', 'retry' => true];
        }

        if ($this->staged !== null) {
            $this->delivery->release($this->staged);
            $this->staged = null;
        }

        return $this->finish($target, $attempts, $result);
    }

    /**
     * Saves the outcome, re-queues temporary failures with a back-off, and rolls the post status up.
     *
     * @param array<string, mixed> $target
     * @param array<string, mixed> $result
     * @return 'published'|'failed'|'retried'
     */
    private function finish(array $target, int $attempts, array $result): string
    {
        $id  = (int) $target['id'];
        $now = date('Y-m-d H:i:s');

        if (! empty($result['ok'])) {
            $data = is_array($result['data'] ?? null) ? $result['data'] : [];
            $this->targets->update($id, [
                'status'       => 'published',
                'progress'     => 100,
                'progress_stage' => 'Published',
                'attempts'     => $attempts,
                'external_id'  => (string) ($data['external_id'] ?? ''),
                'external_url' => substr((string) ($data['external_url'] ?? ''), 0, 500),
                'last_error'   => null,
                'published_at' => $now,
            ]);
            $outcome = 'published';
        } elseif (! empty($result['retry']) && $attempts < self::MAX_ATTEMPTS) {
            $this->targets->update($id, [
                'status'       => 'scheduled',
                'attempts'     => $attempts,
                'scheduled_at' => date('Y-m-d H:i:s', time() + 300 * $attempts),
                'last_error'   => substr((string) ($result['message'] ?? ''), 0, 500),
            ]);
            $outcome = 'retried';
        } else {
            $this->targets->update($id, [
                'status'     => 'failed',
                'progress_stage' => 'Failed',
                'attempts'   => $attempts,
                'last_error' => substr((string) ($result['message'] ?? 'Publishing failed.'), 0, 500),
            ]);
            $outcome = 'failed';
        }

        $this->rollUpPost((int) $target['post_id']);
        log_activity('social_publish_' . $outcome, 'social', 'Social target #' . $id . ' ' . $outcome, [
            'target_id'  => $id,
            'post_id'    => (int) $target['post_id'],
            'account_id' => (int) $target['account_id'],
            'attempts'   => $attempts,
        ]);

        return $outcome;
    }

    private function rollUpPost(int $postId): void
    {
        $rows = $this->targets->select('status')->where('post_id', $postId)->findAll();
        $s    = array_count_values(array_column($rows, 'status'));
        $n    = count($rows);

        if ($n === 0 || ($s['scheduled'] ?? 0) + ($s['publishing'] ?? 0) > 0) {
            return;
        }
        $published = $s['published'] ?? 0;
        $status    = $published === $n ? 'published' : ($published > 0 ? 'partial' : 'failed');
        $this->posts->update($postId, ['status' => $status]);
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $target
     */
    private function buildCaption(array $post, array $target): string
    {
        $override = trim((string) ($target['caption_override'] ?? ''));
        $caption  = $override !== '' ? $override : trim((string) ($post['caption'] ?? ''));
        $tags     = trim((string) ($post['hashtags'] ?? ''));

        return $tags !== '' ? trim($caption . "\n\n" . $tags) : $caption;
    }

    /**
     * Stages the Drive file and returns a short-lived signed public URL (see SocialMediaDelivery).
     * The staged file name is kept so it can be deleted once the target is finished.
     */
    private function resolveMediaUrl(int $assetId, ?callable $onProgress = null): ?string
    {
        $asset = $this->assets->find($assetId);
        if (! is_array($asset)) {
            return null;
        }
        $r = $this->delivery->stage($asset, $onProgress);
        if (! $r['ok']) {
            log_message('error', 'SocialPublishService::resolveMediaUrl asset=' . $assetId . ' ' . $r['message']);

            return null;
        }
        $this->staged = (string) $r['data']['file'];

        return (string) $r['data']['url'];
    }
}
