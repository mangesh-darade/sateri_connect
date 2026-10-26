<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\MswipeService;
use App\Libraries\RazorpayService;
use App\Libraries\TenantContext;
use App\Libraries\WalletService;
use App\Models\WalletPaymentModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Client view of the WhatsApp wallet: balance, limits, online recharge and invoices. The full ledger lives
 * under Reports > Transaction report.
 */
class Wallet extends BaseController
{
    public function index(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('settings.view')) {
            return $denied;
        }

        $msw = new MswipeService();
        // Mswipe may not send the customer back or call us (for example on localhost), so open links are
        // checked with Mswipe whenever the wallet page is opened. A paid one is credited before we show the balance.
        $pending = [];
        try {
            $open = model(WalletPaymentModel::class)->where('gateway', 'mswipe')->where('status', 'created')
                ->where('created_at >=', date('Y-m-d H:i:s', strtotime('-3 days')))->countAllResults();
            if ($open > 0 && $msw->isConfigured()) {
                $msw->reconcilePending(5);
                // Only recent ones are shown as a notice; an abandoned link must not nag for days.
                $pending = model(WalletPaymentModel::class)->where('gateway', 'mswipe')->where('status', 'created')
                    ->where('created_at >=', date('Y-m-d H:i:s', strtotime('-6 hours')))->orderBy('id', 'DESC')->findAll(3);
            }
        } catch (\Throwable $e) {
            log_message('warning', 'Wallet page Mswipe check failed: {msg}', ['msg' => $e->getMessage()]);
        }

        $wallet = new WalletService();
        $rzp    = new RazorpayService();
        $cfg    = $rzp->config();
        $inr    = strtoupper($wallet->currency()) === 'INR';
        $gateways = [];
        if ($rzp->isConfigured() && $inr) {
            $gateways['razorpay'] = 'Razorpay (UPI, cards, netbanking)';
        }
        if ($msw->isConfigured() && $inr) {
            $gateways['mswipe'] = 'Mswipe (pay by link)';
        }

