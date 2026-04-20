<?php

namespace App\Models\Risk;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * RiskItem represents an individual risk within an assessment.
 * It includes scoring (likelihood and impact) and flexibility for linking to other entities.
 */
class RiskItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'risk_assessment_id',
        'category_id',
        'title',
        'description',
        'recommended_actions',
        'conclusion',
        'likelihood',
        'impact',
        'score',
        'status',
        'next_review_at',
    ];

    protected $casts = [
        'next_review_at' => 'date',
    ];

    /**
     * Boot the model.
     * Automatically calculates the score on saving if likelihood or impact are changed.
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($model) {
            $model->score = $model->likelihood * $model->impact;
        });
    }

    /**
     * Get the Bootstrap badge class based on the risk score.
     */
    public function getScoreBadgeClassAttribute(): string
    {
        if ($this->score >= 16) {
            return 'text-bg-dark'; // Critical
        }
        if ($this->score >= 10) {
            return 'text-bg-danger'; // High
        }
        if ($this->score >= 5) {
            return 'text-bg-warning'; // Medium
        }
        return 'text-bg-success'; // Low
    }

    /**
     * Get the latest update for this risk item.
     */
    public function latestUpdate(): BelongsTo
    {
        return $this->belongsTo(RiskItemUpdate::class, 'id', 'risk_item_id')->latestOfMany();
    }

    /**
     * Get all updates for this risk item, sorted by creation date.
     */
    public function updates(): HasMany
    {
        return $this->hasMany(RiskItemUpdate::class)->orderBy('created_at', 'asc');
    }

    /**
     * Get the original (first) update for this risk item.
     */
    public function getOriginalStateAttribute(): ?RiskItemUpdate
    {
        return $this->updates()->first();
    }

    /**
     * Get the category that this risk item belongs to.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(\App\Models\System\Category::class);
    }

    /**
     * Get the assessment that this risk item belongs to.
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(RiskAssessment::class, 'risk_assessment_id');
    }

    /**
     * Get the links associated with this risk item.
     * These links connect the risk item to other entities (e.g., documents, assets).
     */
    public function links(): HasMany
    {
        return $this->hasMany(RiskItemLink::class);
    }
}
