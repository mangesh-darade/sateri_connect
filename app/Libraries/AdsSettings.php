<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Ads settings of one client: the daily budget cap, test / live mode and the Meta connection.
 * The access token is stored encrypted and is never sent back to the browser.
 */
class AdsSettings
{
    private const MAX_CAP_UNITS = 10000000;

    private SettingsService $settings;

    public function __construct(?SettingsService $settings = null)
    {
        $this->settings = $settings ?? service('settingsService');
    }

    /**
     * @return array{cap: float, mode: string, account: string, page: string, whatsapp: string, token_saved: bool}
     */
    public function current(): array
    {
        return [
            'cap'         => (float) $this->settings->get(AdsBudgetGuard::SETTING_KEY, 0),
            'mode'        => strtolower((string) $this->settings->get('ads_mode', 'mock')) === 'live' ? 'live' : 'mock',
            'account'     => (string) $this->settings->get('ads_meta_ad_account_id', ''),
            'page'        => (string) $this->settings->get('ads_meta_page_id', ''),
            'whatsapp'    => (string) $this->settings->get('ads_meta_whatsapp_number', ''),
            'token_saved' => (string) $this->settings->get('ads_meta_access_token', '') !== '',
        ];
    }

    /**
     * @param array<string, mixed> $in cap, mode, account, page, whatsapp, token (blank keeps the saved token)
     *
     * @return array{ok: bool, status: int, message: string, errors?: array<string, string>}
     */
    public function save(array $in): array
    {
        $errors = [];

        $cap = (float) str_replace(',', '', (string) ($in['cap'] ?? '0'));
        if ($cap < 0 || $cap > self::MAX_CAP_UNITS) {
            $errors['cap'] = 'Enter a daily limit between 0 and ' . number_format(self::MAX_CAP_UNITS) . '.';
        }

        $mode = strtolower((string) ($in['mode'] ?? 'mock'));
        if (! in_array($mode, ['mock', 'live'], true)) {
            $errors['mode'] = 'Choose test or live mode.';
        }

        $account = trim((string) ($in['account'] ?? ''));
        if ($account !== '' && ! preg_match('/^(act_)?\d{5,30}$/', $account)) {
            $errors['account'] = 'The ad account ID is digits only, for example 1234567890.';
        }
        $page = trim((string) ($in['page'] ?? ''));
        if ($page !== '' && ! preg_match('/^\d{5,30}$/', $page)) {
            $errors['page'] = 'The Facebook Page ID is digits only.';
        }
        $whatsapp = preg_replace('/\D+/', '', (string) ($in['whatsapp'] ?? ''));
        if ($whatsapp !== '' && (strlen($whatsapp) < 8 || strlen($whatsapp) > 15)) {
            $errors['whatsapp'] = 'Enter the WhatsApp number with country code, digits only.';
        }
        $token = trim((string) ($in['token'] ?? ''));
        if (mb_strlen($token) > 1000) {
            $errors['token'] = 'That access token is too long.';
        }

        if ($errors !== []) {
            return ['ok' => false, 'status' => 422, 'message' => 'Please fix the highlighted fields.', 'errors' => $errors];
        }

        $old = $this->current();
        $this->settings->set(AdsBudgetGuard::SETTING_KEY, (string) round($cap, 2), 'ads', false);
        $this->settings->set('ads_mode', $mode, 'ads', false);
        $this->settings->set('ads_meta_ad_account_id', $account, 'ads', false);
        $this->settings->set('ads_meta_page_id', $page, 'ads', false);
        $this->settings->set('ads_meta_whatsapp_number', $whatsapp, 'ads', false);
        if ($token !== '') {
            $this->settings->set('ads_meta_access_token', $token, 'ads', true);
        }

        log_activity('update', 'ads', 'Ad settings updated', [
            'cap_from'      => $old['cap'],
            'cap_to'        => round($cap, 2),
            'mode'          => $mode,
            'token_changed' => $token !== '',
        ]);

        return ['ok' => true, 'status' => 200, 'message' => 'Ad settings saved.'];
    }
}
