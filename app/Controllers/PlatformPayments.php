<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\MasterTenantRepository;
use App\Libraries\MswipeService;
use App\Libraries\RazorpayService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Platform super admin: Razorpay keys and invoice details for online wallet recharges.
 */
class PlatformPayments extends BaseController
{
    public function index(): string|ResponseInterface
    {
        if ($denied = $this->denyUnlessPrimary()) {
            return $denied;
        }

        $svc = new RazorpayService();
        $cfg = $svc->config();

        return view('platform/payments', [
            'pageTitle'    => 'Online payments',
            'navActive'    => 'payments',
            'platformName' => (string) session('platform_admin_name'),
            'cfg'          => $cfg,
            'hasSecret'    => $cfg['razorpay_key_secret'] !== '',
            'hasWebhook'   => $cfg['razorpay_webhook_secret'] !== '',
            'webhookUrl'   => site_url('webhooks/razorpay'),
            'msw'          => ($mswSvc = new MswipeService())->config(),
            'mswHasPassword' => $mswSvc->config()['mswipe_password'] !== '',
            'mswBackPostUrl' => site_url('webhooks/mswipe'),
        ]);
    }

    public function save(): ResponseInterface
    {
        if ($denied = $this->denyUnlessPrimary()) {
            return $denied;
        }

        $in = $this->request->getPost();
        $min = (float) ($in['recharge_min'] ?? 100);
        $max = (float) ($in['recharge_max'] ?? 100000);
        if ($min < 1 || $max < $min || $max > 10000000) {
            return redirect()->back()->withInput()->with('error', 'Check the minimum and maximum amounts.');
        }

        $keyId = trim((string) ($in['razorpay_key_id'] ?? ''));
        if ($keyId !== '' && preg_match('/^rzp_(test|live)_[A-Za-z0-9]+$/', $keyId) !== 1) {
            return redirect()->back()->withInput()->with('error', 'The key id should look like rzp_test_xxxx or rzp_live_xxxx.');
        }

        (new RazorpayService())->saveConfig([
            'razorpay_enabled'        => ! empty($in['razorpay_enabled']) ? '1' : '0',
            'razorpay_key_id'         => $keyId,
            'razorpay_key_secret'     => (string) ($in['razorpay_key_secret'] ?? ''),
            'razorpay_webhook_secret' => (string) ($in['razorpay_webhook_secret'] ?? ''),
            'recharge_min'            => (string) $min,
            'recharge_max'            => (string) $max,
            'invoice_company'         => mb_substr(trim((string) ($in['invoice_company'] ?? '')), 0, 120),
            'invoice_address'         => mb_substr(trim((string) ($in['invoice_address'] ?? '')), 0, 400),
            'invoice_gstin'           => strtoupper(mb_substr(trim((string) ($in['invoice_gstin'] ?? '')), 0, 20)),
        ]);
        log_activity('update', 'platform', 'Updated online payment settings', ['platform_admin_id' => (int) session('platform_admin_id')]);

        return redirect()->to(site_url('platform/payments'))->with('success', 'Payment settings saved.');
    }

    public function saveMswipe(): ResponseInterface
    {
        if ($denied = $this->denyUnlessPrimary()) {
            return $denied;
        }

        $in = $this->request->getPost();
        $fallback = preg_replace('/\D+/', '', (string) ($in['mswipe_fallback_mobile'] ?? '')) ?? '';
        if ($fallback !== '' && strlen($fallback) < 10) {
            return redirect()->back()->withInput()->with('error', 'The fallback mobile number must have 10 digits.');
        }

        (new MswipeService())->saveConfig([
            'mswipe_enabled'         => ! empty($in['mswipe_enabled']) ? '1' : '0',
            'mswipe_env'             => ($in['mswipe_env'] ?? '') === 'live' ? 'live' : 'uat',
            'mswipe_user_id'         => mb_substr(trim((string) ($in['mswipe_user_id'] ?? '')), 0, 40),
            'mswipe_client_id'       => mb_substr(trim((string) ($in['mswipe_client_id'] ?? '')), 0, 60),
            'mswipe_password'        => (string) ($in['mswipe_password'] ?? ''),
            'mswipe_custcode'        => mb_substr(trim((string) ($in['mswipe_custcode'] ?? '')), 0, 40),
            'mswipe_fallback_mobile' => $fallback !== '' ? substr($fallback, -10) : '',
        ]);
        log_activity('update', 'platform', 'Updated Mswipe payment settings', ['platform_admin_id' => (int) session('platform_admin_id')]);

        return redirect()->to(site_url('platform/payments'))->with('success', 'Mswipe settings saved.');
    }

    public function testMswipe(): ResponseInterface
    {
        if ($denied = $this->denyUnlessPrimary()) {
            return $denied;
        }

        $res = (new MswipeService())->testCredentials();

        return redirect()->to(site_url('platform/payments'))->with($res['ok'] ? 'success' : 'error', $res['message']);
    }

    public function test(): ResponseInterface
    {
        if ($denied = $this->denyUnlessPrimary()) {
            return $denied;
        }

        $res = (new RazorpayService())->testKeys();

        return redirect()->to(site_url('platform/payments'))->with($res['ok'] ? 'success' : 'error', $res['message']);
    }

    protected function denyUnlessPrimary(): ?ResponseInterface
    {
        if ((new MasterTenantRepository())->isPrimaryPlatformAdmin((int) session('platform_admin_id'))) {
            return null;
        }

        return redirect()->to('/platform/clients')->with('error', 'Only the main platform super admin can change payment settings.');
    }
}
