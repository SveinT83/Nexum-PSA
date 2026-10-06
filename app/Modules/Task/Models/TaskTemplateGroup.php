<?php

namespace App\Modules\Task\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class TaskTemplateGroup extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'owner_type',
        'is_active',
        'settings',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'settings' => 'array',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(TaskTemplateItem::class, 'template_group_id')
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function allItems(): HasMany
    {
        return $this->hasMany(TaskTemplateItem::class, 'template_group_id')->orderBy('sort_order')->orderBy('id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(TaskTemplateRun::class, 'template_group_id');
    }

    public function recurringTemplates(): HasMany
    {
        return $this->hasMany(TaskRecurringTemplate::class, 'template_group_id');
    }

    protected static function booted(): void
    {
        static::creating(function (TaskTemplateGroup $group): void {
            if (blank($group->slug)) {
                $group->slug = Str::slug($group->name);
            }
        });
    }
}
