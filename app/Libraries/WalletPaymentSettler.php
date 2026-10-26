<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\WalletPaymentModel;
use Throwable;

/**
 * Gateway-neutral last step of an online recharge: the gateway says an order is paid, this credits the wallet
 * once and stamps the invoice. Used by RazorpayService and MswipeService.
 *
 * Returns ['ok', 'status', 'message'] and never throws.
 */
class WalletPaymentSettler
{
    /**
     * @param int|null $paidPaise amount the gateway reports (paise); must match the order when given
     * @param string   $label     gateway name for the ledger text, for example "Razorpay"
     * @param string   $refPrefix idempotency prefix, so a payment id is credited once per gateway
     *
     * @return array{ok: bool, status: int, message: string}
     */
    public function settle(string $orderId, string $paymentId, ?int $paidPaise, string $label, string $refPrefix): array
    {
        try {
            $payments = model(WalletPaymentModel::class);
            $row = $payments->where('order_id', $orderId)->first();
            if (! is_array($row)) {
                return $this->r(false, 404, 'Unknown payment order.');
            }
            if ($row['status'] === 'paid') {
                return $this->r(true, 200, 'Payment already credited.');
            }
            if ($paidPaise !== null && $paidPaise !== (int) round((float) $row['total_amount'] * 100)) {
                log_message('error', '{g} amount mismatch for {o}: paid {p} paise, expected {e}', ['g' => $label, 'o' => $orderId, 'p' => $paidPaise, 'e' => (float) $row['total_amount'] * 100]);

                return $this->r(false, 409, 'Paid amount does not match the order.');
            }
            if ($paymentId === '') {
                return $this->r(false, 422, 'Missing payment reference.');
            }

            $res = (new WalletService())->credit((float) $row['credit_amount'], 'Online recharge (' . $label . ' ' . $paymentId . ')', null, $refPrefix . $paymentId);
            if (! $res['ok']) {
                return $this->r(false, 500, 'Payment received but the wallet could not be credited. It will be retried.');
            }

            $now = date('Y-m-d H:i:s');
            $tenantKey = (string) (TenantContext::get() ?: session('tenant_key') ?: '');
            $payments->update((int) $row['id'], [
                'status' => 'paid', 'payment_id' => $paymentId, 'paid_at' => $now, 'updated_at' => $now,
                'invoice_no' => RazorpayService::invoiceNumber($tenantKey, (int) $row['id'], $now),
            ]);
            log_activity('recharge', 'wallet', 'Wallet recharged online', ['gateway' => $label, 'order_id' => $orderId, 'payment_id' => $paymentId, 'credit' => (float) $row['credit_amount']]);

            return $this->r(true, 200, 'Wallet recharged with ' . number_format((float) $row['credit_amount'], 2) . '.');
        } catch (Throwable $e) {
            log_message('error', 'WalletPaymentSettler::settle failed: {msg}', ['msg' => $e->getMessage()]);

            return $this->r(false, 500, 'Could not record the payment.');
        }
    }

    /** Mark an order failed (the customer did not pay). A later success still settles it. */
    public function markFailed(string $orderId): void
    {
        try {
            $payments = model(WalletPaymentModel::class);
            $row = $payments->where('order_id', $orderId)->first();
            if (is_array($row) && $row['status'] === 'created') {
                $payments->update((int) $row['id'], ['status' => 'failed', 'updated_at' => date('Y-m-d H:i:s')]);
            }
        } catch (Throwable $e) {
            log_message('warning', 'WalletPaymentSettler::markFailed: {msg}', ['msg' => $e->getMessage()]);
        }
    }

    /**
     * @return array{ok: bool, status: int, message: string}
     */
    private function r(bool $ok, int $status, string $message): array
    {
        return ['ok' => $ok, 'status' => $status, 'message' => $message];
    }
}
