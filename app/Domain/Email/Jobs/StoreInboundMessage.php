<?php

namespace App\Domain\Email\Jobs;

use App\Domain\Email\Models\EmailMessage;
use App\Domain\Email\Models\EmailAccount;
use App\Domain\Email\Services\ImapClient;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Domain\Email\Services\BodyNormalizer;
use App\Domain\Email\Services\HtmlSanitizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
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

        EmailMessage::updateOrCreate(
            [
                'account_id' => $this->payload['account_id'],
                'mailbox' => 'INBOX',
                'imap_uid' => $this->payload['imap_uid'],
            ],
            [
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
            ]
        );

        // TODO: Dispatch ProcessInboundRules job.
    }
}
