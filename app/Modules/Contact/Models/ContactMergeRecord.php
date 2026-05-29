<?php

namespace App\Modules\Contact\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactMergeRecord extends Model
{
    protected $fillable = [
        'source_contact_id',
        'target_contact_id',
        'merged_by',
        'source_snapshot',
        'reason',
        'merged_at',
    ];

    protected $casts = [
        'source_snapshot' => 'array',
        'merged_at' => 'datetime',
    ];

    public function sourceContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'source_contact_id');
    }

    public function targetContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'target_contact_id');
    }

    public function mergedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merged_by');
    }
}
