<?php

declare(strict_types=1);

namespace App\Libraries;

use Throwable;

/**
 * The one place that knows about currencies. Nothing else in the app names a currency, a symbol or the base currency.
 *
 *  - The catalogue (code, symbol, name, exchange rate) lives in the master database (`wa_fx_rates`) and is edited by the
 *    platform admin on the WhatsApp rates page. The built-in SEED below only fills an empty catalogue the first time.
 *  - The base currency (the one every exchange rate is measured against, rate 1) is a platform setting
 *    (`wallet_base_currency`). The seed value is used only when nothing is saved yet, or when no database is reachable.
 *  - A country's default currency is stored per country (`wa_rates.default_currency`), seeded from SEED_COUNTRY.
 */
class Currency
{
    /** Used only to fill an empty master database, and as the answer when no database can be reached. */
    public const INSTALL_BASE = 'INR';

    /** @var array<string, array{0: string, 1: string}> code => [symbol, name]. First-run catalogue; editable afterwards. */
    public const SEED = [
        'AED' => ['AED', 'UAE dirham'], 'ARS' => ['ARS', 'Argentine peso'], 'AUD' => ['A$', 'Australian dollar'],
        'BRL' => ['R$', 'Brazilian real'], 'CLP' => ['CLP', 'Chilean peso'], 'COP' => ['COP', 'Colombian peso'],
        'EUR' => ['€', 'Euro'], 'GBP' => ['£', 'Pound sterling'], 'IDR' => ['Rp', 'Indonesian rupiah'],
        'INR' => ['₹', 'Indian rupee'], 'MXN' => ['MX$', 'Mexican peso'], 'MYR' => ['RM', 'Malaysian ringgit'],
        'PEN' => ['S/', 'Peruvian sol'], 'SAR' => ['SAR', 'Saudi riyal'], 'SGD' => ['S$', 'Singapore dollar'],
        'USD' => ['$', 'US dollar'],
    ];

    /** @var array<string, string> calling code => currency, first-run defaults per country (stored in the master afterwards). */
    public const SEED_COUNTRY = [
        '54' => 'ARS', '55' => 'BRL', '56' => 'CLP', '57' => 'COP', '91' => 'INR', '62' => 'IDR', '60' => 'MYR',
        '52' => 'MXN', '51' => 'PEN', '966' => 'SAR', '65' => 'SGD', '971' => 'AED', '44' => 'GBP', '61' => 'AUD',
        '33' => 'EUR', '49' => 'EUR', '39' => 'EUR', '31' => 'EUR', '34' => 'EUR', '43' => 'EUR', '32' => 'EUR',
        '357' => 'EUR', '372' => 'EUR', '358' => 'EUR', '30' => 'EUR', '353' => 'EUR', '371' => 'EUR', '370' => 'EUR',
        '352' => 'EUR', '356' => 'EUR', '351' => 'EUR', '421' => 'EUR', '386' => 'EUR', '385' => 'EUR',
    ];

    /** Currency of a country that has no entry anywhere, as Meta bills the rest of the world. */
    private const FALLBACK = 'USD';

    /** @var array<string, array{symbol: string, name: string, rate: float}>|null */
    private static ?array $catalogue = null;
    private static ?string $base = null;

    public static function flush(): void
    {
        self::$catalogue = null;
        self::$base      = null;
    }

    /**
     * The base currency: every exchange rate says how many of it one unit of a currency is worth.
     */
    public static function base(): string
    {
        if (self::$base !== null) {
            return self::$base;
        }
        if (! MasterTenantRepository::masterConfigured()) {
            return self::$base = self::INSTALL_BASE;
        }

        try {
            (new WalletRateCard())->ensureTable();
            $repo  = new MasterTenantRepository();
            $saved = strtoupper(trim($repo->getPlatformSetting('wallet_base_currency', '')));
            if ($saved === '') {
                // Older installs: the currency with rate 1 is the base. Remember it.
                $row   = $repo::masterConnection()->table('wa_fx_rates')->where('rate', 1)->orderBy('currency')->get()->getRowArray();
                $saved = strtoupper((string) ($row['currency'] ?? '')) ?: self::INSTALL_BASE;
                $repo->setPlatformSetting('wallet_base_currency', $saved);
            }

            return self::$base = $saved;
        } catch (Throwable $e) {
            log_message('error', 'Currency::base failed: {msg}', ['msg' => $e->getMessage()]);

            return self::INSTALL_BASE;
        }
    }

    /**
     * @return array<string, array{symbol: string, name: string, rate: float}> code => details, sorted by code
     */
    public static function catalogue(): array
    {
        if (self::$catalogue !== null) {
            return self::$catalogue;
        }

        $out = [];
        if (MasterTenantRepository::masterConfigured()) {
            try {
                (new WalletRateCard())->ensureTable();
                foreach (MasterTenantRepository::masterConnection()->table('wa_fx_rates')->orderBy('currency')->get()->getResultArray() as $r) {
                    $code       = strtoupper((string) $r['currency']);
                    $out[$code] = ['symbol' => trim((string) ($r['symbol'] ?? '')) ?: $code, 'name' => (string) ($r['name'] ?? ''), 'rate' => (float) $r['rate']];
                }
            } catch (Throwable $e) {
                log_message('error', 'Currency::catalogue failed: {msg}', ['msg' => $e->getMessage()]);
            }
        }
        if ($out === []) {
            foreach (self::SEED as $code => [$symbol, $name]) {
                $out[$code] = ['symbol' => $symbol, 'name' => $name, 'rate' => $code === self::INSTALL_BASE ? 1.0 : 0.0];
            }

            // Not cached: a database that is only briefly unreachable must not freeze the seed list.
            return $out;
        }

        return self::$catalogue = $out;
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::catalogue());
    }

    public static function isKnown(string $code): bool
    {
        return isset(self::catalogue()[strtoupper(trim($code))]);
    }

    /**
     * What to print before an amount. A symbol made of letters (AED, RM) gets a space after it ("AED 10.00"),
     * a sign (₹ $ €) does not. A currency without a saved symbol prints its code.
     */
    public static function symbol(string $code): string
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return '';
        }
        $symbol = self::catalogue()[$code]['symbol'] ?? $code;

        return ctype_alpha($symbol) ? $symbol . ' ' : $symbol;
    }

    /** The first-run default for a country (used only to fill an empty master database). */
    public static function seedForCountry(string $countryCode): string
    {
        return self::SEED_COUNTRY[strtolower(trim($countryCode))] ?? self::FALLBACK;
    }

    /**
     * The currency a country is billed in when it has not been configured otherwise (stored per country in the master).
     */
    public static function defaultForCountry(string $countryCode): string
    {
        $countryCode = strtolower(trim($countryCode));
        if ($countryCode === '') {
            return self::base();
        }

        if (MasterTenantRepository::masterConfigured()) {
            try {
                $card = new WalletRateCard();
                foreach ($card->all() as $r) {
                    if ((string) $r['country_code'] === $countryCode && ! empty($r['default_currency'])) {
                        return strtoupper((string) $r['default_currency']);
                    }
                }
            } catch (Throwable) {
                // fall through to the built-in defaults
            }
        }

        return self::SEED_COUNTRY[$countryCode] ?? self::FALLBACK;
    }
}
