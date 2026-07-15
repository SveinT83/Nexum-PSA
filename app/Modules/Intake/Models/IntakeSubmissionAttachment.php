<?php

namespace App\Modules\Intake\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntakeSubmissionAttachment extends Model
{
    protected $fillable = [
        'intake_submission_id',
        'intake_form_field_id',
        'disk',
        'path',
        'filename',
        'original_filename',
        'content_type',
        'size_bytes',
        'checksum_sha1',
        'metadata',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'metadata' => 'array',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(IntakeSubmission::class, 'intake_submission_id');
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(IntakeFormField::class, 'intake_form_field_id');
    }
}
