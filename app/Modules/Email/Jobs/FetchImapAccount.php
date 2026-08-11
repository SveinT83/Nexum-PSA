<?php

namespace App\Modules\Email\Jobs;

use App\Models\Settings\CommonSetting;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Services\ImapClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
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

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('email-fetch-account:'.$this->accountId))
                ->releaseAfter(5)
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function handle(): void
    {
        $account = EmailAccount::find($this->accountId);
        if (! $account || ! $account->is_active) {
            return; // skip inactive or missing accounts
        }

        $client = $this->makeImapClient($account);
        try {
            $client->connect();
        } catch (\Throwable $e) {
            Log::warning('IMAP connect failed', ['account' => $account->id, 'error' => $e->getMessage()]);

            return;
        }

        try {
            $batchSize = max(1, $this->batchSize);
            $mailboxState = $client->mailboxState();
            $uidValidity = (int) $mailboxState['uid_validity'];
            $nextUid = (int) $mailboxState['next_uid'];

            if ($uidValidity <= 0 || $nextUid <= 0) {
                $account->forceFill([
                    'last_error_code' => 'IMAP_MAILBOX_STATE',
                    'last_error_message' => 'INBOX did not return a valid UIDVALIDITY/UIDNEXT state.',
                ])->save();
                Log::warning('IMAP mailbox state is incomplete; automatic ingest stopped.', [
                    'account' => $account->id,
                ]);

                return;
            }

            if ($account->imap_uid_validity === null || $account->imap_live_start_uid === null) {
                // First activation is a forward-only baseline. Messages that
                // already existed in the mailbox are never implicit work.
                $account->forceFill([
                    'imap_uid_validity' => $uidValidity,
                    'imap_live_start_uid' => max(0, $nextUid - 1),
                    'imap_live_cursor_initialized_at' => now(),
                    'last_successful_fetch_at' => now(),
                    'last_error_code' => null,
                    'last_error_message' => null,
                ])->save();

                return;
            }

            if ((int) $account->imap_uid_validity !== $uidValidity) {
                // UID reuse after a mailbox reset can route old mail as new.
                // Fail closed until an operator explicitly re-baselines it.
                $account->forceFill([
                    'last_error_code' => 'IMAP_UIDVALIDITY_CHANGED',
                    'last_error_message' => 'INBOX UIDVALIDITY changed; automatic ingest requires an explicit re-baseline.',
                ])->save();
                Log::warning('IMAP UIDVALIDITY changed; automatic ingest stopped.', [
                    'account' => $account->id,
                    'expected_uid_validity' => (int) $account->imap_uid_validity,
                    'actual_uid_validity' => $uidValidity,
                ]);

                return;
            }

            $highestStoredUid = (int) $account->messages()
                ->withTrashed()
                ->where('mailbox', 'INBOX')
                ->max('imap_uid');
            $liveHighWaterUid = max((int) $account->imap_live_start_uid, $highestStoredUid);

            // Drain the oldest new UIDs first. This remains bounded without
            // losing a burst larger than one poll batch.
            $messages = $this->unstoredMessages(
                $account,
                $client->fetchAfterUid($liveHighWaterUid, $batchSize),
            )
                ->filter(fn (array $payload): bool => (int) $payload['imap_uid'] > $liveHighWaterUid)
                ->take($batchSize)
                ->values();

            $account->forceFill([
                'last_successful_fetch_at' => now(),
                'last_error_code' => null,
                'last_error_message' => null,
            ])->save();

            $settings = CommonSetting::query()
                ->where('type', 'emailhub')
                ->pluck('value', 'name')
                ->all();
            $limitMb = (int) ($settings['size_limit_mb'] ?? 25);

            foreach ($messages as $payload) {
                $oversize = isset($payload['size_bytes'])
                    && $payload['size_bytes'] > $limitMb * 1024 * 1024;

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
        } finally {
            $client->disconnect();
        }
    }

    protected function makeImapClient(EmailAccount $account): ImapClient
    {
        return new ImapClient($account);
    }

    private function unstoredMessages(
        EmailAccount $account,
        array $messages,
    ): Collection {
        $candidates = collect($messages)
            ->filter(fn (array $payload): bool => (int) ($payload['imap_uid'] ?? 0) > 0)
            ->unique(fn (array $payload): int => (int) $payload['imap_uid'])
            ->values();

        if ($candidates->isEmpty()) {
            return collect();
        }

        // Include soft-deleted rows because the database uniqueness boundary
        // still reserves their account/mailbox/UID identity.
        $stored = $account->messages()
            ->withTrashed()
            ->where('mailbox', 'INBOX')
            ->whereIn('imap_uid', $candidates->pluck('imap_uid'))
            ->pluck('imap_uid')
            ->map(fn ($uid): int => (int) $uid)
            ->flip();

        return $candidates
            ->reject(fn (array $payload): bool => $stored->has((int) $payload['imap_uid']))
            ->values();
    }
}
