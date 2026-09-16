<?php

namespace App\Providers;

use App\Enums\CefrLevel;
use App\Enums\LessonCardStatus;
use App\Enums\RoadmapStatus;
use App\GraphQL\Types\NativeEnumType;
use Illuminate\Support\ServiceProvider;
use Nuwave\Lighthouse\Schema\TypeRegistry;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(TypeRegistry $typeRegistry): void
    {
        // Exposed through the registry rather than the SDL so the values on the
        // wire stay the backed values (`active`), not the case names (`Active`).
        $typeRegistry->register(new NativeEnumType(CefrLevel::class));
        $typeRegistry->register(new NativeEnumType(RoadmapStatus::class));
        $typeRegistry->register(new NativeEnumType(LessonCardStatus::class));
    }
}
