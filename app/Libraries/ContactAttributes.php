<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\ContactModel;

/**
 * Contact attribute helpers for custom_fields + builder mapping.
 */
class ContactAttributes
{
    /**
     * Core contact columns that can be used like attributes.
     *
     * @return list<string>
     */
    public static function coreKeys(): array
    {
        return ['name', 'mobile', 'email', 'country', 'notes', 'status', 'birthday'];
    }

    /**
     * Collect attribute keys seen across contacts + common Cheerio fields.
     *
     * @return list<string>
     */
    public static function knownKeys(?ContactModel $contacts = null): array
    {
        $keys = self::coreKeys();
        $extra = [
            'source', 'city', 'business_name', 'business_type',
            'current_software_name', 'required_features', 'product_sel_1',
            'webhook_status', 'full_name', 'phone',
        ];

        try {
            $model = $contacts ?? model(ContactModel::class);
            $rows  = $model->select('custom_fields')->orderBy('id', 'DESC')->findAll(500);
            foreach ($rows as $row) {
                $fields = $row['custom_fields'] ?? null;
                if (is_string($fields)) {
                    $decoded = json_decode($fields, true);
                    $fields  = is_array($decoded) ? $decoded : null;
                }
                if (! is_array($fields)) {
                    continue;
                }
                foreach (array_keys($fields) as $key) {
                    $key = trim((string) $key);
                    if ($key === '' || str_starts_with($key, '_')) {
                        continue;
                    }
                    $extra[] = $key;
                }
            }
        } catch (\Throwable $e) {
            // ignore — builder still has core + defaults
        }

        $keys = array_values(array_unique(array_merge($keys, $extra)));
        sort($keys, SORT_NATURAL | SORT_FLAG_CASE);

        return $keys;
    }

    /**
     * Flatten contact row + custom_fields for template / webhook mapping.
     *
     * @param array<string, mixed> $contact
     *
     * @return array<string, mixed>
     */
    public static function flatten(array $contact): array
    {
        $out = [];
        foreach (self::coreKeys() as $key) {
            if (array_key_exists($key, $contact)) {
                $out[$key] = $contact[$key];
            }
        }

        $fields = $contact['custom_fields'] ?? [];
        if (is_string($fields)) {
            $decoded = json_decode($fields, true);
            $fields  = is_array($decoded) ? $decoded : [];
        }
        if (is_array($fields)) {
            foreach ($fields as $key => $value) {
                $key = trim((string) $key);
                if ($key === '' || str_starts_with($key, '_')) {
                    continue;
                }
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
