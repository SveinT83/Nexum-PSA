<?php

namespace App\Modules\Email\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailCanonicalCorrelationInspection extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'email_canonical_correlation_inspections';

    protected $guarded = [];

    protected $casts = [
        'inspected_by' => 'integer',
        'inspected_at' => 'datetime',
    ];

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(
            EmailCanonicalCorrelationCandidate::class,
            'email_canonical_correlation_candidate_id',
        );
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }
}
