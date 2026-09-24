<?php

namespace App\Providers;

use App\Assessments\AssessmentAudioStorage;
use App\Assessments\CefrAssessor;
use App\Assessments\DeepSeekCefrAssessor;
use App\Assessments\S3AssessmentAudioStorage;
use App\Enums\AssessmentStatus;
use App\Enums\CefrLevel;
use App\Enums\LessonCardStatus;
use App\Enums\RoadmapStatus;
use App\Enums\VoiceSessionStatus;
use App\GraphQL\Types\NativeEnumType;
use Aws\S3\S3Client;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Nuwave\Lighthouse\Schema\TypeRegistry;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Built from the `s3` disk so the credentials live in one place. The
        // Laravel filesystem driver needs league/flysystem-aws-s3-v3, which the
        // presigned-POST flow does not: it needs the SDK's signing, not its
        // storage adapter (README, decision 12).
        $this->app->singleton(S3Client::class, function (Application $app): S3Client {
            /** @var array<string, mixed> $disk */
            $disk = $app['config']->get('filesystems.disks.s3', []);

            $config = [
                'version' => 'latest',
                'region' => $disk['region'] ?: 'us-east-1',
                'credentials' => [
                    'key' => $disk['key'] ?? '',
                    'secret' => $disk['secret'] ?? '',
                ],
            ];

            if (filled($disk['endpoint'] ?? null)) {
                $config['endpoint'] = $disk['endpoint'];
                $config['use_path_style_endpoint'] = (bool) ($disk['use_path_style_endpoint'] ?? false);
            }

            return new S3Client($config);
        });

        $this->app->bind(AssessmentAudioStorage::class, fn (Application $app): S3AssessmentAudioStorage => new S3AssessmentAudioStorage(
            client: $app->make(S3Client::class),
            bucket: (string) $app['config']->get('filesystems.disks.s3.bucket'),
        ));

        $this->app->bind(CefrAssessor::class, DeepSeekCefrAssessor::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(TypeRegistry $typeRegistry): void
    {
        // The Deepgram package issues its own HTTP request, so the limit for a
        // batch transcription is global rather than per call: the framework
        // default of 30 seconds is shorter than a long recording takes, and the
        // assessment job would burn its attempts on timeouts.
        Http::globalOptions([
            'connect_timeout' => 5,
            'timeout' => (int) config('assessments.transcription_timeout_seconds'),
        ]);

        // Exposed through the registry rather than the SDL so the values on the
        // wire stay the backed values (`active`), not the case names (`Active`).
        $typeRegistry->register(new NativeEnumType(CefrLevel::class));
        $typeRegistry->register(new NativeEnumType(RoadmapStatus::class));
        $typeRegistry->register(new NativeEnumType(LessonCardStatus::class));
        $typeRegistry->register(new NativeEnumType(AssessmentStatus::class));
        $typeRegistry->register(new NativeEnumType(VoiceSessionStatus::class));
    }
}
