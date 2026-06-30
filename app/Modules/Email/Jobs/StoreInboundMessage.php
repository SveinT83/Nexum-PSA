<?php

namespace App\Modules\Email\Jobs;

use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Services\ImapClient;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Modules\Email\Services\BodyNormalizer;
use App\Modules\Email\Services\HtmlSanitizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class StoreInboundMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    /**
     * @param array $payload Structured inbound email data
     */
    public function __construct(public array $payload) {}

    public function handle(): void
    {
        $account = EmailAccount::find($this->payload['account_id']);
        if (!$account) {
            return;
        }

        $identity = [
            'account_id' => $this->payload['account_id'],
            'mailbox' => $this->payload['mailbox'] ?? 'INBOX',
            'imap_uid' => $this->payload['imap_uid'],
        ];

        $existing = EmailMessage::withTrashed()
            ->where($identity)
            ->first();

        if ($existing) {
            if ($existing->trashed()) {
                Log::info('Inbound email UID already exists as soft-deleted; skipping re-import.', $identity + [
                    'email_message_id' => $existing->id,
                ]);

                return;
            }

            Log::info('Inbound email UID already stored; skipping duplicate store.', $identity + [
                'email_message_id' => $existing->id,
            ]);

            ProcessInboundRules::dispatch($existing->id);

            return;
        }

        // Oversize: only store headers/meta, skip body & attachments
        $html = null;
        $text = null;
        $rawPath = null;
        $attachmentsCount = 0;

        if (!($this->payload['is_oversize'] ?? false)) {
            // Fetch full message by UID for body & attachments
            try {
                $client = new ImapClient($account);
                $client->connect();
                $message = $client->fetchByUid($this->payload['imap_uid']);
            } catch (\Throwable $e) {
                Log::warning('Failed to refetch full message', ['uid' => $this->payload['imap_uid'], 'error' => $e->getMessage()]);
                $message = null;
            }

            if ($message) {
                $html = $message->getHTMLBody();
                $text = $message->getTextBody();
                // Store raw .eml
                try {
                    $raw = $message->getRawBody();
                    $rawPath = 'email/raw/' . $account->id . '/' . $this->payload['imap_uid'] . '.eml';
                    Storage::disk('local')->put($rawPath, $raw);
                } catch (\Throwable $e) {
                    Log::warning('Raw save failed', ['uid' => $this->payload['imap_uid'], 'error' => $e->getMessage()]);
                }

                // Attachments
                $attachments = $message->getAttachments();
                foreach ($attachments as $att) {
                    $path = 'email/attachments/' . $account->id . '/' . $this->payload['imap_uid'] . '/' . $att->getName();
                    try {
                        Storage::disk('local')->put($path, $att->getContent());
                        $attachmentsCount++;
                        // We could create EmailAttachment records here (future step)
                    } catch (\Throwable $e) {
                        Log::warning('Attachment save failed', ['file' => $att->getName(), 'uid' => $this->payload['imap_uid'], 'error' => $e->getMessage()]);
                    }
                }
            }
        }

        $sanitized = HtmlSanitizer::sanitize($html);
        $textNormalized = $text ?: BodyNormalizer::toText($html);

        $messageModel = $this->storeMessage($identity, [
            'message_id' => $this->payload['message_id'] ?? null,
            'subject' => $this->payload['subject'] ?? null,
            'from_name' => $this->payload['from_name'] ?? null,
            'from_email' => $this->payload['from_email'] ?? null,
            'to_json' => $this->payload['to'] ?? [],
            'cc_json' => $this->payload['cc'] ?? [],
            'headers_json' => $this->payload['headers'] ?? [],
            'in_reply_to' => $this->payload['in_reply_to'] ?? null,
            'references' => $this->payload['references'] ?? null,
            'received_at' => $this->payload['received_at'] ?? now(),
            'size_bytes' => $this->payload['size_bytes'] ?? null,
            'is_oversize' => $this->payload['is_oversize'] ?? false,
            'state' => 'untriaged',
            'labels_json' => [],
            'body_html_sanitized' => $sanitized,
            'body_text' => $textNormalized,
            'raw_path' => $rawPath,
            'attachments_count' => $attachmentsCount,
            'checksum_sha1' => $this->payload['checksum_sha1'] ?? null,
        ]);

        if (! $messageModel) {
            return;
        }

        // Auto-delete from server if policy is set OR global default is ON
        $globalDelete = \App\Models\Settings\CommonSetting::where('type', 'emailhub')
            ->where('name', 'delete_on_success')
            ->value('value') === '1';

        $shouldDelete = ($account->delete_policy === 'auto_delete') || ($account->delete_policy === 'local_only' && $globalDelete);

        if ($shouldDelete && $messageModel->wasRecentlyCreated) {
            try {
                $client = new ImapClient($account);
                $client->connect();
                $client->deleteByUid($messageModel->imap_uid);
                $client->disconnect();
            } catch (\Throwable $e) {
                Log::warning('Auto-delete failed', ['uid' => $messageModel->imap_uid, 'error' => $e->getMessage()]);
            }
        }

        ProcessInboundRules::dispatch($messageModel->id);
    }

    private function storeMessage(array $identity, array $attributes): ?EmailMessage
    {
        try {
            return EmailMessage::updateOrCreate($identity, $attributes);
        } catch (QueryException $exception) {
            if (! $this->isDuplicateKeyException($exception)) {
                throw $exception;
            }

            $existing = EmailMessage::withTrashed()
                ->where($identity)
                ->first();

            if (! $existing) {
                throw $exception;
            }

            Log::info('Inbound email UID already stored by another worker; recovered duplicate race.', $identity + [
                'email_message_id' => $existing->id,
                'trashed' => $existing->trashed(),
            ]);

            if ($existing->trashed()) {
                return null;
            }

            return $existing;
        }
    }

    private function isDuplicateKeyException(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (string) ($exception->errorInfo[1] ?? '');
        $message = $exception->getMessage();

        return $sqlState === '23000'
            && (
                $driverCode === '1062'
                || str_contains($message, 'uniq_account_mailbox_uid')
                || str_contains($message, 'UNIQUE constraint failed')
            );
    }
}
