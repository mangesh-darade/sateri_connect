<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Canonical WhatsApp template variable parser.
 *
 * Placeholder identities and example values are deliberately kept separate:
 * `{{1}}` is the key; "Mangesh" is only its example.
 */
final class WhatsAppTemplateVariables
{
    /**
     * @param list<array<string, mixed>> $components
     * @return list<array{key:string,index:int,style:string,example:string,suggested_source:string}>
     */
    public static function definitionsFromComponents(array $components): array
    {
        foreach ($components as $component) {
            if (! is_array($component) || strtoupper((string) ($component['type'] ?? '')) !== 'BODY') {
                continue;
            }

            $example = is_array($component['example'] ?? null) ? $component['example'] : [];

            return self::definitionsFromBody(
                (string) ($component['text'] ?? ''),
                self::positionalExamples($example),
                self::namedExamples($example)
            );
        }

        return [];
    }

    /**
     * Repair old rows where templates.variables contains samples instead of keys.
     *
     * @param mixed $stored
     * @return list<array{key:string,index:int,style:string,example:string,suggested_source:string}>
     */
    public static function definitionsForTemplate(mixed $stored, string $body, mixed $rawPayload = null): array
    {
        $stored = self::decodeArray($stored);
        $keys   = self::placeholderKeys($body);

        if ($keys === []) {
            return [];
        }

        $raw = self::decodeArray($rawPayload);
        $components = is_array($raw['components'] ?? null) ? $raw['components'] : [];
        $fromComponents = self::definitionsFromComponents($components);
        if (array_column($fromComponents, 'key') === $keys) {
            return $fromComponents;
        }

        $legacyExamples = [];
        if (array_is_list($stored) && count($stored) === count($keys)) {
            foreach ($stored as $index => $value) {
                $value = is_scalar($value) ? trim((string) $value) : '';
                if ($value !== '' && $value !== $keys[$index]) {
                    $legacyExamples[$index] = $value;
                }
            }
        }

        return self::buildDefinitions($keys, $legacyExamples, []);
    }

    /**
     * @param list<array<string, mixed>> $components
     * @return list<string>
     */
    public static function identitiesFromComponents(array $components): array
    {
        return array_column(self::definitionsFromComponents($components), 'key');
    }

    /**
     * @return list<string>
     */
    public static function identitiesFromBody(string $body): array
    {
        return self::placeholderKeys($body);
    }

    /**
     * @param list<string> $positionalExamples
     * @param array<string, string> $namedExamples
     * @return list<array{key:string,index:int,style:string,example:string,suggested_source:string}>
     */
    public static function definitionsFromBody(
        string $body,
        array $positionalExamples = [],
        array $namedExamples = []
    ): array {
        return self::buildDefinitions(self::placeholderKeys($body), $positionalExamples, $namedExamples);
    }

    public static function suggestSource(string $key, string $example = ''): string
    {
        $haystack = strtolower(trim($key . ' ' . $example));

        if (preg_match('/\b(e-?mail|email_address)\b/', $haystack)) {
            return 'email';
        }
        if (preg_match('/\b(mobile|phone|telephone|whatsapp_number)\b/', $haystack)) {
            return 'mobile';
        }
        if (preg_match('/\b(customer_name|contact_name|first_name|full_name|name)\b/', $haystack)) {
            return 'name';
        }

        // A real provider example is safer as a pre-filled custom value than
        // silently sending the contact name for every variable.
        return $example !== '' ? 'custom' : '';
    }

