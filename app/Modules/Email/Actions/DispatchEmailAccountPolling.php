<?php

namespace App\Modules\Email\Actions;

use App\Modules\Email\Jobs\FetchImapAccount;
use App\Modules\Email\Models\EmailAccount;
use Illuminate\Support\Facades\Log;
use Throwable;

class DispatchEmailAccountPolling
{
    /**
     * Start one isolated fetch per active account. Scheduled and command-line
     * polling share this path so adding a mailbox cannot leave one entry point
     * with a stale hard-coded account list.
     *
     * @return array{matched: int, started: int, failed: int}
     */
    public function handle(
        ?int $accountId = null,
        int $batchSize = 20,
        bool $asynchronously = true,
    ): array {
        $result = [
            'matched' => 0,
            'started' => 0,
            'failed' => 0,
        ];

        $query = EmailAccount::query()
            ->where('is_active', true)
            ->when($accountId !== null, fn ($query) => $query->whereKey($accountId));

        $query->chunkById(50, function ($accounts) use (&$result, $batchSize, $asynchronously): void {
            foreach ($accounts as $account) {
                $result['matched']++;

                try {
                    if ($asynchronously) {
                        FetchImapAccount::dispatch($account->id, max(1, $batchSize));
                    } else {
                        // A synchronous operator check must also persist every
                        // fetched message before returning. Keeping Store jobs
                        // inside the already-held provider lock prevents a
                        // concurrent worker from racing the parent fetch.
                        FetchImapAccount::dispatchSync($account->id, max(1, $batchSize), true);
                    }

                    $result['started']++;
                } catch (Throwable $exception) {
                    // A broken account must not prevent later active accounts
                    // from being checked. Provider messages are intentionally
                    // excluded because they may contain mailbox or credential
                    // details.
                    $result['failed']++;
                    Log::warning('Email account polling could not be started.', [
                        'account_id' => $account->id,
                        'mode' => $asynchronously ? 'queued' : 'synchronous',
                        'exception' => $exception::class,
                    ]);
                }
            }
        });

        return $result;
    }
}
