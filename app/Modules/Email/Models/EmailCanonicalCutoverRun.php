<?php

namespace App\Modules\Email\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailCanonicalCutoverRun extends Model
{
    public const OPERATION_BACKFILL = 'backfill';

    public const OPERATION_MERGE = 'merge';

    public const OPERATION_AUDIT = 'audit';

    public const OPERATION_MODE = 'mode';

    public const STATUS_PREVIEWED = 'previewed';

    public const STATUS_APPLYING = 'applying';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_FAILED = 'failed';

    public const STATUS_ROLLED_BACK = 'rolled_back';

    protected $table = 'email_canonical_cutover_runs';

    protected $guarded = [];

    protected $casts = [
        'account_scope_json' => 'array',
        'frozen_min_message_id' => 'integer',
        'frozen_max_message_id' => 'integer',
        'item_cap' => 'integer',
        'item_count' => 'integer',
        'applied_count' => 'integer',
        'rolled_back_count' => 'integer',
        'previewed_at' => 'datetime',
        'applied_at' => 'datetime',
        'rolled_back_at' => 'datetime',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function applier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function rollbackActor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rolled_back_by');
    }

    public function correlationRun(): BelongsTo
    {
        return $this->belongsTo(
            EmailCanonicalCorrelationRun::class,
            'source_correlation_run_id',
        );
    }

    public function items(): HasMany
    {
        return $this->hasMany(EmailCanonicalCutoverItem::class, 'email_canonical_cutover_run_id');
    }
}
