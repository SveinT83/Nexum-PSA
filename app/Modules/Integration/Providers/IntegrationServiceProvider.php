<?php

namespace App\Modules\Integration\Providers;

use App\Modules\Integration\Contracts\RunsStructuredAiWorkloads;
use App\Modules\Integration\Services\StructuredAiWorkloadExecutor;
use Illuminate\Support\ServiceProvider;

class IntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            RunsStructuredAiWorkloads::class,
            StructuredAiWorkloadExecutor::class,
        );
    }

    public function boot(): void {}
}
