<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\NotificationModel;
use App\Models\WalletModel;
use App\Models\WalletTransactionModel;
use Throwable;

/**
 * Client WhatsApp wallet (tenant DB). Charges follow Meta's policy: per message, by category and the
 * recipient's country, with 1,000 free service messages per month.
 *
 * Meta bills delivered messages, but the send path cannot know that yet, so a message is debited when it
 * is sent and given back when Meta reports it failed or not billable (settleFromStatus), or when it was
 * never delivered within Meta's retry window (releaseStale). The wallet only enforces anything while
 * `wallets.enabled` is 1. The platform admin can add GST (`tax_percent`), which is charged on top of the
 * rate card price.
 *
 * Tables are created on first use through the models' self-healing schema, so no manual migration is
 * needed on a client database.
 *
 * Every method returns ['ok' => bool, 'status' => int, 'message' => string, ...] and never throws.
 */
class WalletService
{
    public const FREE_SERVICE_PER_MONTH = 1000;

    /** Meta stops retrying an undelivered message after 30 days; it is not billed then. */
    public const UNDELIVERED_DAYS = 30;

    /** One low-balance notification per this many seconds. */
    protected const ALERT_INTERVAL = 43200;

    protected function db(): \CodeIgniter\Database\BaseConnection
    {
        return db_connect();
    }

    /**
     * @return array<string, mixed>
     */
    public function wallet(): array
    {
        try {
            // Instantiating the models creates missing wallet tables / columns on this database.
            model(WalletModel::class);
            model(WalletTransactionModel::class);

            $db = $this->db();
            if (! $db->tableExists('wallets')) {
                return $this->emptyWallet();
            }
            $row = $db->table('wallets')->where('id', 1)->get()->getRowArray();
            if (! is_array($row)) {
                $db->table('wallets')->ignore(true)->insert(['id' => 1, 'updated_at' => date('Y-m-d H:i:s')]);
                $row = $db->table('wallets')->where('id', 1)->get()->getRowArray();
            }

            return is_array($row) ? $row : $this->emptyWallet();
        } catch (Throwable $e) {
            log_message('error', 'WalletService::wallet failed: {msg}', ['msg' => $e->getMessage()]);

            return $this->emptyWallet();
        }
    }

    public function isEnabled(): bool
    {
        return (int) ($this->wallet()['enabled'] ?? 0) === 1;
    }

    public function balance(): float
    {
        return (float) ($this->wallet()['balance'] ?? 0);
    }

    public function currency(): string
    {
        return (string) ($this->wallet()['currency'] ?? Currency::base());
    }

    /**
     * One answer for "is this wallet OK?", used by the top bar, the wallet page and the platform list.
     * state: off (not enforced), ok, low (below the alert level) or empty (nothing left to send with).
     *
     * @param array<string, mixed>|null $wallet a row already loaded, to avoid another query
     *
     * @return array{state: string, balance: float, threshold: float, currency: string}
     */
    public function health(?array $wallet = null): array
    {
        $w         = $wallet ?? $this->wallet();
        $balance   = (float) ($w['balance'] ?? 0);
        $threshold = (float) ($w['low_balance_alert'] ?? 0);
        $state     = 'off';
        if ((int) ($w['enabled'] ?? 0) === 1) {
            $state = $balance <= 0 ? 'empty' : ($threshold > 0 && $balance < $threshold ? 'low' : 'ok');
        }

        return ['state' => $state, 'balance' => $balance, 'threshold' => $threshold, 'currency' => (string) ($w['currency'] ?? Currency::base())];
    }

    /**
     * Price of one message: Meta price + platform markup, then GST on that. Pure, so it is unit tested.
     *
     * @return array{amount: float, base: float, tax: float}
     */
    public static function price(float $base, float $markupPercent, float $taxPercent): array
    {
        $subtotal = $base * (1 + max(0.0, $markupPercent) / 100);
        $tax      = $subtotal * max(0.0, $taxPercent) / 100;

        return ['amount' => round($subtotal + $tax, 4), 'base' => round($base, 4), 'tax' => round($tax, 4)];
    }

    /** Platform margin added on the Meta price before GST. The client only ever sees the final price. */
    public function markupPercent(): float
    {
        return max(0.0, (float) ($this->wallet()['markup_percent'] ?? 0));
    }

    public function taxPercent(): float
    {
        return (float) ($this->wallet()['tax_percent'] ?? 0);
    }

    /**
     * @return array{ok: bool, status: int, message: string}
     */
    public function setEnabled(bool $enabled): array
    {
        $this->wallet();

        return $this->run(fn (): array => $this->saveWallet(['enabled' => $enabled ? 1 : 0]));
    }

