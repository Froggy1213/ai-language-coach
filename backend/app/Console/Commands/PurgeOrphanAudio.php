<?php

namespace App\Console\Commands;

use App\Enums\AssessmentStatus;
use App\Models\Assessment;
use Aws\S3\S3Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Throwable;

final class PurgeOrphanAudio extends Command
{
    protected $signature = 'privacy:purge-orphan-audio {--dry-run : Simulate the purge without deleting files from S3}';

    protected $description = 'Purge orphan assessment audio files in S3 older than the retention policy window';

    public function handle(S3Client $s3Client): int
    {
        $retentionDays = (int) config('privacy.orphan_audio_retention_days', 7);
        $cutoff = Date::now()->subDays($retentionDays);
        $isDryRun = (bool) $this->option('dry-run');

        $bucket = (string) config('filesystems.disks.s3.bucket');
        if ($bucket === '') {
            $this->warn('No S3 bucket configured; skipping orphan audio purge.');

            return self::SUCCESS;
        }

        $keyPrefix = rtrim((string) config('assessments.key_prefix', 'assessments'), '/').'/';
        $orphanKeys = [];

        try {
            $paginator = $s3Client->getPaginator('ListObjectsV2', [
                'Bucket' => $bucket,
                'Prefix' => $keyPrefix,
            ]);

            foreach ($paginator as $page) {
                /** @var array<int, array<string, mixed>> $contents */
                $contents = $page['Contents'] ?? [];

                foreach ($contents as $object) {
                    $key = (string) ($object['Key'] ?? '');
                    if ($key === '' || str_ends_with($key, '/')) {
                        continue;
                    }

                    $lastModified = isset($object['LastModified'])
                        ? Date::parse((string) $object['LastModified'])
                        : null;

                    // Keep files within the retention window
                    if ($lastModified !== null && $lastModified->gte($cutoff)) {
                        continue;
                    }

                    // A recording whose assessment is still actively processing must not be deleted.
                    $isStillProcessing = Assessment::query()
                        ->where('status', AssessmentStatus::Processing)
                        ->where('audio_url', 'like', "%{$key}")
                        ->exists();

                    if ($isStillProcessing) {
                        continue;
                    }

                    $orphanKeys[] = $key;
                }
            }
        } catch (Throwable $exception) {
            $this->error("Failed to list objects in bucket `{$bucket}`: {$exception->getMessage()}");
            report($exception);

            return self::FAILURE;
        }

        $count = count($orphanKeys);

        if ($isDryRun) {
            $this->info("[Dry Run] Would purge {$count} orphan audio recording(s) older than {$retentionDays} days.");

            return self::SUCCESS;
        }

        $deletedCount = 0;
        foreach ($orphanKeys as $key) {
            try {
                $s3Client->deleteObject([
                    'Bucket' => $bucket,
                    'Key' => $key,
                ]);
                $deletedCount++;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $this->info("Purged {$deletedCount} orphan audio recording(s) older than {$retentionDays} days.");

        return self::SUCCESS;
    }
}
