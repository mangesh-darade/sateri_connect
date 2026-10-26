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

        \App\Libraries\PerformanceIndexes::ensure(db_connect());

        $svc = new DashboardStats();

        [$todayStart, $todayEnd] = app_day_bounds_utc();
        [$monthStart, $monthEnd] = AppDateTime::monthToDateBoundsUtc();

        // One range map feeds every card and the 14-day trend, so each table is scanned once.
        $dates  = AppDateTime::recentDaysYmd(14);
        $ranges = [
            'all'   => [null, null],
            'today' => [$todayStart, $todayEnd],
            'month' => [$monthStart, $monthEnd],
        ];
        foreach ($dates as $date) {
            $ranges['d:' . $date] = app_day_bounds_utc($date);
        }

        $wa    = $svc->whatsappBuckets($ranges);
        $email = $svc->emailBuckets($ranges);

        $channels = [
            'whatsapp' => ['all' => $wa['all'], 'today' => $wa['today'], 'month' => $wa['month']],
            'email'    => ['all' => $email['all'], 'today' => $email['today'], 'month' => $email['month']],
        ];

        $campaignStatus = $svc->campaignStatus();

        $stats = [
            'contacts'      => model(ContactModel::class)->where('deleted_at', null)->countAllResults(),
            'campaigns'     => array_sum($campaignStatus),
            'queue_pending' => model(MessageQueueModel::class)->where('status', 'pending')->countAllResults(),
            'open_chats'    => model(ConversationModel::class)->where('status', 'open')->countAllResults(),
        ];

        $charts = [
            'trends'    => $svc->dailyTrend($dates, $wa, $email),
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
