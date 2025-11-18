<?php

namespace App\Domain\Email\Jobs;

use App\Domain\Email\Models\EmailAccount;
use App\Domain\Email\Services\ImapClient;
use App\Domain\Email\Jobs\StoreInboundMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchImapAccount implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120; // seconds

    public function __construct(public int $accountId, public int $batchSize = 20) {}

    public function handle(): void
    {
        $account = EmailAccount::find($this->accountId);
        if (!$account || !$account->is_active) {
            return; // skip inactive or missing accounts
        }

        $client = new ImapClient($account);
        try {
            $client->connect();
        } catch (\Throwable $e) {
            Log::warning('IMAP connect failed', ['account' => $account->id, 'error' => $e->getMessage()]);
            return;
        }

        $messages = $client->fetchUnseen($this->batchSize);
        foreach ($messages as $payload) {
            // Dedup check: skip if already stored by unique index (account+mailbox+uid)
            $exists = $account->messages()->where('imap_uid', $payload['imap_uid'])->where('mailbox', 'INBOX')->exists();
            if ($exists) {
                continue;
            }

            $oversize = isset($payload['size_bytes']) && $payload['size_bytes'] > 25 * 1024 * 1024; // 25MB

            StoreInboundMessage::dispatch(array_merge($payload, [
                'account_id' => $account->id,
                'is_oversize' => $oversize,
            ]));
        }
    }
}