        return view('wallet/index', [
            'pageTitle' => 'WhatsApp wallet',
            'wallet'    => $wallet->wallet(),
            'health'    => $wallet->health(),
            'limits'    => $wallet->limits(),
            'freeUsed'  => $wallet->freeServiceUsed(),
            'freeLimit' => WalletService::FREE_SERVICE_PER_MONTH,
            'recharge'  => [
                'available' => $gateways !== [],
                'gateways'  => $gateways,
                // Why "Add money" is not usable, shown to the client instead of hiding the card.
                'reason'    => ! $inr
                    ? 'Online payment is available for INR wallets only. Ask your platform admin to add money.'
                    : ($gateways === [] ? 'Online payment is not set up yet. Ask your platform admin to add money, or to enable online payments.' : ''),
                'gst'       => $rzp->gstPercent(),
                'min'       => (float) $cfg['recharge_min'],
                'max'       => (float) $cfg['recharge_max'],
                'canPay'    => can('settings.edit'),
            ],
            'pendingPayments' => $pending,
            'hasPayments' => model(WalletPaymentModel::class)->where('status', 'paid')->countAllResults() > 0,
        ]);
    }

    /** Rows for the wallet ledger table (server-side: search, sort, filter and paging run in SQL). */
    public function transactionsData(): ResponseInterface
    {
        if ($denied = $this->requirePermission('settings.view')) {
            return $denied;
        }

        $get  = $this->request->getGet();
        $cols = ['created_at', 'type', 'description', 'amount', 'balance_after'];
        $page = (new WalletService())->transactionsPage([
            'start'  => (int) ($get['start'] ?? 0),
            'length' => (int) ($get['length'] ?? 10),
            'search' => (string) ($get['search']['value'] ?? ''),
            'type'   => (string) ($get['type'] ?? ''),
            'order'  => $cols[(int) ($get['order'][0]['column'] ?? 0)] ?? 'created_at',
            'dir'    => (string) ($get['order'][0]['dir'] ?? 'desc'),
        ]);

        return $this->response->setJSON([
            'draw'            => (int) ($get['draw'] ?? 0),
            'recordsTotal'    => $page['total'],
            'recordsFiltered' => $page['filtered'],
            'data'            => WalletService::tableRows($page['rows']),
        ]);
    }

    /** Rows for the online recharges / invoices table. */
    public function paymentsData(): ResponseInterface
    {
        if ($denied = $this->requirePermission('settings.view')) {
            return $denied;
        }

        $get    = $this->request->getGet();
        $cols   = ['paid_at', 'invoice_no', 'credit_amount', 'tax_amount', 'total_amount'];
        $order  = $cols[(int) ($get['order'][0]['column'] ?? 0)] ?? 'paid_at';
        $dir    = strtolower((string) ($get['order'][0]['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        $search = trim((string) ($get['search']['value'] ?? ''));
        $start  = max(0, (int) ($get['start'] ?? 0));
        $length = max(1, min(100, (int) ($get['length'] ?? 10)));

        $base = static function (?string $q) {
            $m = model(WalletPaymentModel::class)->where('status', 'paid');
            if ($q !== null && $q !== '') {
                $m->groupStart()->like('invoice_no', $q)->orLike('CAST(total_amount AS CHAR)', $q)->orLike('CAST(credit_amount AS CHAR)', $q)->groupEnd();
            }

            return $m;
        };

        $total    = $base(null)->countAllResults();
        $filtered = $base($search)->countAllResults();
        $rows     = $base($search)->orderBy($order, $dir)->orderBy('id', $dir)->findAll($length, $start);

        $data = [];
        foreach ($rows as $r) {
            $data[] = [
                'date'    => \App\Libraries\AppDateTime::format($r['paid_at'] ?? null, 'd M Y', (string) ($r['paid_at'] ?? '')),
                'time'    => \App\Libraries\AppDateTime::format($r['paid_at'] ?? null, 'h:i A', ''),
                'invoice' => (string) ($r['invoice_no'] ?? ''),
                'credit'  => number_format((float) ($r['credit_amount'] ?? 0), 2),
                'tax'     => number_format((float) ($r['tax_amount'] ?? 0), 2),
                'total'   => number_format((float) ($r['total_amount'] ?? 0), 2),
                'url'     => site_url('wallet/invoice/' . (int) $r['id'] . '?print=1'),
            ];
        }

        return $this->response->setJSON([
            'draw'            => (int) ($get['draw'] ?? 0),
            'recordsTotal'    => $total,
            'recordsFiltered' => $filtered,
            'data'            => $data,
        ]);
    }

    /** Step 1 of an online recharge: create the Razorpay order. */
    public function rechargeOrder(): ResponseInterface
    {
        if ($denied = $this->requirePermission('settings.edit')) {
            return $denied;
        }

        $tenantKey = (string) (TenantContext::get() ?: session('tenant_key') ?: '');

        if ((string) $this->request->getPost('gateway') === 'mswipe') {
            $user = model(\App\Models\UserModel::class)->find((int) session('user_id')) ?: [];
            $res  = (new MswipeService())->createLink(
                $tenantKey,
                (float) $this->request->getPost('amount'),
                (int) session('user_id'),
                (string) ($user['phone'] ?? $user['mobile'] ?? ''),
                (string) ($user['email'] ?? ''),
                site_url('wallet/recharge/return')
            );

            return $res['ok']
                ? $this->jsonResponse(true, ['gateway' => 'mswipe', 'redirect_url' => $res['url']], 'Redirecting to Mswipe.')
                : $this->jsonResponse(false, null, $res['message'], [], $res['status']);
        }

        try {
            $res = (new RazorpayService())->createOrder(
                $tenantKey,
                (float) $this->request->getPost('amount'),
                (int) session('user_id')
            );
        } catch (\Throwable $e) {
            log_message('error', 'Wallet::rechargeOrder failed: {msg}', ['msg' => $e->getMessage()]);

            return $this->jsonResponse(false, null, 'Could not start the payment. Please try again.', [], 500);
        }
        if (! $res['ok']) {
            return $this->jsonResponse(false, null, $res['message'], [], $res['status']);
        }

        return $this->jsonResponse(true, [
            'gateway' => 'razorpay', 'order_id' => $res['order_id'], 'key_id' => $res['key_id'], 'amount' => $res['amount'], 'currency' => $res['currency'],
            'name' => (string) service('settingsService')->get('app_name', 'Sateri Connect'),
        ], 'Order created.');
    }

    /** Step 2: the browser returns the payment proof; verify it and credit the wallet. */
    public function rechargeVerify(): ResponseInterface
    {
        if ($denied = $this->requirePermission('settings.edit')) {
            return $denied;
        }

        try {
            $res = (new RazorpayService())->confirm(
                trim((string) $this->request->getPost('razorpay_order_id')),
                trim((string) $this->request->getPost('razorpay_payment_id')),
                trim((string) $this->request->getPost('razorpay_signature'))
            );
        } catch (\Throwable $e) {
            log_message('error', 'Wallet::rechargeVerify failed: {msg}', ['msg' => $e->getMessage()]);

            return $this->jsonResponse(false, null, 'We could not confirm your payment yet. If money was deducted it will be credited automatically within a few minutes.', [], 500);
        }

        return $this->jsonResponse($res['ok'], null, $res['message'], [], $res['ok'] ? 200 : $res['status']);
    }

    /** The customer comes back from Mswipe: check the payment with Mswipe itself, then show the result. */
    public function rechargeReturn(): ResponseInterface
    {
        if ($denied = $this->requirePermission('settings.view')) {
            return $denied;
        }

        $orderId = trim((string) $this->request->getGet('order'));
        if (preg_match('/^[A-Za-z0-9]{6,40}$/', $orderId) !== 1) {
            return redirect()->to(site_url('wallet'));
        }
        $res = (new MswipeService())->reconcile($orderId);
        $key = ($res['state'] ?? '') === 'paid' ? 'success' : (($res['state'] ?? '') === 'pending' ? 'info' : 'error');

        return redirect()->to(site_url('wallet'))->with($key, $res['message']);
    }

    /** Printable invoice (use the browser's Save as PDF). */
    public function invoice(int $id): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('settings.view')) {
            return $denied;
        }

        $row = model(WalletPaymentModel::class)->where('id', $id)->where('status', 'paid')->first();
        if (! is_array($row)) {
            return $this->response->setStatusCode(404)->setBody('Invoice not found.');
        }
        $cfg = (new RazorpayService())->config();

        return view('wallet/invoice', [
            'p'      => $row,
            'seller' => ['name' => $cfg['invoice_company'], 'address' => $cfg['invoice_address'], 'gstin' => $cfg['invoice_gstin']],
            'client' => (string) service('settingsService')->get('app_name', 'Client'),
            'autoPrint' => $this->request->getGet('print') === '1',
        ]);
    }
}