    /**
     * Keep the wallet in the currency of the client's country. When the country was saved (or changed) without the
     * wallet following, the balance and limits are converted at the platform exchange rate, exactly as when the
     * platform admin changes the country. Safe to call often: it checks at most once every few minutes per client
     * database, does nothing when the country is unknown or already matches, and never throws.
     *
     * @return array{changed: bool, message: string}
     */
    public function alignCurrencyToCountry(): array
    {
        $none = ['changed' => false, 'message' => ''];

        try {
            $cache = cache();
            $key   = 'wallet_currency_aligned_' . md5((string) $this->db()->getDatabase());
            if ($cache->get($key)) {
                return $none;
            }

            $tenant = (string) (TenantContext::get() ?? '');
            if ($tenant === '' || ! MasterTenantRepository::masterConfigured()) {
                return $none;
            }
            // Throws when the master database is unreachable; nothing is remembered then, so the next page retries.
            $country = (new MasterTenantRepository())->tenantCountry($tenant);
            // Remember the check only after the master answered. No country: look again soon, it may be set any minute.
            $cache->save($key, 1, $country === '' ? 60 : 300);
            if ($country === '' || $country === 'other' || WhatsAppCountries::nameOf($country) === '') {
                return $none;
            }
            $target = (new WalletRateCard())->currencyForCountry($country);
            if ($target === strtoupper($this->currency())) {
                return $none;
            }

            $result = $this->setCurrency($target, true);
            if ($result['ok']) {
                log_activity('update', 'wallet', 'Wallet currency followed the client country', ['country_code' => $country, 'currency' => $target]);
            }

            return ['changed' => $result['ok'], 'message' => (string) $result['message']];
        } catch (Throwable $e) {
            log_message('warning', 'WalletService::alignCurrencyToCountry failed: {msg}', ['msg' => $e->getMessage()]);

            return $none;
        }
    }

    /**
     * Wallet currency, taken from the client's country. Only while the wallet has no history, because
     * old amounts would otherwise change meaning.
     *
     * @return array{ok: bool, status: int, message: string}
     */
    public function setCurrency(string $currency, bool $convertBalance = false): array
    {
        $currency = strtoupper(trim($currency));
        if (! Currency::isKnown($currency)) {
            return $this->fail(422, 'Unsupported wallet currency.');
        }
        if ($currency === strtoupper($this->currency())) {
            return $this->ok('Wallet currency unchanged.');
        }

        return $this->run(function () use ($currency, $convertBalance): array {
            $used = (int) $this->db()->table('wallet_transactions')->countAllResults() > 0
                || abs((float) ($this->wallet()['balance'] ?? 0)) > 0;
            if ($used && ! $convertBalance) {
                return $this->fail(422, 'The wallet already has a balance or transactions in ' . $this->currency() . ', so its currency was not changed.');
            }
            if (! $used) {
                return $this->saveWallet(['currency' => $currency]);
            }

            return $this->convertWalletCurrency(strtoupper($this->currency()), $currency);
        });
    }

    /**
     * Move a used wallet to another currency at the platform exchange rate, in ONE statement inside a transaction.
     * The wallet row is locked first, so a charge or recharge cannot land between reading and writing the balance,
     * and the `WHERE currency = from` guard makes a repeated call a no-op. Every ledger row saved before currencies
     * were recorded is stamped with the old currency, so history keeps its meaning.
     *
     * @return array{ok: bool, status: int, message: string}
     */
    protected function convertWalletCurrency(string $from, string $to): array
    {
        $factor = (new WalletRateCard())->convert(1.0, $from, $to);
        if ($factor === null || $factor <= 0) {
            return $this->fail(422, 'No exchange rate is set for ' . $from . ' to ' . $to . ', so the wallet currency was not changed. Add it under Wallet rates.');
        }

        $db = $this->db();
        $db->transStart();
        $row = $db->query('SELECT `currency`, `balance` FROM `wallets` WHERE `id` = 1 FOR UPDATE')->getRowArray();
        if (strtoupper((string) ($row['currency'] ?? '')) !== $from) {
            $db->transComplete();

            return $this->ok('Wallet currency unchanged.');
        }
        $oldBalance = (float) ($row['balance'] ?? 0);

        $db->query('UPDATE `wallet_transactions` SET `currency` = ? WHERE `currency` IS NULL', [$from]);
        $db->query(
            'UPDATE `wallets` SET `currency` = ?, `balance` = ROUND(`balance` * ?, 4), `low_balance_alert` = ROUND(`low_balance_alert` * ?, 2),'
            . ' `daily_cap` = ROUND(`daily_cap` * ?, 2), `monthly_cap` = ROUND(`monthly_cap` * ?, 2), `auto_topup_amount` = ROUND(`auto_topup_amount` * ?, 2),'
            . ' `updated_at` = ? WHERE `id` = 1 AND `currency` = ?',
            [$to, $factor, $factor, $factor, $factor, $factor, date('Y-m-d H:i:s'), $from]
        );
        $changed = $db->affectedRows() > 0;
        $db->transComplete();

        if (! $db->transStatus()) {
            return $this->fail(500, 'Could not change the wallet currency.');
        }
        if ($changed) {
            log_activity('update', 'wallet', 'Wallet currency changed with balance converted', [
                'from' => $from, 'to' => $to, 'rate' => round($factor, 6), 'old_balance' => $oldBalance, 'new_balance' => round($oldBalance * $factor, 4),
            ]);
        }

        return $this->ok('Wallet updated.');
    }

