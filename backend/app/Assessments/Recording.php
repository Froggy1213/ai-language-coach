<?php

namespace App\Assessments;

use RuntimeException;

/**
 * The audio of one assessment, staged as a file on the worker's disk.
 *
 * It is a path rather than a string of bytes because nothing downstream needs
 * the audio in memory: the transcription provider is handed a file, and the
 * limits are 15 MB per recording. Reading a body of that size into a PHP string
 * and then handing the same string to an HTTP client costs the worker several
 * times the object's size in peak memory for no gain — the S3 read streams
 * straight to this file instead.
 *
 * The file is ours to remove: `discard()` is called by whoever consumed it, and
 * the unit of work is a single `transcribe()` call.
 */
final readonly class Recording
{
    public function __construct(
        public string $path,
        public string $contentType,
    ) {}

    /**
     * Remove the staged copy.
     *
     * Best-effort by design: the caller is usually already leaving through an
     * exception, and a failed unlink must not replace that error with its own.
     * The caller reports the original failure either way.
     */
    public function discard(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }

    /**
     * @throws RuntimeException when the staged file is gone before it was read.
     */
    public function assertReadable(): void
    {
        if (! is_readable($this->path)) {
            throw new RuntimeException("The staged recording at `{$this->path}` is not readable.");
        }
    }
}
