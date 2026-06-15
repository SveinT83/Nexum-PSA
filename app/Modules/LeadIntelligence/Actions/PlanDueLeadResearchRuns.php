<?php

namespace App\Modules\LeadIntelligence\Actions;

use App\Modules\LeadIntelligence\Models\LeadResearchRun;
use App\Modules\LeadIntelligence\Models\LeadSegment;
use Illuminate\Support\Carbon;

class PlanDueLeadResearchRuns
{
    public function handle(?Carbon $now = null): array
    {
        $now ??= now();
        $summary = [
            'created' => 0,
            'skipped' => 0,
            'segments' => [],
        ];

        LeadSegment::query()
            ->where('enabled', true)
            ->where('schedule_enabled', true)
            ->where(function ($query) use ($now): void {
                $query->whereNull('next_run_at')
                    ->orWhere('next_run_at', '<=', $now);
            })
            ->orderBy('id')
            ->each(function (LeadSegment $segment) use ($now, &$summary): void {
                $result = $this->planSegment($segment, $now);
                $summary['segments'][] = $result;

                if ($result['created']) {
                    $summary['created']++;
                } else {
                    $summary['skipped']++;
                }
            });

        return $summary;
    }

    private function planSegment(LeadSegment $segment, Carbon $now): array
    {
        [$periodStart, $periodEnd] = $this->periodWindow($segment, $now);
        $periodRuns = $segment->researchRuns()
            ->where('created_at', '>=', $periodStart)
            ->where('created_at', '<', $periodEnd)
            ->get();
        $tokensUsed = $periodRuns->sum('tokens_used');
        $newLeads = $periodRuns->sum(fn (LeadResearchRun $run): int => $this->newLeadCount($run));

        if ($this->goalReached($segment, $newLeads)) {
            $this->deferToNextPeriod($segment, $periodEnd);

            return $this->result($segment, false, 'target_reached', $newLeads, $tokensUsed);
        }

        if ($this->tokenBudgetReached($segment, $tokensUsed)) {
            $this->deferToNextPeriod($segment, $periodEnd);

            return $this->result($segment, false, 'token_budget_reached', $newLeads, $tokensUsed);
        }

        if ($this->maxRunsReached($segment, $periodRuns->count())) {
            $this->deferToNextPeriod($segment, $periodEnd);

            return $this->result($segment, false, 'max_runs_reached', $newLeads, $tokensUsed);
        }

        $run = LeadResearchRun::query()->create([
            'lead_segment_id' => $segment->id,
            'status' => LeadResearchRun::STATUS_QUEUED,
            'summary_json' => [
                'planner' => 'lead-intelligence:plan-due-runs',
                'execution_engine' => 'ai_led_discovery_worker',
                'goal_prompt' => $segment->description,
                'segment_filters' => [
                    'geography' => $segment->geography_json ?: [],
                    'industries' => $segment->industries_json ?: [],
                    'nace_codes' => $segment->nace_codes_json ?: [],
                    'keywords' => $segment->keywords_json ?: [],
                    'excluded_keywords' => $segment->excluded_keywords_json ?: [],
                    'target_roles' => $segment->target_roles_json ?: [],
                ],
                'schedule' => [
                    'period' => $segment->schedule_period,
                    'period_start' => $periodStart->toDateTimeString(),
                    'period_end' => $periodEnd->toDateTimeString(),
                    'target_new_leads_per_period' => $segment->target_new_leads_per_period,
                    'token_budget_per_period' => $segment->token_budget_per_period,
                    'token_budget_unlimited' => (bool) $segment->token_budget_unlimited,
                    'new_leads_so_far' => $newLeads,
                    'tokens_used_so_far' => $tokensUsed,
                ],
            ],
        ]);

        $segment->forceFill([
            'last_run_at' => $now,
            'next_run_at' => $this->nextRunAfter($segment, $now, $periodEnd),
        ])->save();

        return $this->result($segment, true, 'queued', $newLeads, $tokensUsed, $run->id);
    }

