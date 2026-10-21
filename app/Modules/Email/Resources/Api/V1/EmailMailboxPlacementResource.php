<?php

namespace App\Modules\Email\Resources\Api\V1;

use App\Modules\Email\Services\EmailCanonicalContentResolver;
use App\Modules\Email\Services\EmailUnreadForMeResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/** An explicit source-placement projection; canonical identity never replaces mailbox authority. */
final class EmailMailboxPlacementResource extends JsonResource
{
    public function __construct($resource, private readonly bool $includeContent = false)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $placement = $this->resource;
        $placement->loadMissing(['account', 'folder', 'message.attachments']);
        $message = app(EmailCanonicalContentResolver::class)->resolve($placement, $placement->message)->message;
        $data = [
            'id' => (int) $placement->id,
            'account_id' => (int) $placement->account_id,
            'conversation_id' => $placement->email_conversation_id ? (int) $placement->email_conversation_id : null,
            'source_message_id' => (int) $placement->email_message_id,
            'folder' => $placement->folder?->only(['id', 'account_id', 'path', 'name', 'role']),
            'folder_path' => $placement->folder_path,
            'sync_version' => (int) $placement->sync_version,
            'provider_seen' => (bool) $placement->provider_seen,
            'provider_flagged' => (bool) $placement->provider_flagged,
            'provider_draft' => (bool) $placement->provider_draft,
            'unread_for_me' => $message ? app(EmailUnreadForMeResolver::class)->resolve($placement->message, $request->user()) : null,
            'subject' => $message?->subject,
            'from_name' => $message?->from_name,
            'from_email' => $message?->from_email,
            'received_at' => $message?->received_at,
            'preview' => Str::limit((string) $message?->body_text, 240),
            'attachments_count' => $message?->attachments->count() ?? 0,
            'conversation_count' => $placement->getAttribute('mail_conversation_count'),
            'conversation_unread_for_me_count' => $placement->getAttribute('mail_conversation_unread_for_me_count'),
            'links' => [
                'self' => route('api.v1.email.mailbox.placements.show', $placement->id),
                'conversation' => route('api.v1.email.mailbox.placements.conversation', $placement->id),
            ],
        ];
        if ($this->includeContent && $message) {
            $data['message'] = [
                'id' => (int) $placement->email_message_id,
                'message_id' => $message->message_id,
                'in_reply_to' => $message->in_reply_to,
                'references' => $message->references,
                'to' => $message->to_json,
                'cc' => $message->cc_json,
                'body_text' => $message->body_text,
                'body_html_sanitized' => $message->body_html_sanitized,
                'attachments' => $placement->message->attachments->map(fn ($part) => [
                    'id' => (int) $part->id, 'filename' => $part->filename,
                    'content_type' => $part->content_type, 'size_bytes' => (int) $part->size_bytes,
                    'is_inline' => (bool) $part->is_inline, 'cid' => $part->cid,
                    'download_url' => route('api.v1.email.mailbox.attachments.download', [$placement->id, $part->id]),
                ])->values(),
            ];
        }

        return $data;
    }
}
