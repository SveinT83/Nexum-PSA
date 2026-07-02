<?php

namespace App\Modules\Relationship\Actions;

use App\Modules\Relationship\Models\NexumRelationship;
use App\Modules\Relationship\Support\RelationshipCapability;
use App\Modules\Ticket\Models\TicketMessage;
use Illuminate\Support\Facades\Storage;

class SyncTicketMessageToRelationship
{
    public function __construct(private readonly NexumRelationshipHttpClient $client) {}

    public function handle(int $messageId): void
    {
        $message = TicketMessage::query()
            ->with(['ticket.syncLinks.relationship', 'author', 'fileAttachments'])
            ->find($messageId);

        if (! $message || $message->type !== 'customer_reply' || $message->visibility !== 'public') {
            return;
        }

        if (str_starts_with((string) ($message->metadata['external_source'] ?? ''), 'nexum_relationship:')) {
            return;
        }

        foreach ($message->ticket->syncLinks as $link) {
            $relationship = $link->relationship;

            if (! $relationship?->isActive() || ! $relationship->supports(RelationshipCapability::TICKET_SYNC) || ! $link->remote_id) {
                continue;
            }

            $payload = [
                'source_message_id' => (string) $message->id,
                'source_ticket_key' => $message->ticket->ticket_key,
                'type' => $message->type,
                'visibility' => 'public',
                'subject' => $message->subject,
                'body' => $message->body,
                'author_name' => $message->author?->name,
                'author_email' => $message->author?->email,
                'occurred_at' => $message->created_at?->toISOString(),
                'attachments' => $this->attachments($message, $relationship),
            ];

            $this->client->post(
                $relationship,
                'tickets/'.rawurlencode((string) $link->remote_id).'/messages',
                $payload,
                RelationshipCapability::TICKET_SYNC,
                $link,
                'ticket_message_synced'
            );
        }
    }

    private function attachments(TicketMessage $message, NexumRelationship $relationship): array
    {
        if (! $relationship->supports(RelationshipCapability::ATTACHMENT_SYNC)) {
            return [];
        }

        $policy = $relationship->attachment_policy ?? [];
        $maxBytes = max(1, (int) ($policy['max_mb'] ?? 10)) * 1024 * 1024;
        $allowedTypes = collect($policy['allowed_content_types'] ?? [])->filter()->values();

        return $message->fileAttachments
            ->filter(function ($attachment) use ($maxBytes, $allowedTypes): bool {
                if ($attachment->size_bytes > $maxBytes || ! $attachment->path) {
                    return false;
                }

                if ($allowedTypes->isNotEmpty() && ! $allowedTypes->contains($attachment->content_type)) {
                    return false;
                }

                return Storage::disk($attachment->disk ?: 'local')->exists($attachment->path);
            })
            ->map(fn ($attachment) => [
                'filename' => $attachment->original_filename ?: $attachment->filename,
                'content_type' => $attachment->content_type ?: 'application/octet-stream',
                'size_bytes' => $attachment->size_bytes,
                'checksum_sha1' => $attachment->checksum_sha1,
                'content_base64' => base64_encode(Storage::disk($attachment->disk ?: 'local')->get($attachment->path)),
            ])
            ->values()
            ->all();
    }
}
