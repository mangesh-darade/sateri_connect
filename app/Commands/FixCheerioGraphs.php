<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\WorkflowGraph;
use App\Models\AutomationModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Re-normalize imported Cheerio workflow graphs (labels / webhook mapping).
 */
class FixCheerioGraphs extends BaseCommand
{
    protected $group       = 'Cheerio';
    protected $name        = 'cheerio:fix-graphs';
    protected $description = 'Re-normalize Cheerio workflow graphs for the local builder.';

    public function run(array $params)
    {
        $model = model(AutomationModel::class);
        $wf    = new WorkflowGraph();
        $rows  = $model->findAll();
        $fixed = 0;

        foreach ($rows as $row) {
            $graph = $row['flow_graph'] ?? null;
            if (! is_array($graph) || empty($graph['nodes'])) {
                continue;
            }

            $source  = (string) ($graph['source'] ?? '');
            $version = (int) ($graph['normalize_version'] ?? 0);
            $needs   = $wf->looksLikeCheerioGraph($graph)
                || in_array($source, ['cheerio', 'cheerio_local'], true)
                || $version < WorkflowGraph::NORMALIZE_VERSION
                || (string) ($row['trigger_type'] ?? '') === 'cheerio_workflow';

            if (! $needs) {
                continue;
            }

            $graph           = $wf->normalizeImportedGraph($graph);
            $graph['source'] = 'cheerio_local';
            $triggerType     = $wf->triggerFromGraph($graph) ?: (string) ($row['trigger_type'] ?? 'cheerio_workflow');

            $model->update((int) $row['id'], [
                'name'         => (string) $row['name'],
                'trigger_type' => $triggerType,
                'flow_graph'   => $graph,
            ]);
            $fixed++;
            CLI::write('Fixed #' . $row['id'] . ' — ' . $row['name'] . ' [' . $triggerType . ']', 'green');
        }

        CLI::write("Done. Fixed {$fixed} workflow(s).", 'green');
    }
}
