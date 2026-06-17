<?php

namespace App\Modules\LeadIntelligence\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LeadScanLedger extends Model
{
    protected $table = 'lead_scan_ledger';

    protected $fillable = [
        'domain',
        'org_no',
        'url',
        'last_scanned_at',
        'next_scan_after',
        'last_result_hash',
        'pages_scanned',
        'tokens_used',
        'status',
        'metadata',
    ];

    protected $casts = [
        'last_scanned_at' => 'datetime',
        'next_scan_after' => 'datetime',
        'pages_scanned' => 'integer',
        'tokens_used' => 'integer',
        'metadata' => 'array',
    ];

    public function scopeDue(Builder $query): Builder
    {
        return $query->where(function (Builder $inner): void {
            $inner->whereNull('next_scan_after')
                ->orWhere('next_scan_after', '<=', now());
        });
    }
}

