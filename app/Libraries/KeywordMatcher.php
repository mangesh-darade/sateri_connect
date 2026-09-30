<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Keyword matching shared by Keywords (auto-replies) and workflow keyword triggers.
 *
 * - Keyword field may hold several keywords separated by commas / new lines ("hi, hello, namaste").
 * - Case, extra spaces and surrounding punctuation are ignored ("Hi!" = "hi").
 * - exact:       whole message equals a keyword            ("hi"  → hi ✓, hi mangesh ✗)
 * - starts_with: message begins with a keyword as a word   ("hi mangesh" ✓, "hindi" ✗)
 * - contains:    keyword appears as a whole word/phrase    ("ok hi there" ✓, "this" ✗)
 */
class KeywordMatcher
{
    public const TYPES = ['exact' => 'Exact', 'contains' => 'Contains', 'starts_with' => 'Starts with'];

    public static function matches(string $message, string $keywords, string $matchType = 'exact'): bool
    {
        $text = self::normalize($message);
        if ($text === '') {
            return false;
        }
        foreach (self::split($keywords) as $keyword) {
            if (self::matchOne($text, $keyword, $matchType)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public static function split(string $keywords): array
    {
        $out = [];
        foreach (preg_split('/[,\n\r]+/u', $keywords) ?: [] as $part) {
            $part = self::normalize($part);
            if ($part !== '') {
                $out[] = $part;
            }
        }

        return array_values(array_unique($out));
    }

    public static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return trim($text, " \t.,!?;:'\"()[]-_*~");
    }

    protected static function matchOne(string $text, string $keyword, string $matchType): bool
    {
        if ($text === $keyword) {
            return true;
        }
        $word = '(?![\p{L}\p{N}])';
        $kw   = preg_quote($keyword, '/');

        return match ($matchType) {
            'starts_with' => preg_match('/^' . $kw . $word . '/u', $text) === 1,
            'contains'    => preg_match('/(?<![\p{L}\p{N}])' . $kw . $word . '/u', $text) === 1,
            default       => false,
        };
    }
}