    /**
     * Low-balance alert level and GST %, set by the platform admin.
     *
     * @return array{ok: bool, status: int, message: string}
     */
    public function updateSettings(float $lowBalanceAlert, float $taxPercent, ?float $markupPercent = null, ?array $limits = null): array
    {
        if (! is_finite($lowBalanceAlert) || ! is_finite($taxPercent) || ($markupPercent !== null && ! is_finite($markupPercent)) || $lowBalanceAlert < 0 || $lowBalanceAlert > 10000000) {
            return $this->fail(422, 'Low balance alert must be between 0 and 10,000,000.');
        }
        if ($taxPercent < 0 || $taxPercent > 100) {
            return $this->fail(422, 'GST must be between 0 and 100 percent.');
        }
        if ($markupPercent !== null && ($markupPercent < 0 || $markupPercent > 1000)) {
            return $this->fail(422, 'Markup must be between 0 and 1000 percent.');
        }
        foreach (['daily_cap', 'monthly_cap', 'auto_topup_amount'] as $k) {
            $v = $limits[$k] ?? null;
            if ($v !== null && (! is_finite((float) $v) || $v < 0 || $v > 10000000)) {
                return $this->fail(422, 'Limits and top-up amount must be between 0 and 10,000,000.');
            }
        }
        $this->wallet();

        $set = [
            'low_balance_alert' => round($lowBalanceAlert, 2),
            'tax_percent'       => round($taxPercent, 2),
        ];
        if ($markupPercent !== null) {
            $set['markup_percent'] = round($markupPercent, 2);
        }
        foreach (['daily_cap', 'monthly_cap', 'auto_topup_amount'] as $k) {
            if (isset($limits[$k])) {
                $set[$k] = round((float) $limits[$k], 2);
            }
        }

        return $this->run(fn (): array => $this->saveWallet($set));
    }

    /**
     * Net spend on messages (debits minus refunds, manual deductions excluded) since a moment.
     */
    public function spentSince(string $from): float
    {
        try {
            $rows = $this->db()->table('wallet_transactions')
                ->select('type, currency, COALESCE(SUM(amount), 0) AS a', false)
                ->whereIn('type', ['debit', 'refund'])->where('category !=', 'manual')
                ->where('created_at >=', $from)->groupBy('type, currency')->get()->getResultArray();
        } catch (Throwable) {
            return 0.0;
        }
        $wallet = strtoupper($this->currency());
        $card   = new WalletRateCard();
        $net    = 0.0;
        foreach ($rows as $r) {
            $a   = (float) $r['a'];
            $cur = strtoupper(trim((string) ($r['currency'] ?? '')));
            if ($cur !== '' && $cur !== $wallet) {
                $a = $card->convert($a, $cur, $wallet) ?? $a;
            }
            $net += ($r['type'] === 'refund' ? -1 : 1) * $a;
        }

        return max(0.0, $net);
    }

    /**
     * Daily and monthly spending limits with what is used so far. A cap of 0 means no limit.
     *
     * @return array{daily: array{cap: float, spent: float}, monthly: array{cap: float, spent: float}}
     */
    public function limits(?array $wallet = null): array
    {
        $w = $wallet ?? $this->wallet();
        $daily   = (float) ($w['daily_cap'] ?? 0);
        $monthly = (float) ($w['monthly_cap'] ?? 0);

        return [
            'daily'   => ['cap' => $daily, 'spent' => $daily > 0 ? $this->spentSince(date('Y-m-d 00:00:00')) : 0.0],
            'monthly' => ['cap' => $monthly, 'spent' => $monthly > 0 ? $this->spentSince(date('Y-m-01 00:00:00')) : 0.0],
        ];
    }

    /**
     * Room left under the tightest cap, or null when there is no cap.
     */
    public function capRoom(): ?float
    {
        $room = null;
        foreach ($this->limits() as $l) {
            if ($l['cap'] > 0) {
                $left = max(0.0, $l['cap'] - $l['spent']);
                $room = $room === null ? $left : min($room, $left);
            }
        }

        return $room;
    }

    /**
     * Cost of one message including GST, or null when no rate applies.
     *
     * @return array{amount: float, base: float, tax: float, rate: float, country_code: string, currency: string}|array{error: string}
     */
    public function quote(string $phone, string $category): array
    {
        // An international number is 7 to 15 digits (E.164). Anything else cannot be priced by country.
        $digits = ltrim(preg_replace('/\D+/', '', $phone) ?? '', '0');
        if (strlen($digits) < 7 || strlen($digits) > 15) {
            return ['error' => 'Invalid WhatsApp number. Use the full number with the country code.'];
        }

        $card = new WalletRateCard();
        $rate = $card->rateFor($digits, $category);
        if ($rate === null || $rate['rate'] <= 0) {
            // A zero rate is a missing rate, never a free message (service replies are free only within the monthly allowance).
            return ['error' => 'No WhatsApp rate is set for this country. Ask the platform admin to add it.'];
        }

        // The rate is in the country's own currency; the wallet is charged in its own currency.
        $converted = $card->convert($rate['rate'], $rate['currency'], $this->currency());
        if ($converted === null) {
            return ['error' => 'No exchange rate is set for ' . strtoupper($rate['currency']) . ' to ' . $this->currency() . '. Ask the platform admin to add it.'];
        }

        // amount = Meta price + platform markup + GST. `base` is what Meta charges, kept for the margin report.
        $price = self::price($converted, $this->markupPercent(), $this->taxPercent());

        return [
            'amount'       => $price['amount'],
            'base'         => $price['base'],
            'tax'          => $price['tax'],
            'rate'         => $rate['rate'],
            'country_code' => $rate['country_code'],
            'currency'     => $rate['currency'],
        ];
    }

