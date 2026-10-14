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
     * Contact columns plus attributes defined on Contacts → Attributes.
     *
     * @return list<string>
     */
    public static function knownKeys(?ContactModel $contacts = null): array
    {
        unset($contacts);
        $keys = self::coreKeys();
        try {
            $keys = array_merge($keys, array_keys(service('contactAttributes')->definitions()));
        } catch (\Throwable $e) {
            // Builder still has the core contact fields.
        }

        $keys = array_values(array_unique($keys));
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
