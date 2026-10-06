<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class CountryModel extends Model
{
    protected $table            = 'countries';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'name',
        'iso2',
        'dial_code',
        'min_digits',
        'max_digits',
        'phone_digits',
        'sort_order',
        'is_active',
        'is_deleted',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Cache active countries in-memory to avoid redundant DB queries during batch operations.
     *
     * @var list<array<string, mixed>>|null
     */
    private static ?array $cachedCountries = null;

    /**
     * Fetch all active countries ordered by sort_order and name.
     *
     * @return list<array<string, mixed>>
     */
    public function getActiveCountries(): array
    {
        if (self::$cachedCountries !== null) {
            return self::$cachedCountries;
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->where('is_active', 1)
            ->where('is_deleted', 0)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('name', 'ASC')
            ->findAll();

        self::$cachedCountries = $rows;

        return $rows;
    }

    /**
     * Find country by dial code (e.g., '91', '1', '44').
     */
    public function findByDialCode(string $dialCode): ?array
    {
        $clean = preg_replace('/\D+/', '', $dialCode) ?? '';
        if ($clean === '') {
            return null;
        }

        foreach ($this->getActiveCountries() as $country) {
            if ((string) $country['dial_code'] === $clean) {
                return $country;
            }
        }

        return $this->where('dial_code', $clean)
            ->where('is_active', 1)
            ->where('is_deleted', 0)
            ->first();
    }

    /**
     * Find country by ISO2 code (e.g., 'IN', 'US').
     */
    public function findByIso(string $iso): ?array
    {
        $clean = strtoupper(trim($iso));
        if ($clean === '') {
            return null;
        }

        foreach ($this->getActiveCountries() as $country) {
            if (strtoupper((string) $country['iso2']) === $clean) {
                return $country;
            }
        }

        return $this->where('iso2', $clean)
            ->where('is_active', 1)
            ->where('is_deleted', 0)
            ->first();
    }

    /**
     * Detect country from full international number (e.g., '919876543210' -> India).
     * Matches longest dial code first (e.g. 971 before 9).
     *
     * @return array{country: array<string, mixed>|null, local_number: string, dial_code: string}
     */
    public function detectFromFullPhone(string $fullPhone): array
    {
        $digits = preg_replace('/\D+/', '', $fullPhone) ?? '';
        $countries = $this->getActiveCountries();

        // Sort countries by dial_code length descending so 3-digit codes match before 1-digit codes
        usort($countries, static function ($a, $b) {
            return strlen((string) $b['dial_code']) <=> strlen((string) $a['dial_code']);
        });

        foreach ($countries as $c) {
            $dial = (string) $c['dial_code'];
            if (str_starts_with($digits, $dial)) {
                $local = substr($digits, strlen($dial));
                $len = strlen($local);
                $min = (int) $c['min_digits'];
                $max = (int) $c['max_digits'];

                // Verify the remainder fits the country's national subscriber length
                if ($len >= $min && $len <= $max) {
                    return [
                        'country'      => $c,
                        'local_number' => $local,
                        'dial_code'    => $dial,
                    ];
                }
            }
        }

        return [
            'country'      => null,
            'local_number' => $digits,
            'dial_code'    => '',
        ];
    }

    /**
     * Validate and format a phone number using country rules.
     *
     * @param string                  $phone            Full number or subscriber number
     * @param string|int|array|null   $countryOrCode    Dial code ('91'), ISO2 ('IN'), or country record
     *
     * @return array{valid: bool, phone: string, dial_code: string, local_number: string, country: array<string, mixed>|null, error: ?string}
     */
    public function validateAndFormatPhone(string $phone, $countryOrCode = null): array
    {
        $rawDigits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($rawDigits === '') {
            return [
                'valid'        => false,
                'phone'        => '',
                'dial_code'    => '',
                'local_number' => '',
                'country'      => null,
                'error'        => 'Mobile number is required.',
            ];
        }

        // Determine country target
        $targetCountry = null;
        if (is_array($countryOrCode)) {
            $targetCountry = $countryOrCode;
        } elseif (is_string($countryOrCode) && $countryOrCode !== '') {
            $cleanCode = preg_replace('/\D+/', '', $countryOrCode) ?? '';
            $targetCountry = $this->findByDialCode($cleanCode) ?? $this->findByIso($countryOrCode);
        } elseif (is_numeric($countryOrCode)) {
            $targetCountry = $this->find((int) $countryOrCode);
        }

        // If no explicit country passed, try detecting from full international phone digits
        if ($targetCountry === null) {
            $detection = $this->detectFromFullPhone($rawDigits);
            if ($detection['country'] !== null) {
                $targetCountry = $detection['country'];
                $localNumber   = $detection['local_number'];
                $dialCode      = $detection['dial_code'];

                return [
                    'valid'        => true,
                    'phone'        => $dialCode . $localNumber,
                    'dial_code'    => $dialCode,
                    'local_number' => $localNumber,
                    'country'      => $targetCountry,
                    'error'        => null,
                ];
            }

            // Cannot detect country code
            return [
                'valid'        => false,
                'phone'        => $rawDigits,
                'dial_code'    => '',
                'local_number' => $rawDigits,
                'country'      => null,
                'error'        => 'Country code is mandatory. Please select or provide a valid country code (e.g. +91 for India).',
            ];
        }

        $dialCode = (string) $targetCountry['dial_code'];
        $min      = (int) $targetCountry['min_digits'];
        $max      = (int) $targetCountry['max_digits'];
        $countryName = (string) $targetCountry['name'];

        // If phone already starts with dial_code, extract local part
        $localNumber = $rawDigits;
        if (str_starts_with($rawDigits, $dialCode) && strlen($rawDigits) > strlen($dialCode)) {
            $candidateLocal = substr($rawDigits, strlen($dialCode));
            // Only strip if remainder satisfies the digits constraint or is closer to it
            if (strlen($candidateLocal) >= $min && strlen($candidateLocal) <= $max) {
                $localNumber = $candidateLocal;
            }
        }

        // Strip leading 0 if present (e.g., UK 07911... -> 7911...)
        if (str_starts_with($localNumber, '0') && strlen($localNumber) > $min) {
            $localNumber = ltrim($localNumber, '0');
        }

        $localLen = strlen($localNumber);
        if ($localLen < $min || $localLen > $max) {
            $expected = ($min === $max) ? "exactly {$min} digits" : "between {$min} and {$max} digits";

            return [
                'valid'        => false,
                'phone'        => $dialCode . $localNumber,
                'dial_code'    => $dialCode,
                'local_number' => $localNumber,
                'country'      => $targetCountry,
                'error'        => "Mobile number for {$countryName} must be {$expected} (excluding country code +{$dialCode}). Got {$localLen} digits.",
            ];
        }

        return [
            'valid'        => true,
            'phone'        => $dialCode . $localNumber,
            'dial_code'    => $dialCode,
            'local_number' => $localNumber,
            'country'      => $targetCountry,
            'error'        => null,
        ];
    }
}
