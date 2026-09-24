<?php

namespace App\Assessments;

/**
 * What the bucket actually holds for an assessment upload, as reported by the
 * storage itself — the client's claims about size and type are not trusted.
 */
final readonly class AudioUpload
{
    public function __construct(
        public string $key,
        public string $fileUrl,
        public int $sizeBytes,
        public string $contentType,
    ) {}
}