    /**
     * What a template campaign to these numbers will cost. Used before a campaign starts.
     *
     * @param list<string> $phones
     *
     * @return array{total: float, messages: int, unpriced: int}
     */
    public function estimate(array $phones, string $category): array
    {
        $total = 0.0;
        $unpriced = 0;
        foreach ($phones as $phone) {
            $q = $this->quote((string) $phone, $category);
            if (isset($q['error'])) {
                $unpriced++;
                continue;
            }
            $total += $q['amount'];
        }

        return ['total' => round($total, 4), 'messages' => count($phones), 'unpriced' => $unpriced];
    }

    /** Shown when something tries to send while the wallet is off. Receiving messages is not affected. */
    public const OFF_MESSAGE = 'The WhatsApp wallet is off, so sending is paused. Incoming messages still arrive. Ask your platform admin to turn the wallet on.';

    /**
     * Debit one outbound message. Returns txn_id (0 when nothing was charged).
     *
     * @return array{ok: bool, status: int, message: string, txn_id?: int}
     */
    public function charge(string $phone, string $category): array
    {
        if (! $this->isEnabled()) {
            return $this->fail(402, self::OFF_MESSAGE);
        }

        if ($category === 'service' && $this->freeServiceUsed() < self::FREE_SERVICE_PER_MONTH) {
            $country = (new WalletRateCard())->rateFor($phone, 'service')['country_code'] ?? '';
            $txn = $this->ledger('debit', 0.0, 'service', $country, null, 'Free service message', ['base_amount' => 0, 'tax_amount' => 0]);

            return $txn !== null ? $this->ok('Free service message.', ['txn_id' => $txn]) : $this->fail(500, 'Wallet error. Try again.');
        }

        $quote = $this->quote($phone, $category);
        if (isset($quote['error'])) {
            return $this->fail(422, $quote['error']);
        }

        $amount = $quote['amount'];
        if ($amount <= 0) {
            $txn = $this->ledger('debit', 0.0, $category, $quote['country_code'], null, 'No charge', ['base_amount' => 0, 'tax_amount' => 0]);

            return $txn !== null ? $this->ok('No charge.', ['txn_id' => $txn]) : $this->fail(500, 'Wallet error. Try again.');
        }

        $tax  = $this->taxPercent();
        $note = 'WhatsApp ' . $category . ' message'
            . (strtoupper($quote['currency']) !== strtoupper($this->currency())
                ? ' (' . strtoupper($quote['currency']) . ' ' . rtrim(rtrim(number_format($quote['rate'], 4, '.', ''), '0'), '.') . ' converted)'
                : '')
            . ($tax > 0 ? ' (incl. GST ' . rtrim(rtrim(number_format($tax, 2, '.', ''), '0'), '.') . '%)' : '');

        $room = $this->capRoom();
        if ($room !== null && $amount > $room + 0.00001) {
            return $this->fail(402, 'Spending limit reached. The daily or monthly WhatsApp spending limit has been used. Ask your platform admin to raise it.');
        }

        try {
            $db = $this->db();
            $db->transStart();
            // Atomic: the balance never goes below zero even with parallel sends.
            $db->query('UPDATE `wallets` SET `balance` = `balance` - ?, `updated_at` = ? WHERE `id` = 1 AND `balance` >= ?', [
                $amount, date('Y-m-d H:i:s'), $amount,
            ]);
            if ($db->affectedRows() < 1) {
                $db->transComplete();
                $this->alertLowBalance(true);

                return $this->fail(402, 'Insufficient wallet balance. Recharge your wallet to keep sending.');
            }
            $txn = $this->insertTxn('debit', $amount, $category, $quote['country_code'], null, $note, null, ['base_amount' => $quote['base'], 'tax_amount' => $quote['tax']]);
            $db->transComplete();

            if (! $db->transStatus() || $txn < 1) {
                return $this->fail(500, 'Wallet error. Try again.');
            }
            $this->alertLowBalance(false);

            return $this->ok('Charged.', ['txn_id' => $txn, 'amount' => $amount]);
        } catch (Throwable $e) {
            log_message('error', 'WalletService::charge failed: {msg}', ['msg' => $e->getMessage()]);

            return $this->fail(500, 'Wallet error. Try again.');
        }
    }

    /** Remember Meta's message id on the debit so a later status can refund it. */
    public function attachReference(int $txnId, string $wamid): void
    {
        if ($txnId < 1 || $wamid === '') {
            return;
        }
        try {
            $this->db()->table('wallet_transactions')->where('id', $txnId)->where('reference', null)->update(['reference' => $wamid]);
        } catch (Throwable $e) {
            log_message('error', 'WalletService::attachReference failed: {msg}', ['msg' => $e->getMessage()]);
        }
    }

