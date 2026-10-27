<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Countries for the WhatsApp rate card, keyed by calling code.
 *
 * MARKETS are the standalone markets on Meta's rate card (Meta lists 39 countries plus North America).
 * Every other country is billed through one of Meta's regional or "Other" rates, so it is listed in
 * OTHERS and the platform admin prices it with its region's rate.
 *
 * Calling codes are matched longest-first, so Kazakhstan (+76/+77) wins over Russia (+7).
 */
class WhatsAppCountries
{
    /** @var array<string, string> calling code => name */
    public const MARKETS = [
        '54' => 'Argentina', '880' => 'Bangladesh', '55' => 'Brazil', '56' => 'Chile', '57' => 'Colombia',
        '20' => 'Egypt', '33' => 'France', '49' => 'Germany', '852' => 'Hong Kong', '36' => 'Hungary',
        '91' => 'India', '62' => 'Indonesia', '972' => 'Israel', '964' => 'Iraq', '39' => 'Italy',
        '76' => 'Kazakhstan (+76)', '77' => 'Kazakhstan (+77)', '965' => 'Kuwait', '60' => 'Malaysia',
        '52' => 'Mexico', '212' => 'Morocco', '977' => 'Nepal', '31' => 'Netherlands', '234' => 'Nigeria',
        '968' => 'Oman', '92' => 'Pakistan', '51' => 'Peru', '48' => 'Poland', '974' => 'Qatar',
        '40' => 'Romania', '7' => 'Russia', '966' => 'Saudi Arabia', '65' => 'Singapore', '94' => 'Sri Lanka',
        '27' => 'South Africa', '34' => 'Spain', '90' => 'Turkey', '380' => 'Ukraine',
        '971' => 'United Arab Emirates', '44' => 'United Kingdom', '1' => 'North America (US, Canada)',
    ];

    /** @var array<string, string> calling code => name (billed at a regional or "Other" rate) */
    public const OTHERS = [
        '93' => 'Afghanistan', '355' => 'Albania', '213' => 'Algeria', '376' => 'Andorra', '244' => 'Angola',
        '374' => 'Armenia', '61' => 'Australia', '43' => 'Austria', '994' => 'Azerbaijan', '973' => 'Bahrain',
        '375' => 'Belarus', '32' => 'Belgium', '501' => 'Belize', '229' => 'Benin', '975' => 'Bhutan',
        '591' => 'Bolivia', '387' => 'Bosnia and Herzegovina', '267' => 'Botswana', '673' => 'Brunei',
        '359' => 'Bulgaria', '226' => 'Burkina Faso', '257' => 'Burundi', '855' => 'Cambodia', '237' => 'Cameroon',
        '236' => 'Central African Republic', '235' => 'Chad', '86' => 'China', '243' => 'DR Congo',
        '242' => 'Congo', '506' => 'Costa Rica', '385' => 'Croatia', '53' => 'Cuba', '357' => 'Cyprus',
        '420' => 'Czechia', '45' => 'Denmark', '253' => 'Djibouti', '593' => 'Ecuador', '503' => 'El Salvador',
        '240' => 'Equatorial Guinea', '291' => 'Eritrea', '372' => 'Estonia', '251' => 'Ethiopia', '679' => 'Fiji',
        '358' => 'Finland', '241' => 'Gabon', '220' => 'Gambia', '995' => 'Georgia', '233' => 'Ghana',
        '30' => 'Greece', '502' => 'Guatemala', '224' => 'Guinea', '592' => 'Guyana', '509' => 'Haiti',
        '504' => 'Honduras', '354' => 'Iceland', '98' => 'Iran', '353' => 'Ireland', '81' => 'Japan',
        '962' => 'Jordan', '254' => 'Kenya', '996' => 'Kyrgyzstan', '856' => 'Laos', '371' => 'Latvia',
        '961' => 'Lebanon', '266' => 'Lesotho', '231' => 'Liberia', '218' => 'Libya', '370' => 'Lithuania',
        '352' => 'Luxembourg', '261' => 'Madagascar', '265' => 'Malawi', '960' => 'Maldives', '223' => 'Mali',
        '356' => 'Malta', '222' => 'Mauritania', '230' => 'Mauritius', '373' => 'Moldova', '976' => 'Mongolia',
        '382' => 'Montenegro', '258' => 'Mozambique', '95' => 'Myanmar', '264' => 'Namibia', '64' => 'New Zealand',
        '505' => 'Nicaragua', '227' => 'Niger', '389' => 'North Macedonia', '47' => 'Norway', '507' => 'Panama',
        '675' => 'Papua New Guinea', '595' => 'Paraguay', '63' => 'Philippines', '351' => 'Portugal',
        '250' => 'Rwanda', '221' => 'Senegal', '381' => 'Serbia', '232' => 'Sierra Leone', '421' => 'Slovakia',
        '386' => 'Slovenia', '252' => 'Somalia', '82' => 'South Korea', '211' => 'South Sudan', '249' => 'Sudan',
        '597' => 'Suriname', '268' => 'Eswatini', '46' => 'Sweden', '41' => 'Switzerland', '963' => 'Syria',
        '886' => 'Taiwan', '992' => 'Tajikistan', '255' => 'Tanzania', '66' => 'Thailand', '228' => 'Togo',
        '216' => 'Tunisia', '993' => 'Turkmenistan', '256' => 'Uganda', '598' => 'Uruguay', '998' => 'Uzbekistan',
        '58' => 'Venezuela', '84' => 'Vietnam', '967' => 'Yemen', '260' => 'Zambia', '263' => 'Zimbabwe',
    ];

