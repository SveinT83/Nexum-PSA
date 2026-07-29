<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiDataEgressPolicyRevision extends Model
{
    protected $fillable = ['policy_id', 'revision', 'policy_snapshot', 'changed_by', 'change_reason'];

    protected $casts = [
        'policy_snapshot' => 'array',
        'revision' => 'integer',
    ];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(AiDataEgressPolicy::class, 'policy_id');
    }
}
