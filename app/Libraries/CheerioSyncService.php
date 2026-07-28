<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\AutomationModel;
use App\Models\ContactModel;
use App\Models\TagModel;
use Throwable;

/**
 * Sync Cheerio Direct API contacts / workflows into local tables.
 */
class CheerioSyncService
{
    /**
     * @return array{created:int,updated:int,skipped:int,total:int,source:string}
     */
    public function syncContacts(): array
    {
        [$remote, $source] = $this->fetchRemoteContacts();
        $model    = model(ContactModel::class);
        $tagModel = model(TagModel::class);

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

            $payload = [
                'name'          => $name,
                'mobile'        => $mobile,
                'email'         => ! empty($row['email']) ? (string) $row['email'] : null,
                'status'        => $status,
                'custom_fields' => $custom,
                'notes'         => ! empty($row['_id']) ? ('Cheerio ID: ' . $row['_id']) : null,
            ];

            $contactId = 0;

            try {
                $existing = $model->findByMobile($mobile);
                if ($existing !== null) {
                    $model->skipValidation(true)->update((int) $existing['id'], $payload);
                    $model->skipValidation(false);
                    $contactId = (int) $existing['id'];
                    $updated++;
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
                    if ($labelName === '') {
                        continue;
                    }
                    $tag = $tagModel->where('name', $labelName)->first();
                    if ($tag === null) {
                        $tagId = $tagModel->insert(['name' => $labelName, 'color' => '#25D366']);
                        if ($tagId) {
                            $tagIds[] = (int) $tagId;
                        }
                    } else {
                        $tagIds[] = (int) $tag['id'];
                    }
                }
                if ($tagIds !== []) {
                    $model->syncTags($contactId, $tagIds);
                }
            }
        }

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
            // Meta Graph has no contact directory — use saved Cheerio API key for CRM sync.
            $cheerio = new CheerioDirectAPI($settings);
            try {
                $remote = $this->normalizeContactList($cheerio->getContacts());
            } catch (Throwable $e) {
                throw new \RuntimeException(
                    'Meta is active for messaging. To sync contacts, save a Cheerio API key in Settings (Cheerio credentials stay saved), then try again. '
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
                    'Meta is active for messaging. To sync workflows, save a Cheerio API key in Settings, then try again. Detail: ' . $e->getMessage(),
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
