<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Per-client hard cap on the daily ad budget (setting `ads_max_daily_budget`, in whole currency
 * units). Enforced server-side before any campaign is saved or launched; 0 / unset means ads
 * cannot be launched until the client sets a cap.
 */
class AdsBudgetGuard
{
    public const SETTING_KEY = 'ads_max_daily_budget';

    public function __construct(private ?SettingsService $settings = null)
    {
        $this->settings ??= service('settingsService');
    }

    /** Cap in minor units (paise); 0 when no cap has been set. */
    public function capMinor(): int
    {
        $units = (float) $this->settings->get(self::SETTING_KEY, 0);

        return $units > 0 ? (int) round($units * 100) : 0;
    }

    /**
     * @return array{ok: bool, status: int, message: string, data?: array{cap_minor: int}}
     */
    public function check(int $dailyBudgetMinor): array
    {
        $cap = $this->capMinor();

        if ($dailyBudgetMinor <= 0) {
            return ['ok' => false, 'status' => 422, 'message' => 'Enter a daily budget greater than zero.'];
        }
        if ($cap === 0) {
            return ['ok' => false, 'status' => 422, 'message' => 'Set a maximum daily ad budget in Ads settings before launching.'];
        }
        if ($dailyBudgetMinor > $cap) {
            return [
                'ok'      => false,
                'status'  => 422,
                'message' => 'The daily budget is above your limit of ' . number_format($cap / 100, 2) . '.',
                'data'    => ['cap_minor' => $cap],
            ];
        }

        return ['ok' => true, 'status' => 200, 'message' => 'OK', 'data' => ['cap_minor' => $cap]];
    }
}
