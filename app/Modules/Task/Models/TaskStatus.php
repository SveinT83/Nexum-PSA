<?php

namespace App\Modules\Task\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class TaskStatus extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_default',
        'is_active',
        'is_open',
        'is_in_progress',
        'is_blocked',
        'is_done',
        'is_cancelled',
        'sort_order',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'is_open' => 'boolean',
        'is_in_progress' => 'boolean',
        'is_blocked' => 'boolean',
        'is_done' => 'boolean',
        'is_cancelled' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'status_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    protected static function booted(): void
    {
        static::creating(function (TaskStatus $status): void {
            if (blank($status->slug)) {
                $status->slug = Str::slug($status->name);
            }
        });
    }
}
