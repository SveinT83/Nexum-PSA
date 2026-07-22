<?php

namespace App\Models\Clients;

use App\Models\System\Integrations\ClientRmmLink;
use App\Models\Tech\Work\Assets\Asset;
use App\Modules\Task\Models\Task;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Client extends Model
{
    use HasFactory;

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    protected $fillable = [
        'name',
        'client_number',
        'org_no',
        'client_format_id',
        'website',
        'sales_category_id',
        'lead_temperature',
        'billing_email',
        'notes',
        'active',
    ];

    public function casts(): array
    {
        return [
            'active' => 'boolean',
            'lead_temperature' => 'integer',
        ];
    }

    /**
     * Get the contracts associated with this client.
     */
    public function contracts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Modules\Commercial\Models\Contracts\Contracts::class);
    }

    public function sites(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\Clients\ClientSite::class);
    }

    public function cloudFactoryLink()
    {
        return $this->hasOne(\App\Modules\Integration\Models\CloudFactory\ClientLink::class);
    }

    public function cloudFactorySubscriptions()
    {
        return $this->hasMany(\App\Modules\Integration\Models\CloudFactory\Subscription::class);
    }

    /**
     * Get all RMM links for the client.
     */
    public function rmmLinks()
    {
        return $this->morphMany(ClientRmmLink::class, 'linkable');
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

    public function salesCategory(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Modules\Taxonomy\Models\Category::class, 'sales_category_id');
    }

    public function clientFormat(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ClientFormat::class);
    }

    public function tags(): \Illuminate\Database\Eloquent\Relations\MorphToMany
    {
        return $this->morphToMany(\App\Modules\Taxonomy\Models\Tag::class, 'taggable', 'taggables')
            ->withPivot('module')
            ->withTimestamps();
    }

    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'owner');
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

        return (int) $this->riskAssessments->sum(function ($assessment) {
            return $assessment->total_score;
        });
    }

    /**
     * Get the top 3 highest-scoring risks for this client across all their assessments.
     * This provides a quick overview of the most critical areas needing attention.
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
