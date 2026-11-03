<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Libraries\AdsService;
use App\Libraries\ReportService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * REST API v1 read-only numbers for AI clients: message report and ad campaigns (plus pausing an ad).
 * Launching ads is left to the Ads screen because it spends money.
 */
class InsightsController extends BaseV1Controller
{
    /** GET /api/v1/reports/summary?from=YYYY-MM-DD&to=YYYY-MM-DD (default: this month) */
    public function summary(): ResponseInterface
    {
        $svc   = new ReportService();
        $range = $svc->dateRange($this->request->getGet('from'), $this->request->getGet('to'));
        $msgs  = db_connect()->table('messages')
            ->select("SUM(direction = 'outbound') AS outbound, SUM(direction = 'inbound') AS inbound", false)
            ->where('created_at >=', $range['from_utc'])
            ->where('created_at <=', $range['to_utc'])
            ->get()->getRowArray() ?: [];

        return $this->respondSuccess([
            'from'      => $range['from'],
            'to'        => $range['to'],
            'messages'  => ['outbound' => (int) ($msgs['outbound'] ?? 0), 'inbound' => (int) ($msgs['inbound'] ?? 0)],
            'campaigns' => $svc->whatsappSummary($range['from_utc'], $range['to_utc']),
        ], 'Report loaded.');
    }

    /** GET /api/v1/ads */
    public function ads(): ResponseInterface
    {
        $rows = (new AdsService())->listWithMetrics(50);

        return $this->respondSuccess(['ads' => $rows, 'total' => count($rows)], 'Ads loaded.');
    }

    /** POST /api/v1/ads/{id}/pause */
    public function pauseAd(string $id): ResponseInterface
    {
        $r = (new AdsService())->pause((int) $id);

        return $r['ok'] ? $this->respondSuccess(null, (string) $r['message']) : $this->respondError((string) $r['message'], (int) max(400, (int) $r['status']));
    }
}
