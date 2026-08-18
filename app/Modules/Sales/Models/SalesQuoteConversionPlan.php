<?php

namespace App\Modules\Sales\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SalesQuoteConversionPlan extends Model
{
    protected $fillable = [
        'quote_version_id',
        'acceptance_snapshot_id',
        'quote_line_id',
        'target_domain',
        'target_type',
        'status',
        'idempotency_key',
        'source_snapshot',
        'accepted_line_snapshot',
        'created_record_type',
        'created_record_id',
        'target_reference',
        'operator_note',
        'processed_at',
        'processed_by',
        'created_by',
    ];

    protected $casts = [
        'source_snapshot' => 'array',
        'accepted_line_snapshot' => 'array',
        'processed_at' => 'datetime',
    ];

    public function quoteVersion(): BelongsTo
    {
        return $this->belongsTo(SalesQuoteVersion::class, 'quote_version_id');
    }

    public function acceptanceSnapshot(): BelongsTo
    {
        return $this->belongsTo(SalesQuoteAcceptanceSnapshot::class, 'acceptance_snapshot_id');
    }

    public function quoteLine(): BelongsTo
    {
        return $this->belongsTo(SalesQuoteLine::class, 'quote_line_id');
    }

    public function createdRecord(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
