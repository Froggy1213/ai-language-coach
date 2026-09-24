<?php

namespace App\Assessments;

/**
 * Everything the browser needs to POST one audio file straight to the bucket.
 *
 * A presigned POST is used rather than a presigned PUT because only the POST
 * policy can carry the size and content-type conditions that make S3 itself
 * reject a bad upload (plan §5) — hence the form fields alongside the URL.
 */
final readonly class PresignedUpload
{
    /**
     * @param  array<string, string>  $fields  Form fields to send with the file.
     */
    public function __construct(
        public string $uploadUrl,
        public string $fileUrl,
        public array $fields,
    ) {}
}
