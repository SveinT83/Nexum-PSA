<?php

namespace App\Modules\Email\Resources\Api\V1;

use App\Modules\Email\Services\EmailDraftFence;
use App\Modules\Email\Services\EmailSharedDraftService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmailComposerDraftResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'scope' => $this->scope,
            'version' => app(EmailDraftFence::class)->issue($this->resource),
            'collaboration' => $this->when(
                $this->scope === \App\Modules\Email\Models\EmailComposerDraft::SCOPE_SHARED,
                function (): array {
                    $lock = $this->resource->relationLoaded('sharedLock') ? $this->sharedLock : null;
                    $holder = $lock && $lock->relationLoaded('holder') ? $lock->holder : null;

                    return [
                        'content_version' => (int) $this->content_version,
                        'source_version' => app(EmailSharedDraftService::class)->sourceVersion($this->resource),
                        'stale' => $this->stale_at !== null,
                        'stale_reason_code' => $this->stale_reason_code,
                        'stale_at' => $this->stale_at,
                        'shared_at' => $this->shared_at,
                        'last_rebased_at' => $this->last_rebased_at,
                        'lease' => $lock ? [
                            'id' => $lock->public_id,
                            'active' => $lock->isActive(),
                            'fencing_token' => (int) $lock->fencing_token,
                            'content_version' => (int) $lock->content_version,
                            'expires_at' => $lock->lease_expires_at,
                            'holder' => $holder ? [
                                'id' => (int) $holder->id,
                                'name' => (string) $holder->name,
                            ] : null,
                        ] : null,
                    ];
                },
            ),
            'account_id' => (int) $this->email_account_id,
            'source_placement_id' => $this->email_mailbox_placement_id
                ? (int) $this->email_mailbox_placement_id
                : null,
            'mode' => $this->mode,
            'status' => $this->status,
            'to' => $this->to_recipients,
            'cc' => $this->cc_recipients,
            'subject' => $this->subject,
            'body_html' => $this->body_html,
            'body_text' => $this->body_text,
            'provider_draft' => [
                'status' => $this->provider_draft_status,
                'error_code' => $this->provider_draft_error_code,
                'synced_at' => $this->provider_draft_synced_at,
                'deleted_at' => $this->provider_draft_deleted_at,
            ],
            'attachments' => EmailComposerDraftAttachmentResource::collection(
                $this->whenLoaded('attachments'),
            ),
            'last_saved_at' => $this->last_saved_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
