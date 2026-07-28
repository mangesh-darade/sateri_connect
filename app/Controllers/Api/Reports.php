<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\CampaignModel;
use App\Models\ContactModel;
use App\Models\MessageModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * API reporting / stats endpoints.
 */
class Reports extends BaseApiController
{
    public function stats(): ResponseInterface
    {
        $from = (string) ($this->request->getGet('from') ?: date('Y-m-01')) . ' 00:00:00';
        $to   = (string) ($this->request->getGet('to') ?: date('Y-m-d')) . ' 23:59:59';

        $row = db_connect()->table('messages')
            ->select("
                SUM(CASE WHEN direction = 'outbound' AND status IN ('sent','delivered','read') THEN 1 ELSE 0 END) AS sent,
                SUM(CASE WHEN direction = 'outbound' AND status IN ('delivered','read') THEN 1 ELSE 0 END) AS delivered,
                SUM(CASE WHEN direction = 'outbound' AND status = 'read' THEN 1 ELSE 0 END) AS `read`,
                SUM(CASE WHEN direction = 'outbound' AND status = 'failed' THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN direction = 'inbound' THEN 1 ELSE 0 END) AS replies
            ", false)
            ->where('created_at >=', $from)
            ->where('created_at <=', $to)
            ->get()
            ->getRowArray();

        return $this->respondSuccess([
            'period' => [
                'from' => $from,
                'to'   => $to,
            ],
            'contacts'  => model(ContactModel::class)->where('deleted_at', null)->countAllResults(),
            'campaigns' => model(CampaignModel::class)->countAllResults(),
            'messages'  => [
                'sent'      => (int) ($row['sent'] ?? 0),
                'delivered' => (int) ($row['delivered'] ?? 0),
                'read'      => (int) ($row['read'] ?? 0),
                'failed'    => (int) ($row['failed'] ?? 0),
                'replies'   => (int) ($row['replies'] ?? 0),
                'total'     => model(MessageModel::class)
                    ->where('created_at >=', $from)
                    ->where('created_at <=', $to)
                    ->countAllResults(),
            ],
        ]);
    }
}
