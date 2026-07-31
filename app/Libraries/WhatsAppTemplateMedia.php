<?php

namespace App\Libraries;

/**
 * Rules for matching an uploaded file to a WhatsApp template's media header.
 *
 * Meta rejects a send when the header parameter type does not match the
 * approved header format, so the same rule is enforced in the wizard UI,
 * the campaign controller, and at send time.
 */
final class WhatsAppTemplateMedia
{
    /**
     * Header formats that carry a media file rather than text.
     *
     * @return list<string>
     */
    public static function mediaHeaderTypes(): array
    {
        return ['image', 'video', 'document'];
    }

    public static function isMediaHeader(string $headerType): bool
    {
        return in_array(strtolower(trim($headerType)), self::mediaHeaderTypes(), true);
    }

    public static function matchesHeaderType(string $headerType, string $mime): bool
    {
        $mime = strtolower(trim($mime));
        if ($mime === '') {
            return true;
        }

        return match (strtolower(trim($headerType))) {
            'image'    => str_starts_with($mime, 'image/'),
            'video'    => str_starts_with($mime, 'video/'),
            'document' => $mime === 'application/pdf'
                || str_contains($mime, 'document')
                || $mime === 'application/msword',
            default => true,
        };
    }

    /**
     * Value for a file input's `accept` attribute.
     */
    public static function acceptAttribute(string $headerType): string
    {
        return match (strtolower(trim($headerType))) {
            'image'    => 'image/png,image/jpeg,image/webp',
            'video'    => 'video/mp4,video/3gpp',
            'document' => 'application/pdf',
            default    => 'image/png,image/jpeg,image/webp,video/mp4,application/pdf',
        };
    }

    /**
     * Short human hint describing what the header accepts.
     */
    public static function expectedLabel(string $headerType): string
    {
        return match (strtolower(trim($headerType))) {
            'image'    => 'a PNG, JPEG, or WEBP image',
            'video'    => 'an MP4 video',
            'document' => 'a PDF document',
            default    => 'a supported media file',
        };
    }

    public static function mismatchMessage(string $templateName, string $headerType, string $mime): string
    {
        $name = trim($templateName);

        return sprintf(
            'Template "%s" has a %s header, so it needs %s. You uploaded %s.',
            $name !== '' ? $name : 'this template',
            strtoupper(trim($headerType)),
            self::expectedLabel($headerType),
            trim($mime) !== '' ? $mime : 'an unsupported file type'
        );
    }
}
