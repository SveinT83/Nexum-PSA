<?php

namespace App\Modules\Intake\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntakeSubmissionEvent extends Model
{
    protected $fillable = [
        'intake_submission_id',
        'actor_id',
        'type',
        'message',
        'before',
        'after',
        'metadata',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'metadata' => 'array',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(IntakeSubmission::class, 'intake_submission_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
