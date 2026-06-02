<?php

namespace App\Modules\Email\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmailMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'account' => $this->whenLoaded('account', fn () => [
                'id' => $this->account?->id,
                'address' => $this->account?->address,
                'description' => $this->account?->description,
                'from_name' => $this->account?->from_name,
            ]),
            'mailbox' => $this->mailbox,
            'imap_uid' => $this->imap_uid,
            'message_id' => $this->message_id,
            'subject' => $this->subject,
            'from_name' => $this->from_name,
            'from_email' => $this->from_email,
            'to' => $this->to_json,
            'cc' => $this->cc_json,
            'headers' => $this->when($request->boolean('include_headers'), $this->headers_json),
            'in_reply_to' => $this->in_reply_to,
            'references' => $this->references,
            'received_at' => $this->received_at,
            'size_bytes' => $this->size_bytes,
            'is_oversize' => $this->is_oversize,
            'state' => $this->state,
            'labels' => $this->labels_json,
            'body_text' => $this->body_text,
            'body_html_sanitized' => $this->when($request->boolean('include_html'), $this->body_html_sanitized),
            'attachments_count' => $this->attachments_count,
            'ticket_id' => $this->ticket_id,
            'attachments' => EmailAttachmentResource::collection($this->whenLoaded('attachments')),
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->map(fn ($tag) => [
                'id' => $tag->id,
                'name' => $tag->name,
                'slug' => $tag->slug,
            ])->values()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'links' => [
                'self' => route('api.v1.email.inbox.messages.show', $this->id),
                'spam' => route('api.v1.email.inbox.messages.spam', $this->id),
            ],
        ];
    }
}
