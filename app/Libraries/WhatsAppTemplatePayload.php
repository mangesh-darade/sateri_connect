<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Provider-neutral WhatsApp template component builders.
 *
 * Emits Meta Cloud / Cheerio Direct compatible shapes:
 *   header / body / button + parameters
 *
 * Provider-specific send envelopes and Cheerio auto-fill stay in each driver.
 */
final class WhatsAppTemplatePayload
{
    /**
     * @param list<array<string, mixed>> $components
     */
    public static function hasMediaHeader(array $components): bool
    {
        foreach ($components as $component) {
            if (! is_array($component)) {
                continue;
            }
            $type = strtolower((string) ($component['type'] ?? ''));
            if (in_array($type, ['image', 'video', 'document'], true)) {
                return true;
            }
            if ($type !== 'header') {
                continue;
            }
            $params = $component['parameters'] ?? null;
            if (! is_array($params)) {
                continue;
            }
            foreach ($params as $param) {
                if (! is_array($param)) {
                    continue;
                }
                $ptype = strtolower((string) ($param['type'] ?? ''));
                if (in_array($ptype, ['image', 'video', 'document'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Build a Meta-style HEADER media component from provider media id and/or public URL.
     *
     * @return array<string, mixed>|null
     */
    public static function buildHeaderComponent(string $headerType, string $mediaId = '', string $mediaUrl = ''): ?array
    {
        $headerType = strtolower(trim($headerType));
        if (! in_array($headerType, ['image', 'video', 'document'], true)) {
            return null;
        }

        $mediaId  = trim($mediaId);
        $mediaUrl = trim($mediaUrl);

        if ($mediaId !== '') {
            return [
                'type'       => 'header',
                'parameters' => [[
                    'type'      => $headerType,
                    $headerType => ['id' => $mediaId],
                ]],
            ];
        }

        if (
            $mediaUrl !== ''
            && preg_match('#^https://#i', $mediaUrl)
            && ! preg_match('#^https://(localhost|127\.0\.0\.1)(:|/|$)#i', $mediaUrl)
        ) {
            return [
                'type'       => 'header',
                'parameters' => [[
                    'type'      => $headerType,
                    $headerType => ['link' => $mediaUrl],
                ]],
            ];
        }

        return null;
    }

    /**
     * Merge campaign/inbox header_media_* fields into components when missing.
     *
     * @param list<array<string, mixed>> $components
     * @param array<string, mixed>       $payload
     *
     * @return list<array<string, mixed>>
     */
    public static function mergeHeaderFromPayload(array $components, array $payload, ?string $headerType = null): array
    {
        $components = array_values(array_filter($components, 'is_array'));
        if (self::hasMediaHeader($components)) {
            return $components;
        }

        $type = strtolower(trim((string) ($headerType ?? '')));
        if (! in_array($type, ['image', 'video', 'document'], true)) {
            $mime = strtolower(trim((string) ($payload['header_media_mime'] ?? '')));
            if (str_starts_with($mime, 'image/')) {
                $type = 'image';
            } elseif (str_starts_with($mime, 'video/')) {
                $type = 'video';
            } elseif ($mime !== '') {
                $type = 'document';
            } else {
                return $components;
            }
        }

        $header = self::buildHeaderComponent(
            $type,
            (string) ($payload['header_media_id'] ?? ''),
            (string) ($payload['header_media_url'] ?? '')
        );
        if ($header === null) {
            return $components;
        }

        array_unshift($components, $header);

        return array_values($components);
    }

    /**
     * Infer IMAGE/VIDEO/DOCUMENT header type from a local templates row.
     *
     * @param array<string, mixed>|null $template
     */
    public static function headerTypeFromTemplate(?array $template): string
    {
        if ($template === null) {
            return '';
        }

        $type = strtolower(trim((string) ($template['header_type'] ?? '')));

        return in_array($type, ['image', 'video', 'document'], true) ? $type : '';
    }
}
