<?php

declare(strict_types=1);

namespace App\Libraries;

use Config\Database;
use Throwable;

/**
 * Stops the currency of a country's WhatsApp rate row from being changed once clients in that country already have
 * wallet history. A wallet's currency follows its country's rate row, and the ledger stores amounts without a
 * currency, so changing it later would convert the balance and leave old transactions in the wrong currency.
 */
class WalletCurrencyGuard
{
    /** @var array<string, list<string>>|null one sweep of every client per request, however many rows are checked */
    private static ?array $sweep = null;

    /**
     * Clients that already have wallet history, grouped by their country's calling code.
     *
     * @param string|null $onlyCode limit the check to one country
     *
     * @return array<string, list<string>> calling code => client names
     */
    public function lockedCountries(?string $onlyCode = null): array
    {
        if (self::$sweep === null) {
            self::$sweep = $this->sweepAll();
        }
        if ($onlyCode === null) {
            return self::$sweep;
        }
        $code = strtolower(trim($onlyCode));

        return isset(self::$sweep[$code]) ? [$code => self::$sweep[$code]] : [];
    }

    /**
     * @return array<string, list<string>>
     */
    private function sweepAll(): array
    {
        $onlyCode   = null;
        $out        = [];
        $connection = new TenantConnection();
        $snapshot   = $connection->capture();

        try {
            foreach ((new MasterTenantRepository())->listActiveTenants(null) as $tenant) {
                $code = strtolower(trim((string) ($tenant['country_code'] ?? '')));
                $key  = strtolower(trim((string) ($tenant['key'] ?? '')));
                if ($code === '' || $key === '' || ($onlyCode !== null && $code !== strtolower(trim($onlyCode)))) {
                    continue;
                }

                try {
                    if (! $connection->apply($key, 'wa-rates-guard')) {
                        continue;
                    }
                    if ($this->hasHistory(Database::connect())) {
                        $out[$code][] = (string) ($tenant['name'] ?? $key);
                    }
                } catch (Throwable $e) {
                    log_message('error', 'WalletCurrencyGuard [{key}]: {msg}', ['key' => $key, 'msg' => $e->getMessage()]);
                }
            }
        } finally {
            $connection->restoreIfChanged($snapshot);
        }

        return $out;
    }

    /**
     * @return list<string> client names, empty when the currency may be changed
     */
    public function lockedFor(string $code): array
    {
        return $this->lockedCountries($code)[strtolower(trim($code))] ?? [];
    }

    /** True when the wallet has any transaction or a non-zero balance. */
    private function hasHistory(object $db): bool
    {
        if ($db->tableExists('wallet_transactions') && (int) $db->table('wallet_transactions')->countAllResults() > 0) {
            return true;
        }
        if ($db->tableExists('wallets')) {
            $w = $db->table('wallets')->where('id', 1)->get()->getRowArray();

            return is_array($w) && abs((float) ($w['balance'] ?? 0)) > 0;
        }

        return false;
    }

    /** Operator-facing message for a blocked currency change. */
    public static function message(string $countryName, array $clients): string
    {
        $names = implode(', ', array_slice($clients, 0, 5)) . (count($clients) > 5 ? ' and ' . (count($clients) - 5) . ' more' : '');

        return 'The currency for ' . $countryName . ' cannot be changed because clients there already have wallet transactions (' . $names . '). '
            . 'Their balances and history are in the current currency.';
    }
}
