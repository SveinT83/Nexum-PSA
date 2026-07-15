<?php

namespace App\Modules\DataExchange\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataExchangeImportPreview extends Model
{
    public const STATUS_PREVIEWED = 'previewed';
    public const STATUS_COMMITTED = 'committed';
    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    protected $casts = [
        'mapping' => 'array',
        'rows' => 'array',
        'errors' => 'array',
        'summary' => 'array',
        'committed_at' => 'datetime',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(DataExchangeProfile::class, 'profile_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(DataExchangeRun::class, 'run_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function committer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'committed_by');
    }
}
