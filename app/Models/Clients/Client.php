<?php

namespace App\Models\Clients;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'client_number',
        'org_no',
        'billing_email',
        'notes',
        'active',
    ];

    public function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    /**
     * Get the contracts associated with this client.
     */
    public function contracts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\CS\Contracts\Contracts::class);
    }

    public function sites(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\Clients\ClientSite::class);
    }

    public function contacts(): \Illuminate\Database\Eloquent\Relations\HasManyThrough
    {
        return $this->hasManyThrough(
            \App\Models\Clients\ClientUser::class,
            \App\Models\Clients\ClientSite::class,
            'client_id',      // Foreign key on client_sites table...
            'client_site_id'  // Foreign key on client_users table...
        );
    }

    /**
     * Get the risk assessments associated with this client.
     */
    public function riskAssessments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\Risk\RiskAssessment::class);
    }

    /**
     * Get the aggregated risk score for the client based on all its assessments.
     * We calculate this by summing up the total score of all linked risk assessments.
     */
    public function getRiskScoreAttribute(): ?int
    {
        if ($this->riskAssessments->count() === 0) {
            return null;
        }

        return (int) $this->riskAssessments->sum(function($assessment) {
            return $assessment->total_score;
        });
    }

    /**
     * Get the top 3 highest-scoring risks for this client across all their assessments.
     * This provides a quick overview of the most critical areas needing attention.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getTopRisksAttribute(): \Illuminate\Database\Eloquent\Collection
    {
        // Fetch all risk items belonging to any of the client's assessments
        // sorted by their individual risk score (likelihood * impact) in descending order.
        return \App\Models\Risk\RiskItem::whereIn('risk_assessment_id', $this->riskAssessments->pluck('id'))
            ->orderByDesc('score')
            ->limit(3)
            ->get();
    }

    /**
     * Get the contextual badge class for the client's risk score.
     * We use the average of the assessment averages to determine the overall severity level.
     */
    public function getRiskScoreBadgeClassAttribute(): string
    {
        $assessments = $this->riskAssessments;
        if ($assessments->count() === 0) {
            return 'text-bg-light';
        }

        // Sum up the average scores of all assessments and find the overall average
        $totalItems = 0;
        $sumOfScores = 0;

        foreach ($assessments as $assessment) {
            $sumOfScores += $assessment->items->sum('score');
            $totalItems += $assessment->items->count();
        }

        if ($totalItems === 0) {
            return 'text-bg-light';
        }

        $overallAverage = $sumOfScores / $totalItems;

        if ($overallAverage >= 16) {
            return 'text-bg-dark'; // Critical
        }
        if ($overallAverage >= 10) {
            return 'text-bg-danger'; // High
        }
        if ($overallAverage >= 5) {
            return 'text-bg-warning'; // Medium
        }
        return 'text-bg-success'; // Low
    }
}
