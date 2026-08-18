<?php

namespace App\Modules\Email\Jobs;

use App\Models\Settings\CommonSetting;
use App\Modules\Email\Actions\DispatchEmailAccountPolling;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class PollActiveEmailAccounts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public function __construct()
    {
        $this->onQueue('email');
    }

    public function handle(DispatchEmailAccountPolling $polling): void
    {
        $settings = CommonSetting::query()
            ->where('type', 'emailhub')
            ->get()->pluck('value', 'name')->toArray();

        if (($settings['pause_ingest'] ?? '0') === '1') {
            return;
        }

        $pollInterval = (int) ($settings['poll_interval'] ?? 1);
        $lastRun = Cache::get('email_last_poll_run');

        if ($lastRun && now()->diffInMinutes($lastRun, true) < $pollInterval) {
            return;
        }

        Cache::put('email_last_poll_run', now());

        $batchSize = (int) ($settings['batch_size'] ?? 20);
        $polling->handle(batchSize: $batchSize, asynchronously: true);
    }
}
