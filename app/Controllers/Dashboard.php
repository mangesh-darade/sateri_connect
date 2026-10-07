<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\AppDateTime;
use App\Libraries\DashboardStats;
use App\Models\ActivityLogModel;
use App\Models\ContactModel;
use App\Models\ConversationModel;
use App\Models\MessageQueueModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Main dashboard: WhatsApp + Email channel pulse, trends, and activity.
 */
class Dashboard extends BaseController
{
    public function index(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('dashboard.view')) {
            return $denied;
        }

        $svc = new DashboardStats();

        [$todayStart, $todayEnd] = app_day_bounds_utc();
        [$monthStart, $monthEnd] = AppDateTime::monthToDateBoundsUtc();

        $channels = [
            'whatsapp' => [
                'all'   => $svc->whatsapp(),
                'today' => $svc->whatsapp($todayStart, $todayEnd),
                'month' => $svc->whatsapp($monthStart, $monthEnd),
            ],
            'email' => [
                'all'   => $svc->email(),
                'today' => $svc->email($todayStart, $todayEnd),
                'month' => $svc->email($monthStart, $monthEnd),
            ],
        ];

        $campaignStatus = $svc->campaignStatus();

        $stats = [
            'contacts'      => model(ContactModel::class)->where('deleted_at', null)->countAllResults(),
            'campaigns'     => array_sum($campaignStatus),
            'queue_pending' => model(MessageQueueModel::class)->where('status', 'pending')->countAllResults(),
            'open_chats'    => model(ConversationModel::class)->where('status', 'open')->countAllResults(),
        ];

        $charts = [
            'trends'    => $svc->dailyTrend(14),
            'campaigns' => [
                'labels' => array_keys($campaignStatus),
                'values' => array_values($campaignStatus),
            ],
        ];

        if ($this->request->isAJAX() || $this->request->getGet('format') === 'json') {
            return $this->jsonResponse(true, [
                'stats'    => $stats,
                'channels' => $channels,
                'charts'   => $charts,
            ]);
        }

        $recentActivity = model(ActivityLogModel::class)
            ->select('activity_logs.*, users.name AS user_name')
            ->join('users', 'users.id = activity_logs.user_id', 'left')
            ->orderBy('activity_logs.created_at', 'DESC')
            ->findAll(8);

        return $this->render('dashboard/index', [
            'pageTitle'       => 'Dashboard',
            'stats'           => $stats,
            'channels'        => $channels,
            'charts'          => $charts,
            'recentActivity'  => $recentActivity,
            'recentCampaigns' => $svc->recentCampaigns(8),
        ]);
    }
}