    /**
     * Length of the national (significant) number, without the calling code and without a leading trunk 0:
     * calling code => [min, max]. Countries not listed use 6 to 14 digits (the E.164 limit is 15 with the code).
     * These are length rules, not proof that a number exists.
     *
     * @var array<string, array{0: int, 1: int}>
     */
    protected const PHONE_LENGTH = [
        '91' => [10, 10], '1' => [10, 10], '44' => [10, 10], '971' => [8, 9], '966' => [8, 9], '92' => [10, 10],
        '880' => [10, 10], '94' => [9, 9], '977' => [10, 10], '65' => [8, 8], '60' => [9, 10], '62' => [9, 12],
        '63' => [10, 10], '61' => [9, 9], '49' => [10, 11], '33' => [9, 9], '39' => [9, 11], '34' => [9, 9],
        '31' => [9, 9], '55' => [10, 11], '52' => [10, 10], '7' => [10, 10], '76' => [9, 9], '77' => [9, 9],
        '90' => [10, 10], '20' => [10, 10], '234' => [10, 10], '254' => [9, 9], '27' => [9, 9], '972' => [9, 9],
        '57' => [10, 10], '54' => [10, 11], '56' => [9, 9], '51' => [9, 9], '84' => [9, 10], '66' => [9, 9],
        '86' => [11, 11], '81' => [10, 10], '82' => [9, 10], '852' => [8, 8], '974' => [8, 8], '965' => [8, 8],
        '968' => [8, 8], '973' => [8, 8], '964' => [10, 10], '212' => [9, 9], '48' => [9, 9], '40' => [9, 9],
        '380' => [9, 9], '36' => [8, 9],
    ];

    /** First digit a national mobile number may start with (regex), where the country's plan is that strict. */
    protected const PHONE_FIRST = ['91' => '[6-9]', '1' => '[2-9]'];

    /**
     * @return array{min: int, max: int, first: string}
     */
    public static function phoneRule(string $code): array
    {
        $code = trim($code);
        [$min, $max] = self::PHONE_LENGTH[$code] ?? [6, 14];

        return ['min' => $min, 'max' => $max, 'first' => self::PHONE_FIRST[$code] ?? ''];
    }

    /**
     * Rules for every country, for the browser to validate with before the form is sent.
     *
     * @return array<string, array{min: int, max: int, first: string}>
     */
    public static function phoneRules(): array
    {
        $out = [];
        foreach (array_keys(self::all()) as $code) {
            $out[(string) $code] = self::phoneRule((string) $code);
        }

        return $out;
    }

    /**
     * Check a mobile number against the chosen country. Accepts "98765 43210", "+91 98765-43210", "0098765…"
     * and a leading trunk 0; returns the cleaned national number and the full number (calling code + national).
     *
     * @return array{ok: bool, national: string, full: string, message: string}
     */
    public static function validatePhone(string $code, string $input): array
    {
        $code = trim($code);
        $bad  = static fn (string $m): array => ['ok' => false, 'national' => '', 'full' => '', 'message' => $m];
        $name = self::nameOf($code);
        if ($name === '' || $code === 'other') {
            return $bad('Choose the country first.');
        }

        $rule   = self::phoneRule($code);
        $digits = preg_replace('/\D+/', '', $input) ?? '';
        if ($digits === '') {
            return $bad('Enter the mobile number.');
        }
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        // "+91 98765 43210" typed in full: drop the calling code when what is left still fits.
        if (strlen($digits) > $rule['max'] && str_starts_with($digits, $code)) {
            $digits = substr($digits, strlen($code));
        }
        $digits = ltrim($digits, '0'); // trunk prefix, for example 0 in front of a UK number

        $len  = $rule['min'] === $rule['max'] ? $rule['min'] . ' digits' : $rule['min'] . ' to ' . $rule['max'] . ' digits';
        $hint = $rule['first'] !== '' ? ', starting with ' . str_replace(['[', ']'], '', $rule['first']) : '';
        $msg  = 'Enter a valid mobile number for ' . $name . ' (' . $len . $hint . ', without the +' . $code . ').';

        if (strlen($digits) < $rule['min'] || strlen($digits) > $rule['max']) {
            return $bad($msg);
        }
        if ($rule['first'] !== '' && preg_match('/^' . $rule['first'] . '/', $digits) !== 1) {
            return $bad($msg);
        }

        return ['ok' => true, 'national' => $digits, 'full' => $code . $digits, 'message' => ''];
    }

