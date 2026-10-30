<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\AutomationModel;
use App\Models\ContactModel;
use App\Models\TagModel;
use Throwable;

/**
 * Sync External Direct API contacts / workflows into local tables.
 */
class CheerioSyncService
{
    /**
     * @return array{created:int,updated:int,skipped:int,total:int,source:string}
     */
    public function syncContacts(): array
    {
        $t0 = microtime(true);
        [$remote, $source] = $this->fetchRemoteContacts();
        $tFetch = microtime(true) - $t0;
        if (is_array($remote[0] ?? null)) {
            log_message('info', 'External contact fields: {keys}', ['keys' => implode(',', array_keys($remote[0]))]);
        }
        $model    = model(ContactModel::class);
        $tagModel = model(TagModel::class);
        $model->skipElintOmPush = true;
        $tagCache   = [];

        // One query each instead of several per contact: existing contacts (incl. deleted) and existing group links.
        $byMobile = [];
        foreach ($model->withDeleted()->where('channel', 'whatsapp')->findAll() as $c) {
            $byMobile[preg_replace('/\D+/', '', (string) $c['mobile']) ?? ''] ??= $c;
        }
        $linked = [];
        foreach (db_connect()->table('contact_tags')->select('tag_id, contact_id')->get()->getResultArray() as $l) {
            $linked[$l['tag_id'] . ':' . $l['contact_id']] = true;
        }
        $attach = static function (int $tagId, int $contactId) use ($tagModel, &$linked): void {
            if ($tagId > 0 && ! isset($linked[$tagId . ':' . $contactId])) {
                $tagModel->attachContact($tagId, $contactId);
                $linked[$tagId . ':' . $contactId] = true;
            }
        };

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $seen    = [];

        foreach ($remote as $row) {
            if (! is_array($row)) {
                continue;
            }

            $mobile = preg_replace('/\D+/', '', (string) ($row['mobile'] ?? $row['phone'] ?? $row['phoneNumber'] ?? '')) ?? '';
            if ($mobile === '') {
                $skipped++;
                continue;
            }
            if (strlen($mobile) > 30) {
                $mobile = substr($mobile, 0, 30);
            }
            if (isset($seen[$mobile])) {
                $skipped++;
                continue;
            }
            $seen[$mobile] = true;

            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                $name = $mobile;
            }

            $custom = $row['customData'] ?? $row['custom_fields'] ?? null;
            if (! is_array($custom)) {
                $custom = null;
            }

            $statusRaw = $row['status'] ?? true;
            $status    = 'active';
            if ($statusRaw === false || $statusRaw === 0 || $statusRaw === 'inactive' || ! empty($row['stop'])) {
                $status = 'inactive';
            }

            // Source shown in the Contacts "All sources" filter; an existing source is kept.
            $prev    = $byMobile[$mobile] ?? null;
            $prevCf  = is_array($prev['custom_fields'] ?? null) ? $prev['custom_fields'] : [];
            // Platform values win, but attributes set only in this app (e.g. elintom_lead_id) are not wiped.
            $custom  = array_merge($prevCf, $custom ?? []);
            $prevSrc = trim((string) ($prevCf['source'] ?? ''));
            if ($prevSrc !== '') {
                $custom['source'] = $prevSrc;
            } elseif (trim((string) ($custom['source'] ?? '')) === '') {
                $custom['source'] = 'External Platform';
            }

            $payload = [
                'name'          => $name,
                'mobile'        => $mobile,
                'email'         => ! empty($row['email']) ? (string) $row['email'] : null,
                'status'        => $status,
                'custom_fields' => $custom,
                'notes'         => ! empty($row['_id']) ? ('External ID: ' . $row['_id']) : null,
            ];

            $contactId = 0;

            try {
                $existing = $prev;
                if ($existing !== null) {
                    $contactId = (int) $existing['id'];
                    $changed   = false;
                    if (! empty($existing['deleted_at'])) {
                        // The External platform still has this contact: bring it back and refresh its values.
                        db_connect()->table('contacts')->where('id', $contactId)->update(['deleted_at' => null]);
                        $changed = true;
                    }
                    foreach (['name', 'email', 'status', 'notes'] as $k) {
                        if ((string) ($existing[$k] ?? '') !== (string) ($payload[$k] ?? '')) {
                            $changed = true;
                        }
                    }
                    if (json_encode($existing['custom_fields'] ?? null) !== json_encode($payload['custom_fields'])) {
                        $changed = true;
                    }
                    if ($changed) {
                        $model->skipValidation(true)->update($contactId, $payload);
                        $model->skipValidation(false);
                        $updated++;
                    }
                } else {
                    $model->skipValidation(true);
                    $contactId = (int) $model->insert($payload);
                    $model->skipValidation(false);
                    if ($contactId <= 0) {
                        $skipped++;
                        continue;
                    }
                    $created++;
                }
            } catch (Throwable $e) {
                $model->skipValidation(false);
                $existing = $model->findByMobile($mobile);
                if ($existing === null) {
                    $skipped++;
                    continue;
                }
                $contactId = (int) $existing['id'];
                $skipped++;
            }

            $labels = $row['labels'] ?? [];
            if ($contactId > 0 && is_array($labels) && $labels !== []) {
                $tagIds = [];
                foreach ($labels as $label) {
                    $labelName = is_array($label)
                        ? (string) ($label['name'] ?? $label['label'] ?? '')
                        : trim((string) $label);
                    // The External API sends only the label ID (no endpoint returns the name); an ID is not a usable group name.
                    if ($labelName === '' || preg_match('/^[a-f0-9]{24}$/i', $labelName) === 1) {
                        continue;
                    }
                    if (! isset($tagCache[$labelName])) {
                        $tagCache[$labelName] = (int) ($tagModel->findOrCreateByName($labelName, '#25D366')['id'] ?? 0);
                    }
                    if ($tagCache[$labelName] > 0) {
                        $tagIds[] = $tagCache[$labelName];
                    }
                }
                // Merge: attach only, so groups added manually in the app are kept.
                foreach (array_unique($tagIds) as $tagId) {
                    $attach($tagId, $contactId);
                }
            }
        }

