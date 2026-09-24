<?php

namespace App\Assessments;

/**
 * The audio of one assessment, read back out of the bucket.
 *
 * It is passed around as bytes rather than as an object URL because the bucket
 * is private: a plain URL handed to a third party answers 403 (and a presigned
 * URL would expose the recording, which plan §5 asks us not to keep around).
 */
final readonly class Recording
{
    public function __construct(
        public string $contents,
        public string $contentType,
    ) {}
}