    /**
     * Split a stored full number (calling code + national, digits only) back into its two parts, so it can be
     * edited. The longest calling code that matches wins; `$prefer` (the client's country) wins a tie.
     *
     * @return array{code: string, national: string}
     */
    public static function splitPhone(string $full, string $prefer = ''): array
    {
        $digits = preg_replace('/\D+/', '', $full) ?? '';
        if ($digits === '') {
            return ['code' => $prefer, 'national' => ''];
        }
        if ($prefer !== '' && str_starts_with($digits, $prefer) && self::nameOf($prefer) !== '') {
            $rest = substr($digits, strlen($prefer));
            $r = self::phoneRule($prefer);
            if (strlen($rest) >= $r['min'] && strlen($rest) <= $r['max']) {
                return ['code' => $prefer, 'national' => $rest];
            }
        }
        $best = '';
        foreach (array_keys(self::all()) as $code) {
            $code = (string) $code;
            if (str_starts_with($digits, $code) && strlen($code) > strlen($best)) {
                $r = self::phoneRule($code);
                $len = strlen($digits) - strlen($code);
                if ($len >= $r['min'] && $len <= $r['max']) {
                    $best = $code;
                }
            }
        }

        return $best !== '' ? ['code' => $best, 'national' => substr($digits, strlen($best))] : ['code' => $prefer, 'national' => $digits];
    }

    /** @var array<string, string> calling code => default IANA timezone (countries with several use the main one) */
    protected const TIMEZONE_BY_CODE = [
        '91' => 'Asia/Kolkata', '971' => 'Asia/Dubai', '44' => 'Europe/London', '966' => 'Asia/Riyadh', '65' => 'Asia/Singapore',
        '62' => 'Asia/Jakarta', '60' => 'Asia/Kuala_Lumpur', '92' => 'Asia/Karachi', '880' => 'Asia/Dhaka', '94' => 'Asia/Colombo',
        '977' => 'Asia/Kathmandu', '852' => 'Asia/Hong_Kong', '49' => 'Europe/Berlin', '33' => 'Europe/Paris', '39' => 'Europe/Rome',
        '34' => 'Europe/Madrid', '31' => 'Europe/Amsterdam', '48' => 'Europe/Warsaw', '36' => 'Europe/Budapest', '40' => 'Europe/Bucharest',
        '90' => 'Europe/Istanbul', '380' => 'Europe/Kyiv', '7' => 'Europe/Moscow', '20' => 'Africa/Cairo', '234' => 'Africa/Lagos',
        '27' => 'Africa/Johannesburg', '212' => 'Africa/Casablanca', '55' => 'America/Sao_Paulo', '54' => 'America/Argentina/Buenos_Aires',
        '56' => 'America/Santiago', '57' => 'America/Bogota', '51' => 'America/Lima', '52' => 'America/Mexico_City', '1' => 'America/New_York',
        '965' => 'Asia/Kuwait', '974' => 'Asia/Qatar', '968' => 'Asia/Muscat', '964' => 'Asia/Baghdad', '972' => 'Asia/Jerusalem',
        '76' => 'Asia/Almaty', '77' => 'Asia/Almaty', '61' => 'Australia/Sydney',
    ];

    /** Default timezone for a new client in this country, UTC when unknown. */
    public static function timezoneOf(string $code): string
    {
        return self::TIMEZONE_BY_CODE[strtolower(trim($code))] ?? 'UTC';
    }

    /**
     * What to print before an amount in this currency (from the currency catalogue, see Currency::symbol()).
     * Used for every client wallet amount, so it follows the client's currency.
     */
    public static function symbolOf(string $currency): string
    {
        return Currency::symbol($currency);
    }

    /** The country's default billing currency (from the master database, see Currency::defaultForCountry()). */
    public static function currencyOf(string $code): string
    {
        return Currency::defaultForCountry($code);
    }

    /**
     * Every country, sorted by name. Markets keep their own entry; "other" stands for all remaining ones.
     *
     * @return array<string, string> calling code => name
     */
    public static function all(): array
    {
        $all = self::MARKETS + self::OTHERS;
        asort($all);

        return $all;
    }

    public static function nameOf(string $code): string
    {
        $code = strtolower(trim($code));
        if ($code === 'other') {
            return 'Other countries';
        }

        return self::all()[$code] ?? '';
    }

    /** Find a calling code from a country name, as written in Meta's rate card CSV. */
    public static function codeByName(string $name): ?string
    {
        $needle = strtolower(trim(preg_replace('/\s*\(.*\)\s*$/', '', $name) ?? $name));
        if ($needle === '') {
            return null;
        }
        $aliases = [
            'uae' => '971', 'united states' => '1', 'usa' => '1', 'canada' => '1', 'north america' => '1',
            'uk' => '44', 'kazakhstan' => '77', 'korea' => '82', 'czech republic' => '420', 'russian federation' => '7',
        ];
        if (isset($aliases[$needle])) {
            return $aliases[$needle];
        }
        foreach (self::all() as $code => $label) {
            if (strtolower(preg_replace('/\s*\(.*\)\s*$/', '', $label) ?? $label) === $needle) {
                return (string) $code;
            }
        }

        return null;
    }
}
