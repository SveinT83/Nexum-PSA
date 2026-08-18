<?php

namespace App\Modules\Email\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailCanonicalCorrelationCandidate extends Model
{
    public const CLASS_STRONG = 'strong_candidate';

    public const CLASS_POSSIBLE = 'possible_duplicate';

    public const CLASS_AMBIGUOUS = 'ambiguous_missing_evidence';

    public const CLASS_DIFFERENT = 'different';

    public const CLASS_OVERSIZED = 'ambiguous_oversized_group';

    public const REVIEW_UNREVIEWED = 'unreviewed';

    public const REVIEW_CONFIRMED = 'confirmed_candidate';

    public const REVIEW_KEEP_SEPARATE = 'keep_separate';

    public const REVIEW_MORE_EVIDENCE = 'needs_more_evidence';

    protected $table = 'email_canonical_correlation_candidates';

    protected $guarded = [];

    protected $casts = [
        'reason_codes_json' => 'array',
        'left_email_message_id' => 'integer',
        'right_email_message_id' => 'integer',
        'left_email_account_id' => 'integer',
        'right_email_account_id' => 'integer',
        'group_size' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(
            EmailCanonicalCorrelationRun::class,
            'email_canonical_correlation_run_id',
        );
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(
            EmailCanonicalCorrelationInspection::class,
            'email_canonical_correlation_candidate_id',
        );
    }
}
