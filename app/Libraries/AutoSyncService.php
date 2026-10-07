<?php

declare(strict_types=1);

namespace App\Libraries;

use Throwable;

/**
 * Background sync run when the app is opened: provider contacts, ElintOm customers and templates.
 * Each job runs on its own (so one failure never stops the others) and is throttled per tenant database.
 */
class AutoSyncService
{
    public const INTERVAL_SECONDS = 900;

    /** job => [label, permission] */
    protected const JOBS = [
        'contacts'  => ['Contacts', 'contacts.import'],
        'elintom'   => ['ElintOm customers', 'contacts.import'],
        'templates' => ['Templates', 'templates.sync'],
    ];

    /**
     * Jobs the current user may run.
     *
     * @param callable(string): bool $can
     *
     * @return list<array{key: string, label: string}>
     */
    public function jobs(callable $can): array
    {
        $out = [];
        foreach (self::JOBS as $key => [$label, $permission]) {
            if ($can($permission)) {
                $out[] = ['key' => $key, 'label' => $label];
            }
        }

        return $out;
    }

    public static function permissionFor(string $job): ?string
    {
        return self::JOBS[$job][1] ?? null;
    }

    /**
     * @return array{job: string, label: string, ran: bool, ok: bool, message: string, changed: int, ms: int, next_in: int}
     */
    public function runJob(string $job, bool $force = false): array
    {
        $label = self::JOBS[$job][0] ?? $job;
        $base  = ['job' => $job, 'label' => $label, 'ran' => false, 'ok' => true, 'message' => '', 'changed' => 0, 'ms' => 0, 'next_in' => 0];
        if (! isset(self::JOBS[$job])) {
            return array_merge($base, ['ok' => false, 'message' => 'Unknown sync job.']);
        }

        $cache = cache();
        $key   = 'auto_sync_' . $job . '_' . md5((string) db_connect()->getDatabase());
        $lock  = $key . '_running';
        if ($cache->get($lock)) {
            return array_merge($base, ['message' => 'Already syncing.', 'next_in' => self::INTERVAL_SECONDS]);
        }
        $wait = (int) ($cache->get($key) ?? 0) + self::INTERVAL_SECONDS - 5 - time();
        if (! $force && $wait > 0) {
            return array_merge($base, ['message' => 'Synced recently.', 'next_in' => $wait]);
        }
        $cache->save($lock, 1, 300);

        $started = microtime(true);
        try {
            [$changed, $message] = $this->execute($job);
            $result = ['ran' => true, 'ok' => true, 'message' => $message, 'changed' => $changed];
        } catch (Throwable $e) {
            log_message('warning', 'Auto sync {job} failed: {msg}', ['job' => $job, 'msg' => $e->getMessage()]);
            $result = ['ran' => true, 'ok' => false, 'message' => $e->getMessage()];
        } finally {
            $cache->save($key, time(), self::INTERVAL_SECONDS);
            $cache->delete($lock);
        }

        return array_merge($base, $result, [
            'ms'      => (int) round((microtime(true) - $started) * 1000),
            'next_in' => self::INTERVAL_SECONDS,
        ]);
    }

    /**
     * @return array{0: int, 1: string} [changed rows, summary]
     */
    protected function execute(string $job): array
    {
        switch ($job) {
            case 'contacts':
                $s = (new CheerioSyncService())->syncContacts();

                return [(int) $s['created'] + (int) $s['updated'], sprintf('%d created, %d updated.', $s['created'], $s['updated'])];

            case 'elintom':
                $s = (new ElintOmCustomerSyncService())->sync();
                $msg = sprintf('%d created, %d updated, %d unchanged, %d deleted in app (skipped).', $s['created'], $s['updated'], $s['unchanged'] ?? 0, $s['deleted'] ?? 0);
                if (! empty($s['failed'])) {
                    $msg .= ' ' . $s['failed'] . ' failed: ' . implode('; ', array_slice($s['errors'] ?? [], 0, 3));
                }

                return [(int) $s['created'] + (int) $s['updated'], $msg];

            default:
                $r = (new TemplateSyncService())->sync();

                return [(int) ($r['synced'] ?? 0), sprintf('%d synced.', (int) ($r['synced'] ?? 0))];
        }
    }
}
