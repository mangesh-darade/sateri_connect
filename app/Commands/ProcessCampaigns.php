<?php

namespace App\Commands;

use App\Libraries\CampaignService;
use App\Libraries\EmailCampaignService;
use App\Libraries\QueueService;
use App\Models\CampaignModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

class ProcessCampaigns extends BaseCommand
{
    protected $group       = 'WhatsApp';
    protected $name        = 'campaigns:process';
    protected $description = 'Start due scheduled campaigns and process their queued messages.';
    protected $usage       = 'campaigns:process [queueLimit]';
    protected $arguments   = [
        'queueLimit' => 'Queue batch size after starting campaigns (default 50).',
    ];

    public function run(array $params)
    {
        $queueLimit = isset($params[0]) ? (int) $params[0] : 50;

        try {
            $campaignService = new CampaignService();
            $started         = $campaignService->processScheduled();
            CLI::write("Started {$started} scheduled WhatsApp campaign(s).", 'green');

            $emailStarted = (new EmailCampaignService())->processScheduled();
            if ($emailStarted > 0) {
                CLI::write("Processed {$emailStarted} scheduled email campaign(s).", 'green');
            }

            // Mark completed campaigns that have no remaining pending/processing items
            $running = model(CampaignModel::class)->where('status', 'running')->findAll();
            foreach ($running as $campaign) {
                $remaining = db_connect()->table('message_queue')
                    ->where('campaign_id', (int) $campaign['id'])
                    ->whereIn('status', ['pending', 'processing'])
                    ->countAllResults();

                if ($remaining === 0) {
                    model(CampaignModel::class)->update((int) $campaign['id'], [
                        'status'       => 'completed',
                        'completed_at' => date('Y-m-d H:i:s'),
                    ]);
                    CLI::write('Campaign #' . $campaign['id'] . ' marked completed.', 'green');
                }
            }

            $stats = (new QueueService())->processBatch($queueLimit);
            CLI::write(sprintf(
                'Queue batch: processed=%d sent=%d failed=%d',
                $stats['processed'],
                $stats['sent'],
                $stats['failed']
            ), 'yellow');
        } catch (Throwable $e) {
            CLI::error('Campaign processing failed: ' . $e->getMessage());

            return EXIT_ERROR;
        }

        return EXIT_SUCCESS;
    }
}
