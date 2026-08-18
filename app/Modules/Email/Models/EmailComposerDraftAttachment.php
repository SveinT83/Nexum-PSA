<?php

namespace App\Modules\Email\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailComposerDraftAttachment extends Model
{
    protected $table = 'email_composer_draft_attachments';

    protected $fillable = [
        'email_composer_draft_id',
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

    public function draft(): BelongsTo
    {
        return $this->belongsTo(EmailComposerDraft::class, 'email_composer_draft_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
