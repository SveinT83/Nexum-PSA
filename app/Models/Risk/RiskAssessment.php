<?php

namespace App\Models\Risk;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\WorkContext\Models\WorkContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * RiskAssessment represents a container for a risk analysis.
 * It can belong to a specific client or be internal (client_id is null).
 */
class RiskAssessment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'client_id',
        'work_context_id',
        'title',
        'description',
        'status',
        'approved_at',
        'approved_by',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
    ];

    /**
     * Get the total risk score for the assessment.
     */
    public function getTotalScoreAttribute(): int
    {
        return $this->items->sum('score');
    }

    /**
     * Get the maximum possible risk score for the assessment.
     * Each item has a maximum score of 25 (5x5).
     */
    public function getMaxPossibleScoreAttribute(): int
    {
        return $this->items->count() * 25;
    }

    /**
     * Get the risk percentage relative to the maximum possible score.
     */
    public function getRiskPercentageAttribute(): float
    {
        $max = $this->max_possible_score;
        if ($max === 0) {
            return 0;
        }
        return round(($this->total_score / $max) * 100, 1);
    }

    /**
     * Get the highest individual risk item (by score).
     */
    public function getHighestRiskItemAttribute(): ?RiskItem
    {
        return $this->items()->orderByDesc('score')->first();
    }

    /**
     * Get the average risk score for the assessment to determine its severity.
     */
    public function getScoreBadgeClassAttribute(): string
    {
        $count = $this->items->count();
        if ($count === 0) {
            return 'text-bg-light';
        }

        $averageScore = $this->items->avg('score');

        if ($averageScore >= 16) {
            return 'text-bg-dark'; // Critical
        }
        if ($averageScore >= 10) {
            return 'text-bg-danger'; // High
        }
        if ($averageScore >= 5) {
            return 'text-bg-warning'; // Medium
        }
        return 'text-bg-success'; // Low
    }


    /**
     * Check if the assessment is ready for approval.
     * All associated risk items must be either 'mitigated' or 'accepted'.
     */
    public function getIsApprovableAttribute(): bool
    {
        if ($this->items->count() === 0) {
            return false;
        }

        return $this->items->where('status', 'open')->count() === 0;
    }

    /**
     * Get the user who approved the assessment.
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the client that this risk assessment belongs to.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function workContext(): BelongsTo
    {
        return $this->belongsTo(WorkContext::class, 'work_context_id');
    }

    /**
     * Get the risk items associated with this assessment.
     */
    public function items(): HasMany
    {
        return $this->hasMany(RiskItem::class);
    }
}
