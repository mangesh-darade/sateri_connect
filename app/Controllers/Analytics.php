<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\ReportService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Analytics hub. WhatsApp and email numbers both come from ReportService.
 */
class Analytics extends BaseController
{
    public function index(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.view')) {
            return $denied;
        }

        $tab = strtolower(trim((string) ($this->request->getGet('tab') ?: 'whatsapp')));
        if (! in_array($tab, ['whatsapp', 'email'], true)) {
            $tab = 'whatsapp';
        }

        $service    = new ReportService();
        $range      = $service->dateRange(
            (string) $this->request->getGet('from'),
            (string) $this->request->getGet('to')
        );
        $campaignId = $this->request->getGet('campaign_id');
        $campaignId = ($campaignId !== null && $campaignId !== '') ? (int) $campaignId : null;

        $emptyWa = [
            'summary' => [], 'daily' => [], 'campaigns' => [], 'recipients' => [],
            'recipient_total' => 0, 'campaign_options' => [],
        ];
        $emptyEmail = [
            'summary' => [], 'daily' => [], 'campaigns' => [], 'logs' => [],
            'unsubscribes' => [], 'campaign_options' => [],
        ];

        return $this->render('analytics/index', [
            'pageTitle'  => 'Analytics',
            'activeTab'  => $tab,
            'from'       => $range['from'],
            'to'         => $range['to'],
            'campaignId' => $campaignId,
            'wa'         => $tab === 'whatsapp' ? $service->whatsappReport($range, $campaignId) : $emptyWa,
            'email'      => $tab === 'email' ? $service->emailReport($range, $campaignId) : $emptyEmail,
        ]);
    }

    public function data(): ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.view')) {
            return $denied;
        }

        $service = new ReportService();
        $range   = $service->dateRange(
            (string) $this->request->getGet('from'),
            (string) $this->request->getGet('to')
        );
        $channel = strtolower(trim((string) ($this->request->getGet('channel') ?: 'whatsapp')));
        $campaignId = $this->request->getGet('campaign_id');
        $campaignId = ($campaignId !== null && $campaignId !== '') ? (int) $campaignId : null;

        $report = $channel === 'email'
            ? $service->emailReport($range, $campaignId)
            : $service->whatsappReport($range, $campaignId);

        return $this->jsonResponse(true, [
            'summary' => $report['summary'],
            'daily'   => $report['daily'],
        ]);
    }
}