    public function firstRunAt(LeadSegment $segment, ?Carbon $now = null): Carbon
    {
        $now ??= now();
        $candidate = $this->atScheduleTime($now->copy(), $segment);

        if ($candidate->lte($now)) {
            $candidate->addDay();
        }

        return $candidate;
    }

    private function nextRunAfter(LeadSegment $segment, Carbon $now, Carbon $periodEnd): Carbon
    {
        if ($segment->schedule_period === 'weekly' && is_array($segment->schedule_weekdays_json) && $segment->schedule_weekdays_json !== []) {
            $candidate = $this->nextConfiguredWeekday($segment, $now);
        } else {
            $candidate = $this->atScheduleTime($now->copy()->addDays(max(1, (int) $segment->run_interval_days)), $segment);
        }

        if ($candidate->gte($periodEnd)) {
            return $this->atScheduleTime($periodEnd->copy(), $segment);
        }

        return $candidate;
    }

    private function nextConfiguredWeekday(LeadSegment $segment, Carbon $now): Carbon
    {
        $weekdays = array_values(array_filter(array_map('intval', $segment->schedule_weekdays_json ?: [])));

        for ($i = 0; $i <= 14; $i++) {
            $candidate = $this->atScheduleTime($now->copy()->addDays($i), $segment);

            if (in_array($candidate->isoWeekday(), $weekdays, true) && $candidate->gt($now)) {
                return $candidate;
            }
        }

        return $this->atScheduleTime($now->copy()->addWeek(), $segment);
    }

    private function periodWindow(LeadSegment $segment, Carbon $now): array
    {
        return match ($segment->schedule_period) {
            'daily' => [$now->copy()->startOfDay(), $now->copy()->startOfDay()->addDay()],
            'monthly' => [$now->copy()->startOfMonth(), $now->copy()->startOfMonth()->addMonth()],
            default => [$now->copy()->startOfWeek(), $now->copy()->startOfWeek()->addWeek()],
        };
    }

    private function deferToNextPeriod(LeadSegment $segment, Carbon $periodEnd): void
    {
        $segment->forceFill([
            'next_run_at' => $this->atScheduleTime($periodEnd->copy(), $segment),
        ])->save();
    }

    private function atScheduleTime(Carbon $date, LeadSegment $segment): Carbon
    {
        $time = $segment->schedule_time ?: '08:00';
        [$hour, $minute] = array_pad(array_map('intval', explode(':', (string) $time)), 2, 0);

        return $date->setTime($hour, $minute);
    }

    private function goalReached(LeadSegment $segment, int $newLeads): bool
    {
        return $segment->target_new_leads_per_period !== null
            && $segment->target_new_leads_per_period > 0
            && $newLeads >= $segment->target_new_leads_per_period;
    }

    private function tokenBudgetReached(LeadSegment $segment, int $tokensUsed): bool
    {
        if ($segment->token_budget_unlimited || $segment->token_budget_per_period === null) {
            return false;
        }

        return $tokensUsed >= $segment->token_budget_per_period;
    }

    private function maxRunsReached(LeadSegment $segment, int $runs): bool
    {
        return $segment->max_runs_per_period !== null
            && $segment->max_runs_per_period > 0
            && $runs >= $segment->max_runs_per_period;
    }

    private function newLeadCount(LeadResearchRun $run): int
    {
        $summary = (array) $run->summary_json;

        return (int) ($summary['new_leads_created'] ?? $summary['new_leads'] ?? $summary['created_leads'] ?? 0);
    }

    private function result(
        LeadSegment $segment,
        bool $created,
        string $reason,
        int $newLeads,
        int $tokensUsed,
        ?int $runId = null,
    ): array {
        return [
            'segment_id' => $segment->id,
            'segment_name' => $segment->name,
            'created' => $created,
            'reason' => $reason,
            'run_id' => $runId,
            'new_leads_this_period' => $newLeads,
            'tokens_used_this_period' => $tokensUsed,
            'next_run_at' => $segment->fresh()->next_run_at?->toDateTimeString(),
        ];
    }
}
