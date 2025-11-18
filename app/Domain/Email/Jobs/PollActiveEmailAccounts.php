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
        EmailAccount::query()
            ->where('is_active', true)
            ->chunkById(50, function ($accounts) {
                foreach ($accounts as $account) {
                    FetchImapAccount::dispatch($account->id);
                }
            });
    }
}
