<?php

namespace App\Assessments;

use InvalidArgumentException;

/**
 * The content types the assessment pipeline accepts, and how a recorder's
 * header maps onto them.
 *
 * Browsers report the codec as a parameter (`audio/webm;codecs=opus`), so the
 * type used for the upload policy and the database is always the base type.
 */
final class AudioContentType
{
    /**
     * @var array<string, string>
     */
    private const EXTENSIONS = [
        'audio/webm' => 'webm',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
        'audio/wave' => 'wav',
        'audio/mpeg' => 'mp3',
        'audio/mp3' => 'mp3',
        'audio/mp4' => 'm4a',
    ];

    public static function normalize(string $contentType): string
    {
        return mb_strtolower(trim(explode(';', $contentType, 2)[0]));
    }

    public static function isAllowed(string $contentType): bool
    {
        /** @var list<string> $allowed */
        $allowed = config('assessments.allowed_mime_types', []);

        return in_array(self::normalize($contentType), $allowed, true);
    }

    /**
     * File extension for the object key, so the bucket stays readable and the
     * transcription service can sniff the format from the name.
     */
    public static function extension(string $contentType): string
    {
        return self::EXTENSIONS[self::normalize($contentType)]
            ?? throw new InvalidArgumentException("Unsupported audio content type `{$contentType}`.");
    }
}