    /**
     * Human label for a template variable input (never hardcodes count).
     *
     * @param array{key?:string,index?:int,example?:string,suggested_source?:string} $definition
     */
    public static function labelFor(array $definition): string
    {
        $key      = trim((string) ($definition['key'] ?? ''));
        $example  = trim((string) ($definition['example'] ?? ''));
        $source   = (string) ($definition['suggested_source'] ?? '');
        $index    = (int) ($definition['index'] ?? 0);

        if ($source === 'name') {
            return 'Customer Name';
        }
        if ($source === 'mobile') {
            return 'Phone Number';
        }
        if ($source === 'email') {
            return 'Email';
        }

        $haystack = strtolower($key . ' ' . $example);
        if (preg_match('/\border\b/', $haystack)) {
            return 'Order Number';
        }
        if (preg_match('/\b(otp|code|pin)\b/', $haystack)) {
            return 'Code';
        }
        if (! ctype_digit($key) && $key !== '') {
            return ucwords(str_replace('_', ' ', $key));
        }

        if ($example !== '' && ! preg_match('/^sample\d+$/i', $example)) {
            return 'Variable ' . ($index > 0 ? $index : $key) . ' (' . $example . ')';
        }

        return 'Variable {{' . ($key !== '' ? $key : (string) $index) . '}}';
    }

    /**
     * Build the safe backend mapping used when the UI does not submit one.
     *
     * Semantic variables map to contact fields; non-semantic provider examples
     * become explicit static values. Unknown variables remain missing.
     *
     * @param list<array{key:string,example:string,suggested_source:string}> $definitions
     * @return array<string, string>
     */
    public static function applyMappingDefaults(array $submitted, array $definitions): array
    {
        $mapping = [];
        foreach ($definitions as $definition) {
            $key = (string) ($definition['key'] ?? '');
            if ($key === '') {
                continue;
            }

            $value = isset($submitted[$key]) && is_scalar($submitted[$key])
                ? trim((string) $submitted[$key])
                : '';
            if ($value !== '') {
                $mapping[$key] = $value;
                continue;
            }

            $suggestion = (string) ($definition['suggested_source'] ?? '');
            $example    = trim((string) ($definition['example'] ?? ''));
            if (in_array($suggestion, ['name', 'mobile', 'email'], true)) {
                $mapping[$key] = $suggestion;
            } elseif ($suggestion === 'custom' && $example !== '') {
                $mapping[$key] = $example;
            }
        }

        return $mapping;
    }

    /**
     * @return list<string>
     */
    private static function placeholderKeys(string $body): array
    {
        if (! preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $body, $matches)) {
            return [];
        }

        $keys = [];
        foreach ($matches[1] as $match) {
            $key = trim((string) $match);
            if ($key !== '' && ! in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @param list<string> $keys
     * @param array<int, string> $positionalExamples
     * @param array<string, string> $namedExamples
     * @return list<array{key:string,index:int,style:string,example:string,suggested_source:string}>
     */
    private static function buildDefinitions(array $keys, array $positionalExamples, array $namedExamples): array
    {
        $definitions = [];
        foreach ($keys as $index => $key) {
            $style   = ctype_digit($key) ? 'positional' : 'named';
            $example = $style === 'named'
                ? trim((string) ($namedExamples[$key] ?? ''))
                : trim((string) ($positionalExamples[$index] ?? ''));

            $definitions[] = [
                'key'              => $key,
                'index'            => $index + 1,
                'style'            => $style,
                'example'          => $example,
                'suggested_source' => self::suggestSource($key, $example),
            ];
        }

        return $definitions;
    }

    /**
     * @param array<string, mixed> $example
     * @return list<string>
     */
    private static function positionalExamples(array $example): array
    {
        $row = $example['body_text'][0] ?? [];
        if (! is_array($row)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '',
            $row
        ));
    }

    /**
     * @param array<string, mixed> $example
     * @return array<string, string>
     */
    private static function namedExamples(array $example): array
    {
        $rows = $example['body_text_named_params'] ?? [];
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = trim((string) ($row['param_name'] ?? $row['parameter_name'] ?? $row['name'] ?? ''));
            $value = trim((string) ($row['example'] ?? $row['value'] ?? ''));
            if ($key !== '') {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * @return array<mixed>
     */
    private static function decodeArray(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($value) ? $value : [];
    }
}
