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

    /** True while ensureTable() is running, so a lookup it triggers (the base currency) cannot start it again. */
    protected static bool $settingUp = false;

    public function ensureTable(): void
    {
        if (self::$ready || self::$settingUp || ! MasterTenantRepository::masterConfigured()) {
            return;
        }
        self::$settingUp = true;

        try {
            $db = $this->db();
            $db->query(
                'CREATE TABLE IF NOT EXISTS `wa_rates` (
                    `country_code` VARCHAR(8) NOT NULL,
                    `country_name` VARCHAR(100) NOT NULL DEFAULT \'\',
                    `currency` VARCHAR(3) NOT NULL DEFAULT \'\',
                    `default_currency` VARCHAR(3) NULL,
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
            if (! $db->fieldExists('symbol', 'wa_fx_rates')) {
                $db->query('ALTER TABLE `wa_fx_rates` ADD `symbol` VARCHAR(8) NULL, ADD `name` VARCHAR(60) NULL');
            }
            if (! $db->fieldExists('default_currency', 'wa_rates')) {
                $db->query('ALTER TABLE `wa_rates` ADD `default_currency` VARCHAR(3) NULL');
            }
            $this->seedCurrencies();

            if ((int) $db->table('wa_rates')->countAllResults() < 5) {
                $this->seedMarkets();
            }
            $this->fillDefaultCurrencies();
            $this->alignDefaultCurrencies();
            self::$ready = true;
        } catch (Throwable $e) {
            log_message('error', 'WalletRateCard::ensureTable failed: {msg}', ['msg' => $e->getMessage()]);
        } finally {
            self::$settingUp = false;
        }
    }

    /**
     * First run (and any currency added to the seed later): put the catalogue in the master. Existing rows keep their
     * exchange rate, symbol and name; only blanks are filled. The base currency gets rate 1.
     */
    protected function seedCurrencies(): void
    {
        $db  = $this->db();
        $now = date('Y-m-d H:i:s');
        foreach (Currency::SEED as $code => [$symbol, $name]) {
            $db->table('wa_fx_rates')->ignore(true)->insert([
                'currency'   => $code,
                'rate'       => $code === Currency::INSTALL_BASE ? 1 : 0,
                'symbol'     => $symbol,
                'name'       => $name,
                'updated_at' => $now,
            ]);
            $db->table('wa_fx_rates')->where('currency', $code)->groupStart()->where('symbol', null)->orWhere('symbol', '')->groupEnd()->update(['symbol' => $symbol]);
            $db->table('wa_fx_rates')->where('currency', $code)->groupStart()->where('name', null)->orWhere('name', '')->groupEnd()->update(['name' => $name]);
        }
    }

    /** Rows saved before `default_currency` existed get the first-run default for their country. */
    protected function fillDefaultCurrencies(): void
    {
        $db = $this->db();
        foreach ($db->table('wa_rates')->select('country_code')->where('default_currency', null)->get()->getResultArray() as $r) {
            $code = (string) $r['country_code'];
            $db->table('wa_rates')->where('country_code', $code)->update(['default_currency' => Currency::seedForCountry($code)]);
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
                'currency'         => Currency::seedForCountry((string) $code),
                'default_currency' => Currency::seedForCountry((string) $code),
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
        $db   = $this->db();
        $base = Currency::INSTALL_BASE;
        try {
            $base = Currency::base();
        } catch (Throwable) {
            // keep the install default
        }
        $rows = $db->table('wa_rates')->select('country_code, default_currency')
            ->where('currency', $base)->where('default_currency IS NOT NULL', null, false)->where('default_currency !=', $base)
            ->where('marketing', 0)->where('utility', 0)->where('authentication', 0)->where('service', 0)
            ->get()->getResultArray();
        foreach ($rows as $r) {
            $db->table('wa_rates')->where('country_code', (string) $r['country_code'])->update(['currency' => (string) $r['default_currency']]);
        }
    }

    /**
     * Exchange rates: how many units of the base currency one unit of $currency is worth.
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
            $out[Currency::base()] = 1.0;

            return self::$fxCache = $out;
        } catch (Throwable $e) {
            log_message('error', 'WalletRateCard::fxAll failed: {msg}', ['msg' => $e->getMessage()]);

            return [Currency::base() => 1.0];
        }
    }

    /**
     * @param array<string, mixed> $rates currency => rate in the base currency
     *
     * @return array{saved: int, bad: list<string>}
     */
    public function saveFx(array $rates): array
    {
        $this->ensureTable();
        $saved = 0;
        $bad = [];
        self::$fxCache = null;
        Currency::flush();
        $base = Currency::base();
        foreach ($rates as $cur => $rate) {
            $cur = strtoupper(trim((string) $cur));
            if ($cur === $base || ! Currency::isKnown($cur)) {
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

    /**
     * Add a currency or change its symbol and name. A new currency starts with no exchange rate (0 = not set), so
     * nothing can be converted with it until the admin enters one.
     *
     * @return array{ok: bool, message: string}
     */
    public function saveCurrency(string $code, string $symbol, string $name): array
    {
        $code   = strtoupper(trim($code));
        $symbol = trim($symbol);
        $name   = trim($name);
        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            return ['ok' => false, 'message' => 'A currency code is three letters, like USD.'];
        }
        if ($symbol === '' || mb_strlen($symbol) > 8) {
            return ['ok' => false, 'message' => 'Enter the symbol (up to 8 characters), for example $ or AED.'];
        }
        if (mb_strlen($name) > 60) {
            return ['ok' => false, 'message' => 'The name is too long.'];
        }

        $this->ensureTable();
        try {
            $db = $this->db();
            if ($db->table('wa_fx_rates')->where('currency', $code)->countAllResults() > 0) {
                $db->table('wa_fx_rates')->where('currency', $code)->update(['symbol' => $symbol, 'name' => $name]);
            } else {
                $db->table('wa_fx_rates')->insert(['currency' => $code, 'rate' => 0, 'symbol' => $symbol, 'name' => $name, 'updated_at' => date('Y-m-d H:i:s')]);
            }
            self::$fxCache = null;
            Currency::flush();

            return ['ok' => true, 'message' => $code . ' saved.'];
        } catch (Throwable $e) {
            log_message('error', 'WalletRateCard::saveCurrency failed: {msg}', ['msg' => $e->getMessage()]);

            return ['ok' => false, 'message' => 'Could not save the currency.'];
        }
    }

    /** When the exchange rates were last saved, '' if never. */
    public function fxUpdatedAt(): string
    {
        $this->ensureTable();
        try {
            $row = $this->db()->table('wa_fx_rates')->selectMax('updated_at', 'u')->where('currency !=', Currency::base())->get()->getRowArray();

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
            $base   = Currency::base();
            $res    = $client->get('https://open.er-api.com/v6/latest/' . rawurlencode($base));
            $data   = json_decode((string) $res->getBody(), true);
        } catch (Throwable $e) {
            log_message('error', 'WalletRateCard::refreshFxFromWeb request failed: {msg}', ['msg' => $e->getMessage()]);

            return ['ok' => false, 'saved' => 0, 'message' => 'Could not reach the exchange rate service. Enter the rates by hand or try again.'];
        }

        if (! is_array($data) || ($data['result'] ?? '') !== 'success' || ! is_array($data['rates'] ?? null)) {
            return ['ok' => false, 'saved' => 0, 'message' => 'The exchange rate service did not return rates. Try again later.'];
        }

        // The feed gives units of each currency per 1 base unit; we store the base value of one unit.
        $rates = [];
        foreach (Currency::codes() as $cur) {
            $perBase = (float) ($data['rates'][$cur] ?? 0);
            if ($cur !== $base && $perBase > 0) {
                $rates[$cur] = (string) round(1 / $perBase, 6);
            }
        }
        $out = $this->saveFx($rates);

        return ['ok' => $out['saved'] > 0, 'saved' => $out['saved'], 'message' => $out['saved'] . ' exchange rates updated (' . (string) ($data['time_last_update_utc'] ?? 'today') . ').'];
    }

    /**
     * Amount in $from converted to $to, through the saved exchange rates (each is the base-currency value of one
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
     * Import a rate card file. Meta publishes it as a spreadsheet (xlsx) even though the link says "CSV", so
     * both are accepted.
     *
     * @return array{ok: bool, imported: int, skipped: list<string>, message: string}
     */
    public function importFile(string $path, string $currency): array
    {
        $head = (string) @file_get_contents($path, false, null, 0, 2);
        if ($head !== 'PK') {
            return $this->importCsv($path, $currency);
        }
        if (! class_exists(\ZipArchive::class) || ! function_exists('simplexml_load_string')) {
            return ['ok' => false, 'imported' => 0, 'skipped' => [], 'message' => 'This server cannot read spreadsheets (PHP zip/xml missing). Save the rate card as CSV and upload that.'];
        }
        $csv = $this->xlsxToCsv((string) file_get_contents($path));
        if ($csv === null || $csv === '') {
            return ['ok' => false, 'imported' => 0, 'skipped' => [], 'message' => 'The spreadsheet could not be read. Save it as CSV and upload that.'];
        }
        $tmp = tempnam(sys_get_temp_dir(), 'wacard');
        if ($tmp === false) {
            return ['ok' => false, 'imported' => 0, 'skipped' => [], 'message' => 'Could not create a temporary file.'];
        }
        try {
            file_put_contents($tmp, $csv);
            $res = $this->importCsv($tmp, $currency);
        } finally {
            @unlink($tmp);
        }
        if ($res['ok'] && preg_match('/effective ([A-Za-z]+ \d{1,2}, \d{4})/', $csv, $m) === 1) {
            $res['message'] .= ' Meta rates effective ' . $m[1] . '.';
        }

        return $res;
    }

    /**
     * Import from the link behind "<CUR> list rates" on Meta's pricing page (copy the link address there and
     * paste it). The link is a signed Meta CDN URL, so only https Meta/Facebook hosts are fetched.
     *
     * @return array{ok: bool, imported: int, skipped: list<string>, message: string}
     */
    public function importFromUrl(string $url, string $currency): array
    {
        $url  = trim($url);
        $fail = static fn (string $m): array => ['ok' => false, 'imported' => 0, 'skipped' => [], 'message' => $m];
        // The pricing page wraps file links in l.facebook.com/l.php?u=<real link>.
        if (str_contains($url, 'l.facebook.com/l.php')) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            $url = (string) ($q['u'] ?? '');
        }
        if (! $this->isMetaHost($url)) {
            return $fail('Paste the link address of "' . $currency . ' list rates" from the Meta pricing page (it must be a Meta download link).');
        }
        $file = $this->httpGet($url, 3_000_000);
        if ($file === null || $file === '') {
            return $fail('Could not download the file. The link may have expired: copy it again from the Meta pricing page.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'wacard');
        if ($tmp === false) {
            return $fail('Could not create a temporary file.');
        }
        try {
            file_put_contents($tmp, $file);

            return $this->importFile($tmp, $currency);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * GET with a size cap. Redirects are followed by hand (max 4) and every hop must stay on https and on a
     * Meta/Facebook host, so a redirect cannot be used to reach anything else. Null on any failure.
     */
    protected function httpGet(string $url, int $maxBytes): ?string
    {
        try {
            for ($hop = 0; $hop <= 4; $hop++) {
                if (! $this->isMetaHost($url)) {
                    return null;
                }
                $headers = [];
                $ch      = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_CONNECTTIMEOUT => 8,
                    CURLOPT_TIMEOUT        => 30,
                    CURLOPT_MAXFILESIZE    => $maxBytes,
                    CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
                    CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; RateCardFetcher)',
                    CURLOPT_ENCODING       => '',
                    CURLOPT_HEADERFUNCTION => static function ($c, string $h) use (&$headers): int {
                        if (stripos($h, 'location:') === 0) {
                            $headers['location'] = trim(substr($h, 9));
                        }

                        return strlen($h);
                    },
                ]);
                $body   = curl_exec($ch);
                $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($body === false) {
                    log_message('error', 'WalletRateCard::httpGet transport error: {e}', ['e' => curl_error($ch)]);
                }
                curl_close($ch);

                if (in_array($status, [301, 302, 303, 307, 308], true) && ! empty($headers['location'])) {
                    $url = $this->absoluteUrl($url, (string) $headers['location']);
                    continue;
                }

                return is_string($body) && $status === 200 && strlen($body) <= $maxBytes ? $body : null;
            }
        } catch (Throwable $e) {
            log_message('error', 'WalletRateCard::httpGet failed: {msg}', ['msg' => $e->getMessage()]);
        }

        return null;
    }

    protected function isMetaHost(string $url): bool
    {
        $p = parse_url($url);

        return ($p['scheme'] ?? '') === 'https'
            && preg_match('/(^|\.)(fbcdn\.net|facebook\.com|fbsbx\.com)$/', strtolower((string) ($p['host'] ?? ''))) === 1;
    }

    protected function absoluteUrl(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }
        $p = parse_url($base);

        return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (str_starts_with($location, '/') ? '' : '/') . $location;
    }

    /** First worksheet of an xlsx as CSV text (every row, including the notes above the header). */
    protected function xlsxToCsv(string $xlsx): ?string
    {
        if ($xlsx === '') {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'waxlsx');
        if ($tmp === false) {
            return null;
        }
        $zip = new \ZipArchive();
        try {
            file_put_contents($tmp, $xlsx);
            if ($zip->open($tmp) !== true) {
                return null;
            }
            $read = static function (string $name) use ($zip): string {
                $s = $zip->getFromName($name, 20_000_000);

                return is_string($s) ? $s : '';
            };
            $sheet = $read('xl/worksheets/sheet1.xml');
            if ($sheet === '') {
                return null;
            }
            $strings = [];
            $ssXml   = $read('xl/sharedStrings.xml');
            if ($ssXml !== '' && ($ss = @simplexml_load_string($ssXml)) !== false) {
                foreach ($ss->si as $si) {
                    $strings[] = (string) dom_import_simplexml($si)->textContent;
                }
            }
            $xml = @simplexml_load_string($sheet);
            if ($xml === false) {
                return null;
            }

            $out = fopen('php://temp', 'r+b');
            foreach ($xml->sheetData->row ?? [] as $row) {
                $line = [];
                foreach ($row->c as $c) {
                    $col = preg_replace('/\d+/', '', (string) $c['r']) ?? '';
                    $n   = 0;
                    foreach (str_split($col) as $ch) {
                        $n = $n * 26 + (ord($ch) - 64);
                    }
                    $val = (string) $c->v;
                    if ((string) $c['t'] === 's' && $val !== '') {
                        $val = $strings[(int) $val] ?? '';
                    }
                    $line[max(0, $n - 1)] = str_replace(["\r", "\n"], ' ', $val);
                }
                if ($line === []) {
                    continue;
                }
                $max = max(array_keys($line));
                $fill = [];
                for ($i = 0; $i <= $max; $i++) {
                    $fill[] = $line[$i] ?? '';
                }
                fputcsv($out, $fill, ',', '"', '\\');
            }
            rewind($out);
            $csv = (string) stream_get_contents($out);
            fclose($out);

            return $csv;
        } catch (Throwable $e) {
            log_message('error', 'WalletRateCard::xlsxToCsv failed: {msg}', ['msg' => $e->getMessage()]);

            return null;
        } finally {
            $zip->close();
            @unlink($tmp);
        }
    }

    /**
     * Import Meta's rate card CSV (Market, Marketing, Utility, Authentication, Service columns).
     * Rows are matched to a calling code by country name; regional rows ("Rest of Africa") cannot be
     * matched and are skipped. A row named "Other" fills the "other" fallback.
     *
     * @return array{ok: bool, imported: int, skipped: list<string>, message: string}
     */
    public function importCsv(string $path, ?string $currency = null): array
    {
        $currency = $currency !== null && $currency !== '' ? $currency : Currency::base();
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
                // Notes above the header ("Marketing rates apply across...") mention "market" too: a real header
                // row names the market column and at least one rate column.
                if (! isset($cols['market']) || count($cols) < 2) {
                    $cols = [];
                }
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
     * The currency a client in this country is billed in (the client's wallet currency): the currency chosen on the
     * country's own row in WhatsApp rates, as long as that row has rates. A country with no rates yet falls back to its
     * default currency, so an unfinished row can never switch anyone's wallet.
     */
    public function currencyForCountry(string $code): string
    {
        $code = strtolower(trim($code));
        foreach ($this->all() as $r) {
            if ((string) $r['country_code'] === $code && ! self::isUnset($r)) {
                $cur = strtoupper((string) ($r['currency'] ?? ''));
                if (Currency::isKnown($cur)) {
                    return $cur;
                }
            }
        }

        return Currency::defaultForCountry($code);
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
        if (! Currency::isKnown($data['currency'])) {
            $data['currency'] = Currency::defaultForCountry($code);
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

            return (bool) $db->table('wa_rates')->insert($data + ['default_currency' => Currency::seedForCountry($code)]);
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
            'currency'     => (string) ($row['currency'] ?? Currency::base()),
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