    /** Give back one debit (send failed before Meta accepted it). */
    public function refundTransaction(int $txnId, string $reason): void
    {
        if ($txnId < 1) {
            return;
        }
        try {
            $debit = $this->db()->table('wallet_transactions')->where('id', $txnId)->where('type', 'debit')->get()->getRowArray();
            if (is_array($debit) && (float) $debit['amount'] > 0) {
                $this->refund($debit, $reason, 'refund-tx-' . $txnId);
            }
        } catch (Throwable $e) {
            log_message('error', 'WalletService::refundTransaction failed: {msg}', ['msg' => $e->getMessage()]);
        }
    }

    /**
     * Meta status webhook: refund a message that failed or that Meta did not bill (free entry point,
     * free service window, non-billable).
     *
     * @param array<string, mixed> $status
     */
    public function settleFromStatus(array $status): void
    {
        $wamid = (string) ($status['id'] ?? '');
        $state = strtolower((string) ($status['status'] ?? ''));
        if ($wamid === '') {
            return;
        }

        // No enabled check: a charge already taken must be returned even if the wallet was switched off since.
        $pricing   = is_array($status['pricing'] ?? null) ? $status['pricing'] : [];
        $notBilled = array_key_exists('billable', $pricing) && $pricing['billable'] === false;
        if ($state !== 'failed' && ! $notBilled) {
            return;
        }

        try {
            $debit = $this->db()->table('wallet_transactions')
                ->where('type', 'debit')->where('reference', $wamid)->get()->getRowArray();
            if (is_array($debit) && (float) $debit['amount'] > 0) {
                $this->refund($debit, $state === 'failed' ? 'Message failed' : 'Not billed by Meta', $wamid);
            }
        } catch (Throwable $e) {
            log_message('error', 'WalletService::settleFromStatus failed: {msg}', ['msg' => $e->getMessage()]);
        }
    }

    /**
     * Give back debits for messages that were never delivered. Meta does not bill those, and stops
     * retrying after UNDELIVERED_DAYS. Run daily by `wallet:settle`.
     */
    public function releaseStale(int $days = self::UNDELIVERED_DAYS, int $limit = 500): int
    {
        $count = 0;
        try {
            $db = $this->db();
            if (! $db->tableExists('wallet_transactions') || ! $db->tableExists('messages')) {
                return 0;
            }

            $cutoff = date('Y-m-d H:i:s', strtotime('-' . max(1, $days) . ' days'));
            $rows = $db->table('wallet_transactions t')
                ->select('t.*')
                ->where('t.type', 'debit')->where('t.amount >', 0)
                ->where('t.reference IS NOT NULL', null, false)
                ->where('t.created_at <', $cutoff)
                ->where("NOT EXISTS (SELECT 1 FROM wallet_transactions r WHERE r.type = 'refund' AND r.reference = t.reference)", null, false)
                ->orderBy('t.id', 'ASC')->limit(max(1, min(2000, $limit)))
                ->get()->getResultArray();

            foreach ($rows as $row) {
                $msg = $db->table('messages')->select('status')
                    ->groupStart()->where('wa_message_id', $row['reference'])->orWhere('wamid', $row['reference'])->groupEnd()
                    ->get()->getRowArray();
                if (is_array($msg) && in_array((string) $msg['status'], ['delivered', 'read'], true)) {
                    continue;
                }
                $this->refund($row, 'Not delivered within ' . $days . ' days', (string) $row['reference']);
                $count++;
            }
        } catch (Throwable $e) {
            log_message('error', 'WalletService::releaseStale failed: {msg}', ['msg' => $e->getMessage()]);
        }

        return $count;
    }

    /**
     * Platform admin adds money. Messages that failed only because the balance was too low in the last
     * two days are queued again.
     *
     * @return array{ok: bool, status: int, message: string}
     */
    public function credit(float $amount, string $description, ?int $userId = null, ?string $reference = null): array
    {
        if (! is_finite($amount) || $amount <= 0 || $amount > 10000000) {
            return $this->fail(422, 'Enter an amount between 0.01 and 10,000,000.');
        }

        $result = $this->run(function () use ($amount, $description, $userId, $reference): array {
            $this->wallet();
            $db = $this->db();
            // A payment is credited once, however many times its confirmation arrives. The wallet row is locked
            // first so the webhook and the browser callback cannot both pass the check at the same time.
            $db->transStart();
            $db->query('SELECT `id` FROM `wallets` WHERE `id` = 1 FOR UPDATE');
            if ($reference !== null && $reference !== ''
                && $db->table('wallet_transactions')->where('type', 'credit')->where('reference', $reference)->countAllResults() > 0) {
                $db->transComplete();

                return $this->ok('Already credited.', ['duplicate' => true]);
            }
            $db->query('UPDATE `wallets` SET `balance` = `balance` + ?, `last_low_alert_at` = NULL, `updated_at` = ? WHERE `id` = 1', [$amount, date('Y-m-d H:i:s')]);
            $this->insertTxn('credit', $amount, null, null, $reference, $description, $userId);
            $db->transComplete();

            return $db->transStatus() ? $this->ok('Wallet credited.') : $this->fail(500, 'Could not credit the wallet.');
        });

        if ($result['ok'] && $this->isEnabled()) {
            $requeued = $this->requeueWalletFailed();
            if ($requeued > 0) {
                $result['message'] .= ' ' . $requeued . ' message' . ($requeued === 1 ? '' : 's') . ' that failed for low balance will be sent again.';
            }
        }

        return $result;
    }