        $model->skipElintOmPush = false;

        log_message('info', 'Contacts sync ({src}): fetch {fetch}s, total {total}s, {n} rows', [
            'src' => $source, 'fetch' => round($tFetch, 1), 'total' => round(microtime(true) - $t0, 1), 'n' => count($remote),
        ]);
        (new ActivityLogger())->log('sync', 'contacts', "Contacts sync ({$source}) created={$created} updated={$updated}");

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'total'   => count($remote),
            'source'  => $source,
        ];
    }

    /**
     * @return array{0: list<array<string,mixed>>, 1: string}
     */
    protected function fetchRemoteContacts(): array
    {
        $settings = service('settingsService');
        $provider = $settings->getWhatsAppProvider();

        if ($provider === 'meta') {
            // Meta Graph has no contact directory — use saved External API key for CRM sync.
            $cheerio = new CheerioDirectAPI($settings);
            try {
                $remote = $this->normalizeContactList($cheerio->getContacts());
            } catch (Throwable $e) {
                throw new \RuntimeException(
                    'Meta is active for messaging. To sync contacts, save an External API key in Settings (External credentials stay saved), then try again. '
                    . 'Or contacts will appear automatically from Meta webhooks. Detail: ' . $e->getMessage(),
                    0,
                    $e
                );
            }

            if ($remote === []) {
                // Still ok — empty directory
                return [$remote, 'cheerio_directory'];
            }

            return [$remote, 'cheerio_directory'];
        }

        $remote = $this->normalizeContactList(service('whatsApp')->getContacts());

        return [$remote, 'cheerio'];
    }

    /**
     * @param mixed $remote
     *
     * @return list<array<string, mixed>>
     */
    protected function normalizeContactList(mixed $remote): array
    {
        if (! is_array($remote)) {
            return [];
        }
        if (isset($remote['unsupported']) && $remote['unsupported']) {
            return [];
        }
        if (isset($remote['data']) && is_array($remote['data'])) {
            $remote = $remote['data'];
        }

        $out = [];
        foreach ($remote as $row) {
            if (is_array($row) && (isset($row['mobile']) || isset($row['phone']) || isset($row['phoneNumber']) || isset($row['_id']))) {
                $out[] = $row;
            }
        }

        // If it was already a plain list of contacts, keep original rows
        if ($out === [] && array_is_list($remote)) {
            foreach ($remote as $row) {
                if (is_array($row)) {
                    $out[] = $row;
                }
            }
        }

        return $out;
    }

    /**
     * @return array{created:int,updated:int,skipped:int,total:int,source:string}
     */
    public function syncWorkflows(?int $createdBy = null): array
    {
        [$remote, $source] = $this->fetchRemoteWorkflows();
        $model   = model(AutomationModel::class);
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($remote as $wf) {
            if (! is_array($wf)) {
                continue;
            }

            $cheerioId = (string) ($wf['_id'] ?? $wf['id'] ?? '');
            $name      = trim((string) ($wf['name'] ?? ''));
            if ($name === '') {
                $skipped++;
                continue;
            }

            $nodes = is_array($wf['nodes'] ?? null) ? $wf['nodes'] : [];
            $edges = is_array($wf['edges'] ?? null) ? $wf['edges'] : [];

            $triggerConfig = [
                'source'              => 'cheerio',
                'cheerio_workflow_id' => $cheerioId !== '' ? $cheerioId : null,
                'cheerio_status'      => $wf['status'] ?? null,
            ];

            $flowGraph = (new WorkflowGraph())->normalizeImportedGraph([
                'source' => 'cheerio',
                'nodes'  => $nodes,
                'edges'  => $edges,
            ]);
            $flowGraph['source'] = 'cheerio_local';

            $triggerType = (new WorkflowGraph())->triggerFromGraph($flowGraph) ?: 'cheerio_workflow';

            $existing = null;
            if ($cheerioId !== '') {
                $existing = $model->like('trigger_config', $cheerioId)->where('trigger_type', 'cheerio_workflow')->first();
            }
            if ($existing === null && $cheerioId !== '') {
                $existing = $model->like('trigger_config', $cheerioId)->first();
            }
            if ($existing === null) {
                $existing = $model->where('name', $name)->where('trigger_type', 'cheerio_workflow')->first();
            }

            $row = [
                'name'           => $name,
                'trigger_type'   => $triggerType,
                'trigger_config' => $triggerConfig,
                'flow_graph'     => $flowGraph,
                'is_active'      => 0,
                'priority'       => 50,
            ];
            if ($createdBy !== null) {
                $row['created_by'] = $createdBy;
            }

            if ($existing !== null) {
                unset($row['created_by']);
                $model->update((int) $existing['id'], $row);
                $updated++;
            } else {
                $id = $model->insert($row);
                if (! $id) {
                    $skipped++;
                    continue;
                }
                $created++;
            }
        }

        (new ActivityLogger())->log('sync', 'automations', "Workflows sync ({$source}) created={$created} updated={$updated}");

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'total'   => count($remote),
            'source'  => $source,
        ];
    }

    /**
     * @return array{0: list<array<string,mixed>>, 1: string}
     */
    protected function fetchRemoteWorkflows(): array
    {
        $settings = service('settingsService');
        $provider = $settings->getWhatsAppProvider();

        if ($provider === 'meta') {
            $cheerio = new CheerioDirectAPI($settings);
            try {
                $remote = $this->normalizeWorkflowList($cheerio->getWorkflows());
            } catch (Throwable $e) {
                throw new \RuntimeException(
                    'Meta is active for messaging. To sync workflows, save an External API key in Settings, then try again. Detail: ' . $e->getMessage(),
                    0,
                    $e
                );
            }

            return [$remote, 'cheerio_directory'];
        }

        return [$this->normalizeWorkflowList(service('whatsApp')->getWorkflows()), 'cheerio'];
    }

    /**
     * @param mixed $remote
     *
     * @return list<array<string, mixed>>
     */
    protected function normalizeWorkflowList(mixed $remote): array
    {
        if (! is_array($remote)) {
            return [];
        }
        if (isset($remote['unsupported']) && $remote['unsupported']) {
            return [];
        }
        if (isset($remote['data']) && is_array($remote['data'])) {
            $remote = $remote['data'];
        }
        if (! array_is_list($remote)) {
            return [];
        }

        return array_values(array_filter($remote, 'is_array'));
    }
}
