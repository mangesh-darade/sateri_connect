<?php

declare(strict_types=1);

namespace App\Libraries;

use Throwable;

/**
 * Meta WhatsApp per-message rates by recipient country (calling code), kept in the master DB so the
 * platform sets them once for every client.
 *
 * Meta bills per delivered message by category (marketing / utility / authentication) and by the
 * recipient's country calling code. Service (free-form) messages are free for 1,000 per phone number
 * each month and billed after that. A row with country_code "other" is the fallback market.
 *
 * Seeded values are Meta's published India list rates (INR, before GST) and must be checked against
 * Meta's current rate card; Meta only changes prices on 1 Jan / Apr / Jul / Oct.
 */
class WalletRateCard
{
    public const CATEGORIES = ['marketing', 'utility', 'authentication', 'service'];

    protected static bool $ready = false;

    /** @var list<array<string, mixed>>|null */
    protected static ?array $cache = null;

    /** @var array<string, float>|null */
    protected static ?array $fxCache = null;

    protected function db(): \CodeIgniter\Database\BaseConnection
    {
        return MasterTenantRepository::masterConnection();
    }

    public function ensureTable(): void
    {
        if (self::$ready || ! MasterTenantRepository::masterConfigured()) {
            return;
        }

        try {
            $db = $this->db();
            $db->query(
                'CREATE TABLE IF NOT EXISTS `wa_rates` (
                    `country_code` VARCHAR(8) NOT NULL,
                    `country_name` VARCHAR(100) NOT NULL DEFAULT \'\',
                    `currency` VARCHAR(3) NOT NULL DEFAULT \'INR\',
                    `marketing` DECIMAL(10,4) NOT NULL DEFAULT 0,
                    `utility` DECIMAL(10,4) NOT NULL DEFAULT 0,
                    `authentication` DECIMAL(10,4) NOT NULL DEFAULT 0,
                    `service` DECIMAL(10,4) NOT NULL DEFAULT 0,
                    `updated_at` DATETIME NULL,
                    PRIMARY KEY (`country_code`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
            $db->query(
                'CREATE TABLE IF NOT EXISTS `wa_fx_rates` (
                    `currency` VARCHAR(3) NOT NULL,
                    `rate` DECIMAL(16,6) NOT NULL DEFAULT 0,
                    `updated_at` DATETIME NULL,
                    PRIMARY KEY (`currency`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
            $db->table('wa_fx_rates')->ignore(true)->insert(['currency' => 'INR', 'rate' => 1, 'updated_at' => date('Y-m-d H:i:s')]);

            if ((int) $db->table('wa_rates')->countAllResults() < 5) {
                $this->seedMarkets();
            }
            $this->alignDefaultCurrencies();
            self::$ready = true;
        } catch (Throwable $e) {
            log_message('error', 'WalletRateCard::ensureTable failed: {msg}', ['msg' => $e->getMessage()]);
        }
    }

    /**
     * Meta's standalone markets, so they show up in the list. Only India has a rate (Meta's published INR
     * list rate); every other market stays at 0 = "not set" until the platform admin enters Meta's rate or
     * imports Meta's rate card CSV. A market with all rates at 0 is never treated as free.
     */
    protected function seedMarkets(): void
    {
        $now = date('Y-m-d H:i:s');
        $db  = $this->db();
        foreach (WhatsAppCountries::MARKETS as $code => $name) {
            $india = $code === '91';
            $db->table('wa_rates')->ignore(true)->insert([
                'country_code'   => (string) $code,
                'country_name'   => $name,
                'currency'       => WhatsAppCountries::currencyOf((string) $code),
                'marketing'      => $india ? 0.8631 : 0,
                'utility'        => $india ? 0.1150 : 0,
                'authentication' => $india ? 0.1150 : 0,
                'service'        => $india ? 0.1150 : 0,
                'updated_at'     => $now,
            ]);
        }
    }

    /**
     * Countries that still have no rate get their own billing currency instead of the INR they were
     * seeded with. Rows that already hold a rate are never touched.
     */
    protected function alignDefaultCurrencies(): void
    {
        $db = $this->db();
        $rows = $db->table('wa_rates')->select('country_code')
            ->where('currency', 'INR')->where('country_code !=', '91')
            ->where('marketing', 0)->where('utility', 0)->where('authentication', 0)->where('service', 0)
            ->get()->getResultArray();
        foreach ($rows as $r) {
            $code = (string) $r['country_code'];
            $cur  = $code === 'other' ? 'USD' : WhatsAppCountries::currencyOf($code);
            if ($cur !== 'INR') {
                $db->table('wa_rates')->where('country_code', $code)->update(['currency' => $cur]);
            }
        }
    }

    /**
     * Exchange rates: how many units of the wallet currency (INR) one unit of $currency is worth.
     *
     * @return array<string, float>
     */
    public function fxAll(): array
    {
        if (self::$fxCache !== null) {
            return self::$fxCache;
        }
        $this->ensureTable();
        try {
            $out = [];
            foreach ($this->db()->table('wa_fx_rates')->get()->getResultArray() as $r) {
                $out[(string) $r['currency']] = (float) $r['rate'];
            }
            $out['INR'] = 1.0;

            return self::$fxCache = $out;
        } catch (Throwable $e) {
            log_message('error', 'WalletRateCard::fxAll failed: {msg}', ['msg' => $e->getMessage()]);

            return ['INR' => 1.0];
        }
    }

    /**
     * @param array<string, mixed> $rates currency => rate in INR
     *
     * @return array{saved: int, bad: list<string>}
     */
    public function saveFx(array $rates): array
    {
        $this->ensureTable();
        $saved = 0;
        $bad = [];
        self::$fxCache = null;
        foreach ($rates as $cur => $rate) {
            $cur = strtoupper(trim((string) $cur));
            if ($cur === 'INR' || ! in_array($cur, WhatsAppCountries::CURRENCIES, true)) {
                continue;
            }
            $value = (float) $rate;
            if (trim((string) $rate) === '') {
                continue; // left blank: keep what is saved
            }
            if ($value <= 0 || $value > 1000000) {
                $bad[] = $cur;
                continue;
            }
            try {
                $db = $this->db();
                $exists = $db->table('wa_fx_rates')->where('currency', $cur)->countAllResults() > 0;
                $row = ['rate' => round($value, 6), 'updated_at' => date('Y-m-d H:i:s')];
                $exists ? $db->table('wa_fx_rates')->where('currency', $cur)->update($row) : $db->table('wa_fx_rates')->insert(['currency' => $cur] + $row);
                $saved++;
            } catch (Throwable $e) {
                log_message('error', 'WalletRateCard::saveFx failed: {msg}', ['msg' => $e->getMessage()]);
                $bad[] = $cur;
            }
        }

        return ['saved' => $saved, 'bad' => $bad];
    }

    /** When the exchange rates were last saved, '' if never. */
    public function fxUpdatedAt(): string
    {
        $this->ensureTable();
        try {
            $row = $this->db()->table('wa_fx_rates')->selectMax('updated_at', 'u')->where('currency !=', 'INR')->get()->getRowArray();

            return (string) ($row['u'] ?? '');
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Fill the exchange rates from a public daily rates feed (mid-market rates, not Meta's own conversion).
     *
     * @return array{ok: bool, saved: int, message: string}
     */
    public function refreshFxFromWeb(): array
    {
        try {
            $client = \Config\Services::curlrequest(['timeout' => 15, 'http_errors' => false], null, null, false);
            $res    = $client->get('https://open.er-api.com/v6/latest/INR');
            $data   = json_decode((string) $res->getBody(), true);
        } catch (Throwable $e) {
            log_message('error', 'WalletRateCard::refreshFxFromWeb request failed: {msg}', ['msg' => $e->getMessage()]);

            return ['ok' => false, 'saved' => 0, 'message' => 'Could not reach the exchange rate service. Enter the rates by hand or try again.'];
        }

        if (! is_array($data) || ($data['result'] ?? '') !== 'success' || ! is_array($data['rates'] ?? null)) {
            return ['ok' => false, 'saved' => 0, 'message' => 'The exchange rate service did not return rates. Try again later.'];
        }

        // The feed gives units of each currency per 1 INR; we store the INR value of one unit.
        $rates = [];
        foreach (WhatsAppCountries::CURRENCIES as $cur) {
            $perInr = (float) ($data['rates'][$cur] ?? 0);
            if ($cur !== 'INR' && $perInr > 0) {
                $rates[$cur] = (string) round(1 / $perInr, 6);
            }
        }
        $out = $this->saveFx($rates);

        return ['ok' => $out['saved'] > 0, 'saved' => $out['saved'], 'message' => $out['saved'] . ' exchange rates updated (' . (string) ($data['time_last_update_utc'] ?? 'today') . ').'];
    }

    /**
     * Amount in $from converted to $to, through the saved exchange rates (each is the INR value of one
     * unit). Null when either currency has no rate.
     */
    public function convert(float $amount, string $from, string $to): ?float
    {
        $from = strtoupper($from);
        $to   = strtoupper($to);
        if ($from === $to) {
            return $amount;
        }
        $fx     = $this->fxAll();
        $fromFx = (float) ($fx[$from] ?? 0);
        $toFx   = (float) ($fx[$to] ?? 0);

        return $fromFx > 0 && $toFx > 0 ? $amount * $fromFx / $toFx : null;
    }

    /** True while no rate has been entered for the market. */
    public static function isUnset(array $row): bool
    {
        foreach (self::CATEGORIES as $cat) {
            if ((float) ($row[$cat] ?? 0) > 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Import Meta's rate card CSV (Market, Marketing, Utility, Authentication, Service columns).
     * Rows are matched to a calling code by country name; regional rows ("Rest of Africa") cannot be
     * matched and are skipped. A row named "Other" fills the "other" fallback.
     *
     * @return array{ok: bool, imported: int, skipped: list<string>, message: string}
     */
    public function importCsv(string $path, string $currency = 'INR'): array
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return ['ok' => false, 'imported' => 0, 'skipped' => [], 'message' => 'Could not read the file.'];
        }

        $cols = [];
        $imported = 0;
        $skipped = [];
        while (($line = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
            if ($cols === []) {
                $cols = $this->csvColumns($line);
                continue;
            }
            if ($cols === null || ! isset($cols['market'])) {
                break;
            }

            $market = trim((string) ($line[$cols['market']] ?? ''));
            if ($market === '') {
                continue;
            }
            $code = strtolower($market) === 'other' ? 'other' : WhatsAppCountries::codeByName($market);
            if ($code === null) {
                $skipped[] = $market;
                continue;
            }

            $row = ['country_code' => $code, 'country_name' => WhatsAppCountries::nameOf($code) ?: $market, 'currency' => $currency];
            foreach (self::CATEGORIES as $cat) {
                $row[$cat] = isset($cols[$cat]) ? (float) preg_replace('/[^0-9.]/', '', (string) ($line[$cols[$cat]] ?? '0')) : 0.0;
            }
            if ($this->save($row)) {
                $imported++;
            } else {
                $skipped[] = $market;
            }
        }
        fclose($fh);

        if (! isset($cols['market']) || $imported === 0) {
            return ['ok' => false, 'imported' => 0, 'skipped' => $skipped, 'message' => 'No rates found. Use the rate card CSV downloaded from Meta (it needs Market, Marketing, Utility, Authentication, Service columns).'];
        }

        return ['ok' => true, 'imported' => $imported, 'skipped' => $skipped, 'message' => $imported . ' countries imported.'];
    }

    /**
     * @param array<int, string|null> $header
     *
     * @return array<string, int>
     */
    protected function csvColumns(array $header): array
    {
        $cols = [];
        foreach ($header as $i => $h) {
            $h = strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h) ?? ''));
            if ($h === '') {
                continue;
            }
            if (! isset($cols['market']) && (str_contains($h, 'market') || str_contains($h, 'country'))) {
                $cols['market'] = $i;
            } elseif (str_contains($h, 'international')) {
                continue;
            } elseif (! isset($cols['marketing']) && str_contains($h, 'marketing')) {
                $cols['marketing'] = $i;
            } elseif (! isset($cols['utility']) && str_contains($h, 'utility')) {
                $cols['utility'] = $i;
            } elseif (! isset($cols['authentication']) && str_contains($h, 'authentication')) {
                $cols['authentication'] = $i;
            } elseif (! isset($cols['service']) && str_contains($h, 'service')) {
                $cols['service'] = $i;
            }
        }

        return $cols;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        // Cached for the request/worker run: a campaign must not hit the master DB once per message.
        if (self::$cache !== null) {
            return self::$cache;
        }

        $this->ensureTable();
        try {
            return self::$cache = $this->db()->table('wa_rates')->orderBy('country_name', 'ASC')->get()->getResultArray();
        } catch (Throwable $e) {
            log_message('error', 'WalletRateCard::all failed: {msg}', ['msg' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    public function save(array $row): bool
    {
        $code = $this->normalizeCode((string) ($row['country_code'] ?? ''));
        if ($code === '') {
            return false;
        }

        $data = [
            'country_code' => $code,
            'country_name' => mb_substr(trim((string) ($row['country_name'] ?? '')), 0, 100),
            'currency'     => strtoupper(substr(trim((string) ($row['currency'] ?? '')), 0, 3)),
            'updated_at'   => date('Y-m-d H:i:s'),
        ];
        if (! in_array($data['currency'], WhatsAppCountries::CURRENCIES, true)) {
            $data['currency'] = $code === 'other' ? 'USD' : WhatsAppCountries::currencyOf($code);
        }
        foreach (self::CATEGORIES as $cat) {
            $rate = (float) ($row[$cat] ?? 0);
            if ($rate < 0 || $rate > 99999) {
                return false;
            }
            $data[$cat] = round($rate, 4);
        }

        self::$cache = null;
        $this->ensureTable();
        try {
            $db = $this->db();
            $exists = $db->table('wa_rates')->where('country_code', $code)->countAllResults() > 0;
            if ($exists) {
                unset($data['country_code']);

                return (bool) $db->table('wa_rates')->where('country_code', $code)->update($data);
            }

            return (bool) $db->table('wa_rates')->insert($data);
        } catch (Throwable $e) {
            log_message('error', 'WalletRateCard::save failed: {msg}', ['msg' => $e->getMessage()]);

            return false;
        }
    }

    public function delete(string $code): void
    {
        $code = $this->normalizeCode($code);
        if ($code === '') {
            return;
        }
        self::$cache = null;
        try {
            $this->db()->table('wa_rates')->where('country_code', $code)->delete();
        } catch (Throwable $e) {
            log_message('error', 'WalletRateCard::delete failed: {msg}', ['msg' => $e->getMessage()]);
        }
    }

    /**
     * Rate for one message to $phone, or null when no market matches (and there is no "other" row).
     *
     * @return array{rate: float, country_code: string, currency: string}|null
     */
    public function rateFor(string $phone, string $category): ?array
    {
        if (! in_array($category, self::CATEGORIES, true)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $rows   = [];
        foreach ($this->all() as $r) {
            $rows[(string) $r['country_code']] = $r;
        }

        // Longest calling-code prefix wins (+77 Kazakhstan before +7 Russia). A market with no rate
        // entered yet is skipped, so it falls to "other" or is refused, never sent for free.
        for ($len = 4; $len >= 1; $len--) {
            $prefix = substr($digits, 0, $len);
            if ($prefix !== '' && isset($rows[$prefix]) && ! self::isUnset($rows[$prefix])) {
                return $this->pick($rows[$prefix], $category);
            }
        }

        return isset($rows['other']) && ! self::isUnset($rows['other']) ? $this->pick($rows['other'], $category) : null;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{rate: float, country_code: string, currency: string}
     */
    protected function pick(array $row, string $category): array
    {
        return [
            'rate'         => (float) ($row[$category] ?? 0),
            'country_code' => (string) $row['country_code'],
            'currency'     => (string) ($row['currency'] ?? 'INR'),
        ];
    }

    protected function normalizeCode(string $code): string
    {
        $code = strtolower(trim($code));
        if ($code === 'other') {
            return $code;
        }

        return preg_match('/^\d{1,4}$/', $code) === 1 ? $code : '';
    }
}
