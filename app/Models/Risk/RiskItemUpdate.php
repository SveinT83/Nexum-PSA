<?php

namespace App\Models\Risk;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * RiskItemUpdate represents a snapshot of an action or state change for a RiskItem.
 */
class RiskItemUpdate extends Model
{
    use HasFactory;

    protected $fillable = [
        'risk_item_id',
        'created_by',
        'note',
        'likelihood',
        'impact',
        'score',
        'status',
    ];

    /**
     * Boot the model.
     * Automatically calculates the score if likelihood and impact are present.
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($model) {
            if ($model->likelihood !== null && $model->impact !== null) {
                $model->score = $model->likelihood * $model->impact;
            }
        });
    }

    /**
     * Get the risk item this update belongs to.
     */
    public function riskItem(): BelongsTo
    {
        return $this->belongsTo(RiskItem::class);
    }

    /**
     * Get the user who created this update.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the Bootstrap badge class for this specific update's score.
     */
    public function getScoreBadgeClassAttribute(): string
    {
        if ($this->score === null) {
            return 'text-bg-light';
        }
        if ($this->score >= 16) {
            return 'text-bg-dark';
        }
        if ($this->score >= 10) {
            return 'text-bg-danger';
        }
        if ($this->score >= 5) {
            return 'text-bg-warning';
        }
        return 'text-bg-success';
    }
}
