<?php

namespace App\Modules\Integration\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class RmmAlertRule extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'rule_key',
        'name',
        'description',
        'is_active',
        'priority',
        'stop_processing',
        'conditions',
        'actions',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'priority' => 'integer',
        'stop_processing' => 'boolean',
        'revision' => 'integer',
        'conditions' => 'array',
        'actions' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (RmmAlertRule $rule): void {
            $rule->rule_key ??= (string) Str::uuid();
            $rule->revision ??= 1;
        });

        static::updating(function (RmmAlertRule $rule): void {
            $rule->revision = ((int) $rule->getOriginal('revision')) + 1;
        });
    }

    public function executions(): HasMany
    {
        return $this->hasMany(RmmAlertRuleExecution::class);
    }

    public function latestExecution(): HasOne
    {
        return $this->hasOne(RmmAlertRuleExecution::class)->latestOfMany();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