    /**
     * Platform admin takes money back (correction). Never below zero.
     *
     * @return array{ok: bool, status: int, message: string}
     */
    public function manualDebit(float $amount, string $description, ?int $userId = null): array
    {
        if (! is_finite($amount) || $amount <= 0 || $amount > 10000000) {
            return $this->fail(422, 'Enter an amount between 0.01 and 10,000,000.');
        }

        return $this->run(function () use ($amount, $description, $userId): array {
            $this->wallet();
            $db = $this->db();
            $db->transStart();
            $db->query('UPDATE `wallets` SET `balance` = `balance` - ?, `updated_at` = ? WHERE `id` = 1 AND `balance` >= ?', [
                $amount, date('Y-m-d H:i:s'), $amount,
            ]);
            if ($db->affectedRows() < 1) {
                $db->transComplete();

                return $this->fail(422, 'Balance is lower than the amount to deduct.');
            }
            $this->insertTxn('debit', $amount, 'manual', null, null, $description, $userId);
            $db->transComplete();

            return $db->transStatus() ? $this->ok('Amount deducted.') : $this->fail(500, 'Could not update the wallet.');
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function transactions(int $limit = 100): array
    {
        try {
            if (! $this->db()->tableExists('wallet_transactions')) {
                return [];
            }

            return $this->db()->table('wallet_transactions')->orderBy('id', 'DESC')->limit(max(1, min(500, $limit)))->get()->getResultArray();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * One page of transactions for a server-side table: filtered, searched and sorted in SQL.
     *
     * @param array{start?:int,length?:int,search?:string,type?:string,order?:string,dir?:string} $opts
     *
     * @return array{total:int,filtered:int,rows:list<array<string, mixed>>}
     */
    public function transactionsPage(array $opts): array
    {
        $empty = ['total' => 0, 'filtered' => 0, 'rows' => []];

        try {
            if (! $this->db()->tableExists('wallet_transactions')) {
                return $empty;
            }

            $sortable = ['created_at', 'type', 'description', 'amount', 'balance_after'];
            $order    = in_array($opts['order'] ?? '', $sortable, true) ? (string) $opts['order'] : 'created_at';
            $dir      = strtolower((string) ($opts['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
            $start    = max(0, (int) ($opts['start'] ?? 0));
            $length   = max(1, min(100, (int) ($opts['length'] ?? 10)));
            $search   = trim((string) ($opts['search'] ?? ''));
            $type     = (string) ($opts['type'] ?? '');

            $apply = function ($b) use ($search, $type) {
                if (in_array($type, ['credit', 'debit', 'refund'], true)) {
                    $b->where('type', $type);
                }
                if ($search !== '') {
                    $b->groupStart()
                        ->like('description', $search)
                        ->orLike('reference', $search)
                        ->orLike('country_code', ltrim($search, '+'))
                        ->orLike('CAST(amount AS CHAR)', $search)
                        ->groupEnd();
                }

                return $b;
            };

            $total    = (int) $this->db()->table('wallet_transactions')->countAllResults();
            $filtered = (int) $apply($this->db()->table('wallet_transactions'))->countAllResults();
            $rows     = $apply($this->db()->table('wallet_transactions'))
                ->orderBy($order, $dir)
                ->orderBy('id', $dir)
                ->limit($length, $start)
                ->get()
                ->getResultArray();

            return ['total' => $total, 'filtered' => $filtered, 'rows' => $rows];
        } catch (Throwable $e) {
            log_message('error', 'WalletService::transactionsPage failed: {msg}', ['msg' => $e->getMessage()]);

            return $empty;
        }
    }

    /**
     * Display-ready rows for the transactions table (dates in the app timezone, numbers formatted).
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    public static function tableRows(array $rows): array
    {
        $labels = ['credit' => 'Recharge', 'debit' => 'Message / deduction', 'refund' => 'Refund'];
        $out    = [];
        foreach ($rows as $t) {
            $type  = (string) ($t['type'] ?? '');
            $out[] = [
                'date'    => AppDateTime::format($t['created_at'] ?? null, 'd M Y', (string) ($t['created_at'] ?? '')),
                'time'    => AppDateTime::format($t['created_at'] ?? null, 'h:i A', ''),
                'type'    => $type,
                'label'   => $labels[$type] ?? $type,
                'details' => (string) ($t['description'] ?? '') . (! empty($t['country_code']) ? ' · +' . $t['country_code'] : ''),
                'in'      => in_array($type, ['credit', 'refund'], true),
                'amount'  => number_format((float) ($t['amount'] ?? 0), 4),
                'balance' => number_format((float) ($t['balance_after'] ?? 0), 2),
                // The currency the row was saved in; '' (older rows) means the wallet's current currency.
                'currency' => strtoupper(trim((string) ($t['currency'] ?? ''))),
                'symbol'   => WhatsAppCountries::symbolOf((string) ($t['currency'] ?? '')),
            ];
        }

        return $out;
    }

    public function freeServiceUsed(): int
    {
        try {
            return (int) $this->db()->table('wallet_transactions')
                ->where('type', 'debit')->where('category', 'service')->where('amount', 0)
                ->where('created_at >=', date('Y-m-01 00:00:00'))
                ->countAllResults();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Queue items that failed in the last 48 hours only because the wallet was empty go back to pending.
     */
    protected function requeueWalletFailed(): int
    {
        try {
            $db = $this->db();
            if (! $db->tableExists('message_queue')) {
                return 0;
            }
            $db->table('message_queue')
                ->where('status', 'failed')
                ->groupStart()->like('error_message', 'Insufficient wallet balance', 'after')->orLike('error_message', 'Spending limit reached', 'after')->groupEnd()
                ->where('updated_at >=', date('Y-m-d H:i:s', strtotime('-48 hours')))
                ->limit(500)
                ->update(['status' => 'pending', 'attempts' => 0, 'error_message' => null, 'scheduled_at' => date('Y-m-d H:i:s')]);

            return max(0, (int) $db->affectedRows());
        } catch (Throwable $e) {
            log_message('error', 'WalletService::requeueWalletFailed failed: {msg}', ['msg' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * In-app notification to the workspace admins when the balance falls below the alert level (or a send
     * was refused because it ran out). At most one every 12 hours; a recharge resets it.
     */
    protected function alertLowBalance(bool $empty): void
    {
        try {
            $w = $this->wallet();
            $h = $this->health($w);
            $balance = $h['balance'];
            if ($h['state'] === 'off' || (! $empty && ! in_array($h['state'], ['low', 'empty'], true))) {
                return;
            }
            $last = (string) ($w['last_low_alert_at'] ?? '');
            if ($last !== '' && (time() - (int) strtotime($last)) < self::ALERT_INTERVAL) {
                return;
            }

            $db = $this->db();
            $admins = $db->table('users u')->select('u.id, u.email')
                ->join('roles r', 'r.id = u.role_id')
                ->groupStart()->whereIn('r.slug', ['super-admin', 'super_admin', 'admin'])->orLike('r.slug', 'admin')->groupEnd()
                ->where('u.status', 'active')->where('u.deleted_at', null)
                ->get()->getResultArray();

            $symbol  = Currency::symbol((string) ($w['currency'] ?? Currency::base()));
            $title   = $empty ? 'WhatsApp wallet is empty' : 'WhatsApp wallet balance is low';
            $message = ($empty ? 'A message could not be sent because the wallet has no balance. ' : '')
                . 'Balance: ' . $symbol . number_format($balance, 2) . '. Contact your platform admin to recharge.';
            // Optional one-click top-up: a Razorpay payment link for the amount the platform admin set.
            $payUrl = null;
            $topup  = (float) ($w['auto_topup_amount'] ?? 0);
            if ($topup > 0) {
                $tenantKey = (string) (TenantContext::get() ?: session('tenant_key') ?: '');
                $link = (new RazorpayService())->createPaymentLink($tenantKey, $topup, (string) service('settingsService')->get('app_name', ''));
                if ($link['ok'] && ! empty($link['url'])) {
                    $payUrl   = (string) $link['url'];
                    $message .= ' Add money online: ' . $payUrl;
                }
            }

            $notes = model(NotificationModel::class);
            foreach ($admins as $a) {
                $notes->insert(['user_id' => (int) $a['id'], 'title' => $title, 'message' => $message, 'type' => 'wallet', 'is_read' => 0, 'link' => $payUrl ?? site_url('wallet')]);
            }
            $this->emailLowBalance($admins, $title, $message, $payUrl);
            $this->saveWallet(['last_low_alert_at' => date('Y-m-d H:i:s')]);
        } catch (Throwable $e) {
            log_message('error', 'WalletService::alertLowBalance failed: {msg}', ['msg' => $e->getMessage()]);
        }
    }

    /**
     * Best-effort email copy of the low-balance alert to the same people. The in-app notification is the
     * reliable one; a missing or failing email setup is only logged.
     *
     * @param list<array<string, mixed>> $admins
     */
    protected function emailLowBalance(array $admins, string $title, string $message, ?string $payUrl = null): void
    {
        try {
            $to = array_values(array_unique(array_filter(array_map(
                static fn (array $a): string => trim((string) ($a['email'] ?? '')),
                $admins
            ), static fn (string $e): bool => filter_var($e, FILTER_VALIDATE_EMAIL) !== false)));
            if ($to === []) {
                return;
            }

            $html = '<div style="font-family:Arial,sans-serif;max-width:480px">'
                . '<h2 style="margin:0 0 8px">' . esc($title) . '</h2><p>' . esc($message) . '</p>'
                . ($payUrl !== null ? '<p><a href="' . esc($payUrl) . '" style="display:inline-block;background:#e11d48;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:bold">Add money now</a></p>' : '')
                . '<p><a href="' . esc(site_url('wallet')) . '">Open wallet</a></p></div>';
            $driver = (new EmailProvider())->getDriver();
            foreach ($to as $email) {
                $driver->sendHtml($email, $title, $html);
            }
        } catch (Throwable $e) {
            log_message('warning', 'WalletService low-balance email skipped: {msg}', ['msg' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string, mixed> $debit
     */
    protected function refund(array $debit, string $reason, string $reference): void
    {
        $db = $this->db();
        $db->transStart();
        // Serialise on the wallet row so duplicate webhook deliveries cannot both pass the check below.
        $db->query('SELECT `id` FROM `wallets` WHERE `id` = 1 FOR UPDATE');
        $already = $db->table('wallet_transactions')->where('type', 'refund')->where('reference', $reference)->countAllResults();
        if ($already > 0) {
            $db->transComplete();

            return;
        }

        // Give back what was taken, in today's wallet currency: a debit saved in another currency (before the wallet
        // was converted) is converted at the platform rate, so an amount saved in one currency is never added as if it were another.
        $amount = (float) $debit['amount'];
        $base   = $debit['base_amount'] ?? null;
        $tax    = $debit['tax_amount'] ?? null;
        $from   = strtoupper(trim((string) ($debit['currency'] ?? '')));
        $to     = strtoupper($this->currency());
        if ($from !== '' && $from !== $to) {
            $factor = (new WalletRateCard())->convert(1.0, $from, $to);
            if ($factor !== null && $factor > 0) {
                $amount = round($amount * $factor, 4);
                $base   = $base !== null ? round((float) $base * $factor, 4) : null;
                $tax    = $tax !== null ? round((float) $tax * $factor, 4) : null;
            }
        }

        $db->query('UPDATE `wallets` SET `balance` = `balance` + ?, `updated_at` = ? WHERE `id` = 1', [$amount, date('Y-m-d H:i:s')]);
        $this->insertTxn('refund', $amount, (string) ($debit['category'] ?? ''), (string) ($debit['country_code'] ?? ''), $reference, $reason, null,
            ['base_amount' => $base, 'tax_amount' => $tax]);
        $db->transComplete();
    }

    protected function ledger(string $type, float $amount, ?string $category, ?string $country, ?string $reference, string $description, array $extra = []): ?int
    {
        try {
            $id = $this->insertTxn($type, $amount, $category, $country, $reference, $description, null, $extra);

            return $id > 0 ? $id : null;
        } catch (Throwable $e) {
            log_message('error', 'WalletService::ledger failed: {msg}', ['msg' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param array{base_amount?: float|int|string|null, tax_amount?: float|int|string|null} $extra Meta cost and GST of a message, for margin
     */
    protected function insertTxn(string $type, float $amount, ?string $category, ?string $country, ?string $reference, string $description, ?int $userId, array $extra = []): int
    {
        $db = $this->db();
        $w = $db->table('wallets')->select('balance, currency')->where('id', 1)->get()->getRowArray();
        $balance = (float) ($w['balance'] ?? 0);
        $currency = strtoupper(trim((string) ($w['currency'] ?? '')));
        $db->table('wallet_transactions')->insert([
            'type'          => $type,
            'amount'        => $amount,
            'currency'      => $currency !== '' ? $currency : null,
            'balance_after' => $balance,
            'category'      => $category !== '' ? $category : null,
            'country_code'  => $country !== '' ? $country : null,
            'reference'     => $reference,
            'description'   => mb_substr($description, 0, 255),
            'base_amount'   => $extra['base_amount'] ?? null,
            'tax_amount'    => $extra['tax_amount'] ?? null,
            'created_by'    => $userId,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        return (int) $db->insertID();
    }

    /**
     * @param array<string, mixed> $set
     *
     * @return array{ok: bool, status: int, message: string}
     */
    protected function saveWallet(array $set): array
    {
        $set['updated_at'] = date('Y-m-d H:i:s');

        return $this->db()->table('wallets')->where('id', 1)->update($set)
            ? $this->ok('Wallet updated.')
            : $this->fail(500, 'Could not update the wallet.');
    }

    /**
     * @return array{ok: bool, status: int, message: string}
     */
    protected function run(callable $fn): array
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            log_message('error', 'WalletService failed: {msg}', ['msg' => $e->getMessage()]);

            return $this->fail(500, 'Wallet error. See the application log for details.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function emptyWallet(): array
    {
        return ['id' => 1, 'enabled' => 0, 'balance' => 0, 'currency' => Currency::base(), 'low_balance_alert' => 0, 'tax_percent' => 0, 'markup_percent' => 0, 'daily_cap' => 0, 'monthly_cap' => 0, 'auto_topup_amount' => 0, 'last_low_alert_at' => null];
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    protected function ok(string $message, array $extra = []): array
    {
        return ['ok' => true, 'status' => 200, 'message' => $message] + $extra;
    }

    /**
     * @return array{ok: bool, status: int, message: string}
     */
    protected function fail(int $status, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'message' => $message];
    }
}
