<?php

namespace App\Modules\Email\Jobs;

use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Services\ImapClient;
use App\Modules\Email\Jobs\StoreInboundMessage;
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

    public function __construct(
        public int $accountId,
        public int $batchSize = 20,
        public bool $syncStore = false
    ) {}

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

        if (count($messages) < $this->batchSize) {
            $messages = collect($messages)
                ->merge($client->fetchRecent($this->batchSize))
                ->unique('imap_uid')
                ->take($this->batchSize)
                ->values()
                ->all();
        }

        $account->forceFill([
            'last_successful_fetch_at' => now(),
            'last_error_code' => null,
            'last_error_message' => null,
        ])->save();

        foreach ($messages as $payload) {
            // Dedup check: include soft-deleted rows because the DB unique key still reserves the UID.
            $exists = $account->messages()
                ->withTrashed()
                ->where('imap_uid', $payload['imap_uid'])
                ->where('mailbox', 'INBOX')
                ->exists();
            if ($exists) {
                continue;
            }

            $settings = \App\Models\Settings\CommonSetting::where('type', 'emailhub')
                ->get()->pluck('value', 'name')->toArray();

            $limitMb = (int)($settings['size_limit_mb'] ?? 25);
            $oversize = isset($payload['size_bytes']) && $payload['size_bytes'] > $limitMb * 1024 * 1024;

            if ($this->syncStore) {
                StoreInboundMessage::dispatchSync(array_merge($payload, [
                    'account_id' => $account->id,
                    'is_oversize' => $oversize,
                ]));
            } else {
                StoreInboundMessage::dispatch(array_merge($payload, [
                    'account_id' => $account->id,
                    'is_oversize' => $oversize,
                ]));
            }
        }
    }
}
