<?php

namespace App\Modules\Taxonomy\Models;

use App\Modules\Documentation\Models\DocumentationTemplate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Category extends Model
{
    use HasFactory, SoftDeletes;

    public const TYPE_TICKET = 'ticket';

    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'type',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    /**
     * Relationship with Documentation TemplatesManagement.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function templates()
    {
        return $this->hasMany(DocumentationTemplate::class);
    }

    /**
     * Relationship with Services.
     * Note: Assumes 'services' table has a 'category_id' column.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function services()
    {
        // Use full namespace to avoid import issues
        return $this->hasMany(\App\Modules\Commercial\Models\Services\Services::class, 'category_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForTickets(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_TICKET);
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($category) {
            if (empty($category->slug)) {
                $category->slug = Str::slug($category->name);
            }
        });
    }
}
