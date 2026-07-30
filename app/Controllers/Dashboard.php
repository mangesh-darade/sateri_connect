<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ActivityLogModel;
use App\Models\CampaignModel;
use App\Models\ContactModel;
use App\Models\ConversationModel;
use App\Models\MessageModel;
use App\Models\MessageQueueModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Main dashboard with aggregate stats and chart data.
 */
class Dashboard extends BaseController
{
    public function index(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('dashboard.view')) {
            return $denied;
        }

        $db = db_connect();

        $totalContacts  = model(ContactModel::class)->where('deleted_at', null)->countAllResults();
        $totalCampaigns = model(CampaignModel::class)->countAllResults();

        $messageStats = $this->messageCounts();
        [$todayStart, $todayEnd] = app_day_bounds_utc();
        $todayStats   = $this->periodMessageCounts($todayStart, $todayEnd);
        [$monthStart, $monthEnd] = \App\Libraries\AppDateTime::monthToDateBoundsUtc();
        $monthStats   = $this->periodMessageCounts($monthStart, $monthEnd);

        $queuePending = model(MessageQueueModel::class)->where('status', 'pending')->countAllResults();
        $openChats    = model(ConversationModel::class)->where('status', 'open')->countAllResults();

        $byDay = $this->messagesByDay(14);
        $trendLabels = [];
        $trendSent = [];
        $trendDelivered = [];
        $trendRead = [];
        $trendFailed = [];
        $trendReplies = [];
        foreach ($byDay as $day) {
            $trendLabels[]    = date('M j', strtotime($day['date']));
            $trendSent[]      = $day['sent'];
            $trendDelivered[] = $day['delivered'];
            $trendRead[]      = $day['read'];
            $trendFailed[]    = $day['failed'];
            $trendReplies[]   = $day['replies'];
        }

        $campaignStatus = $this->campaignStatusBreakdown();
        $campaignLabels = array_keys($campaignStatus);
        $campaignValues = array_values($campaignStatus);

        $charts = [
            'trends' => [
                'labels'    => $trendLabels,
                'sent'      => $trendSent,
                'delivered' => $trendDelivered,
                'read'      => $trendRead,
                'failed'    => $trendFailed,
                'replies'   => $trendReplies,
            ],
            'campaigns' => [
                'labels' => $campaignLabels,
                'values' => $campaignValues,
            ],
            'messages_by_day'  => $byDay,
            'status_breakdown' => [
                'sent'      => $messageStats['sent'],
                'delivered' => $messageStats['delivered'],
                'read'      => $messageStats['read'],
                'failed'    => $messageStats['failed'],
                'replies'   => $messageStats['replies'],
            ],
            'campaign_status' => $campaignStatus,
        ];

        $recentActivity = model(ActivityLogModel::class)
            ->select('activity_logs.*, users.name AS user_name')
            ->join('users', 'users.id = activity_logs.user_id', 'left')
            ->orderBy('activity_logs.created_at', 'DESC')
            ->findAll(20);

        $recentCampaigns = model(CampaignModel::class)
            ->orderBy('created_at', 'DESC')
            ->findAll(5);

        $data = [
            'pageTitle'       => 'Dashboard',
            'subtitle'        => 'Delivery, replies, and campaign pulse at a glance.',
            'stats'           => [
                'contacts'       => $totalContacts,
                'campaigns'      => $totalCampaigns,
                'sent'           => $messageStats['sent'],
                'delivered'      => $messageStats['delivered'],
                'read'           => $messageStats['read'],
                'failed'         => $messageStats['failed'],
                'replies'        => $messageStats['replies'],
                'queue_pending'  => $queuePending,
                'open_chats'     => $openChats,
                'today'          => $todayStats['sent'],
                'today_replies'  => $todayStats['replies'],
                'this_month'     => $monthStats['sent'],
            ],
            'chartsJson'      => json_encode($charts),
            'charts'          => $charts,
            'recentActivity'  => $recentActivity,
            'recentCampaigns' => $recentCampaigns,
        ];

        if ($this->request->isAJAX() || $this->request->getGet('format') === 'json') {
            return $this->jsonResponse(true, [
                'stats'  => $data['stats'],
                'charts' => $charts,
            ]);
        }

        return $this->render('dashboard/index', $data);
    }

    /**
     * @return array{sent:int,delivered:int,read:int,failed:int,replies:int}
     */
    protected function messageCounts(): array
    {
        $db = db_connect();

        $outbound = $db->table('messages')
            ->select("
                SUM(CASE WHEN direction = 'outbound' AND status IN ('sent','delivered','read') THEN 1 ELSE 0 END) AS sent,
                SUM(CASE WHEN direction = 'outbound' AND status IN ('delivered','read') THEN 1 ELSE 0 END) AS delivered,
                SUM(CASE WHEN direction = 'outbound' AND status = 'read' THEN 1 ELSE 0 END) AS `read`,
                SUM(CASE WHEN direction = 'outbound' AND status = 'failed' THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN direction = 'inbound' THEN 1 ELSE 0 END) AS replies
            ", false)
            ->get()
            ->getRowArray();

        return [
            'sent'      => (int) ($outbound['sent'] ?? 0),
            'delivered' => (int) ($outbound['delivered'] ?? 0),
            'read'      => (int) ($outbound['read'] ?? 0),
            'failed'    => (int) ($outbound['failed'] ?? 0),
            'replies'   => (int) ($outbound['replies'] ?? 0),
        ];
    }

    /**
     * @return array{sent:int,delivered:int,read:int,failed:int,replies:int}
     */
    protected function periodMessageCounts(string $from, string $to): array
    {
        $db = db_connect();

        $row = $db->table('messages')
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

        return [
            'sent'      => (int) ($row['sent'] ?? 0),
            'delivered' => (int) ($row['delivered'] ?? 0),
            'read'      => (int) ($row['read'] ?? 0),
            'failed'    => (int) ($row['failed'] ?? 0),
            'replies'   => (int) ($row['replies'] ?? 0),
        ];
    }

    /**
     * @return list<array{date:string,sent:int,delivered:int,read:int,failed:int,replies:int}>
     */
    protected function messagesByDay(int $days): array
    {
        $db     = db_connect();
        $result = [];

        foreach (\App\Libraries\AppDateTime::recentDaysYmd($days) as $date) {
            [$from, $to] = app_day_bounds_utc($date);

            $row = $db->table('messages')
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

            $result[] = [
                'date'      => $date,
                'sent'      => (int) ($row['sent'] ?? 0),
                'delivered' => (int) ($row['delivered'] ?? 0),
                'read'      => (int) ($row['read'] ?? 0),
                'failed'    => (int) ($row['failed'] ?? 0),
                'replies'   => (int) ($row['replies'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * @return array<string, int>
     */
    protected function campaignStatusBreakdown(): array
    {
        $rows = db_connect()->table('campaigns')
            ->select('status, COUNT(*) AS total')
            ->groupBy('status')
            ->get()
            ->getResultArray();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['status']] = (int) $row['total'];
        }

        return $out;
    }
}
