<?php

namespace App\Modules\Integration\Providers;

use App\Modules\Integration\Contracts\InspectsTlsCertificates;
use App\Modules\Integration\Contracts\RunsStructuredAiWorkloads;
use App\Modules\Integration\Services\IntegrationHub\TlsCertificateInspector;
use App\Modules\Integration\Services\StructuredAiWorkloadExecutor;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class IntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            RunsStructuredAiWorkloads::class,
            StructuredAiWorkloadExecutor::class,
        );
        $this->app->bind(
            InspectsTlsCertificates::class,
            TlsCertificateInspector::class,
        );
    }

    public function boot(): void
    {
        RateLimiter::for('integration-hub-grants', function (Request $request): Limit {
            $tokenId = $request->user()?->currentAccessToken()?->id;

            return Limit::perMinute(20)->by('integration-hub-grants:'.($tokenId ?: $request->ip()));
        });
        RateLimiter::for('integration-hub-service', function (Request $request): Limit {
            $tokenId = $request->user()?->currentAccessToken()?->id;

            return Limit::perMinute(60)->by('integration-hub-service:'.($tokenId ?: $request->ip()));
        });
    }
}
