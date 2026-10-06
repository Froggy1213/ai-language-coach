<?php

namespace App\Providers;

use App\Observability\CloudWatchMetricPublisher;
use App\Observability\MetricPublisher;
use App\Observability\NullMetricPublisher;
use Aws\CloudWatch\CloudWatchClient;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the metrics sink for the voice pipeline (plan §7).
 *
 * Separate from AppServiceProvider because the choice here is entirely
 * configuration-driven and self-contained: either a CloudWatch client can be
 * built or the pipeline talks to nothing at all.
 */
class ObservabilityServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(MetricPublisher::class, function (Application $app): MetricPublisher {
            /** @var array<string, mixed> $config */
            $config = $app['config']->get('observability.cloudwatch', []);

            if (! ($config['enabled'] ?? false)) {
                return new NullMetricPublisher;
            }

            return new CloudWatchMetricPublisher(
                client: new CloudWatchClient($this->clientConfig($app, $config)),
                namespace: (string) ($config['namespace'] ?? 'AiLanguageCoach/Voice'),
                environment: (string) ($config['environment'] ?? 'production'),
            );
        });
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function clientConfig(Application $app, array $config): array
    {
        $timeout = (int) ($config['timeout_seconds'] ?? 3);

        $clientConfig = [
            'version' => 'latest',
            'region' => (string) ($config['region'] ?? 'us-east-1'),
            'http' => ['timeout' => $timeout, 'connect_timeout' => min(2, $timeout)],
        ];

        /*
        | Credentials come from the same place the S3 client reads them (README,
        | decision 14), so there is one answer to "which AWS account is this".
        | They are only passed on when they exist: on ECS the task role supplies
        | them, and an empty key pair would fail signing instead of falling
        | through to the instance's own credentials.
        */
        /** @var array<string, mixed> $disk */
        $disk = $app['config']->get('filesystems.disks.s3', []);
        $key = (string) ($disk['key'] ?? '');
        $secret = (string) ($disk['secret'] ?? '');

        if ($key !== '' && $secret !== '') {
            $clientConfig['credentials'] = ['key' => $key, 'secret' => $secret];
        }

        return $clientConfig;
    }
}
