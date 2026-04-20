<?php

namespace App\Domain\Email\Jobs;

use App\Domain\Email\Models\EmailAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PollActiveEmailAccounts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public function handle(): void
    {
        $settings = \App\Models\Settings\CommonSetting::where('type', 'emailhub')
            ->get()->pluck('value', 'name')->toArray();

        if (($settings['pause_ingest'] ?? '0') === '1') {
            return;
        }

        $pollInterval = (int)($settings['poll_interval'] ?? 1);
        $lastRun = \Illuminate\Support\Facades\Cache::get('email_last_poll_run');

        if ($lastRun && now()->diffInMinutes($lastRun) < $pollInterval) {
            return;
        }

        \Illuminate\Support\Facades\Cache::put('email_last_poll_run', now());

        $batchSize = (int)($settings['batch_size'] ?? 20);

        EmailAccount::query()
            ->where('is_active', true)
            ->chunkById(50, function ($accounts) use ($batchSize) {
                foreach ($accounts as $account) {
                    FetchImapAccount::dispatch($account->id, $batchSize);
                }
            });
    }
}
