<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\FacebookLeadService;
use App\Models\WebhookLogModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * php spark facebook:retry-leads [days]
 *
 * Replays Facebook lead webhooks whose Graph fetch failed (expired token, outage).
 * Needs a tenant: set database.tenant in .env or run once per tenant.
 */
class FacebookRetryLeads extends BaseCommand
{
    public const ERROR_PREFIX = 'FB_LEAD_RETRY:';

    protected $group       = 'WhatsApp';
    protected $name        = 'facebook:retry-leads';
    protected $description = 'Retry Facebook lead webhooks that failed to load the lead from Meta';
    protected $usage       = 'facebook:retry-leads [days]';

    public function run(array $params)
    {
        $days = max(1, (int) ($params[0] ?? 3));
        $logs = model(WebhookLogModel::class);

        $rows = $logs->where('event_type', 'leadgen')
            ->like('error_message', self::ERROR_PREFIX, 'after')
            ->where('created_at >=', date('Y-m-d H:i:s', time() - $days * 86400))
            ->orderBy('id', 'ASC')
            ->findAll(200);

        $service = new FacebookLeadService();
        $ok      = 0;
        $failed  = 0;

        foreach ($rows as $row) {
            $payload = is_array($row['payload'] ?? null) ? $row['payload'] : (json_decode((string) ($row['payload'] ?? ''), true) ?: []);
            $stillFailing = false;

            foreach ((array) ($payload['entry'] ?? []) as $entry) {
                $pageId = (string) ($entry['id'] ?? '');
                foreach ((array) ($entry['changes'] ?? []) as $change) {
                    if (! is_array($change) || ($change['field'] ?? '') !== 'leadgen') {
                        continue;
                    }
                    $value = is_array($change['value'] ?? null) ? $change['value'] : [];
                    if ($pageId !== '' && trim((string) ($value['page_id'] ?? '')) === '') {
                        $value['page_id'] = $pageId;
                    }
                    try {
                        $result = $service->ingest($value);
                    } catch (Throwable $e) {
                        $result = ['ok' => false, 'retryable' => true, 'message' => $e->getMessage()];
                    }
                    if (empty($result['ok']) && ! empty($result['retryable'])) {
                        $stillFailing = true;
                        CLI::write('Lead ' . ($value['leadgen_id'] ?? '?') . ': ' . ($result['message'] ?? 'failed'), 'red');
                    }
                }
            }

            if ($stillFailing) {
                $failed++;
                continue;
            }
            $logs->update((int) $row['id'], ['error_message' => 'Retried OK']);
            $ok++;
        }

        CLI::write("Retried webhooks: {$ok} ok, {$failed} still failing.", $failed > 0 ? 'yellow' : 'green');
    }
}
