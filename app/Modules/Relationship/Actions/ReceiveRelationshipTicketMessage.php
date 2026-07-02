<?php

namespace App\Modules\Relationship\Actions;

use App\Modules\Relationship\Models\NexumRelationship;
use App\Modules\Relationship\Models\NexumSyncLink;
use App\Modules\Relationship\Support\RelationshipCapability;
use App\Modules\Ticket\Actions\SyncExternalTicketMessage;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketAttachment;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReceiveRelationshipTicketMessage
{
    public function __construct(private readonly RecordSyncEvent $events) {}

    public function handle(NexumRelationship $relationship, string $remoteTicketId, array $data): array
    {
        if (! $relationship->supports(RelationshipCapability::TICKET_SYNC)) {
            throw ValidationException::withMessages(['relationship' => 'Ticket sync is not enabled for this relationship.']);
        }

        $link = NexumSyncLink::query()
            ->where('relationship_id', $relationship->id)
            ->where('domain', 'ticket')
            ->where('remote_type', 'ticket')
            ->where('remote_id', $remoteTicketId)
            ->firstOrFail();

        $ticket = Ticket::query()->findOrFail($link->local_id);

        [$message, $created] = app(SyncExternalTicketMessage::class)->handle($ticket, [
            'source' => $relationship->syncSource(),
            'external_id' => (string) $data['source_message_id'],
            'type' => 'customer_reply',
            'visibility' => 'public',
            'subject' => $data['subject'] ?? null,
            'body' => $data['body'],
            'author_name' => $data['author_name'] ?? null,
            'author_email' => $data['author_email'] ?? null,
            'occurred_at' => $data['occurred_at'] ?? null,
            'metadata' => [
                'source_ticket_key' => $data['source_ticket_key'] ?? null,
                'relationship_id' => $relationship->id,
            ],
        ]);

        if ($created) {
            $this->storeAttachments($message, $relationship, $data['attachments'] ?? []);
        }

        $this->events->handle($relationship, [
            'sync_link_id' => $link->id,
            'direction' => 'inbound',
            'capability' => RelationshipCapability::TICKET_SYNC,
            'local_type' => $message::class,
            'local_id' => $message->id,
            'remote_type' => 'ticket_message',
            'remote_id' => (string) $data['source_message_id'],
            'event_type' => 'ticket_message_received',
            'outcome' => 'synced',
        ]);

        return [$message->refresh(), $created];
    }

    private function storeAttachments($message, NexumRelationship $relationship, array $attachments): void
    {
        if (! $relationship->supports(RelationshipCapability::ATTACHMENT_SYNC)) {
            return;
        }

        $policy = $relationship->attachment_policy ?? [];
        $maxBytes = max(1, (int) ($policy['max_mb'] ?? 10)) * 1024 * 1024;
        $allowedTypes = collect($policy['allowed_content_types'] ?? [])->filter()->values();

        foreach ($attachments as $attachment) {
            $content = base64_decode((string) ($attachment['content_base64'] ?? ''), true);

            if ($content === false || strlen($content) > $maxBytes) {
                continue;
            }

            $contentType = (string) ($attachment['content_type'] ?? 'application/octet-stream');

            if ($allowedTypes->isNotEmpty() && ! $allowedTypes->contains($contentType)) {
                continue;
            }

            $filename = $this->safeFilename((string) ($attachment['filename'] ?? 'attachment'));
            $path = 'ticket/attachments/'.$message->ticket_id.'/'.$message->id.'/'.Str::uuid().'-'.$filename;

            Storage::disk('local')->put($path, $content);

            TicketAttachment::query()->create([
                'ticket_id' => $message->ticket_id,
                'ticket_message_id' => $message->id,
                'uploaded_by' => null,
                'source' => 'nexum_relationship',
                'filename' => $filename,
                'original_filename' => $filename,
                'content_type' => $contentType,
                'size_bytes' => strlen($content),
                'disk' => 'local',
                'path' => $path,
                'checksum_sha1' => sha1($content),
            ]);
        }
    }

    private function safeFilename(string $filename): string
    {
        $filename = trim(str_replace(['/', '\\'], '-', $filename));

        return $filename !== '' ? $filename : 'attachment';
    }
}
