<?php

namespace App\Modules\Email\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class EmailComposerDraftAttachment extends Model
{
    protected $table = 'email_composer_draft_attachments';

    protected $fillable = [
        'public_id',
        'email_composer_draft_id',
        'draft_generation_id',
        'user_id',
        'position',
        'filename',
        'content_type',
        'size_bytes',
        'disk',
        'path',
        'checksum_sha1',
    ];

    protected $casts = [
        'position' => 'integer',
        'size_bytes' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $attachment): void {
            $attachment->public_id = $attachment->public_id ?: (string) Str::uuid();

            if (! $attachment->draft_generation_id && $attachment->email_composer_draft_id) {
                $attachment->draft_generation_id = EmailComposerDraft::query()
                    ->whereKey($attachment->email_composer_draft_id)
                    ->value('generation_id');
            }
        });
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(EmailComposerDraft::class, 'email_composer_draft_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
