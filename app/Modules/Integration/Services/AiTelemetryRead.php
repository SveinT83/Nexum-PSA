<?php

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Models\AiModelUsageEvent;
use App\Modules\Integration\Support\AiCostEvidence;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** UI and API share the same filtered attempt ledger and exact per-currency subtotals. */
final class AiTelemetryRead
{
    public function query(Request $request): Builder
    {
        $filters = $request->validate([
            'domain' => ['nullable', 'string', 'max:80'],
            'feature' => ['nullable', 'string', 'max:120'],
            'execution_id' => ['nullable', 'uuid'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = AiModelUsageEvent::query();
        foreach (['domain' => 'domain', 'feature' => 'feature_key', 'execution_id' => 'execution_id'] as $key => $column) {
            if (filled($filters[$key] ?? null)) {
                $query->where($column, $filters[$key]);
            }
        }

        return $query;
    }

    public function event(AiModelUsageEvent $event): array
    {
        return [
            'id' => $event->id, 'execution_id' => $event->execution_id,
            'attempt_number' => $event->attempt_number, 'status' => $event->status,
            'domain' => $event->domain, 'feature' => $event->feature_key,
            'provider_id' => $event->ai_provider_id, 'agent_id' => $event->ai_agent_id,
            'requested_model' => $event->requested_model, 'actual_model' => $event->actual_model,
            'created_at' => $event->created_at?->toIso8601String(),
            'started_at' => $event->started_at?->toIso8601String(),
            'finished_at' => $event->finished_at?->toIso8601String(),
            'duration_ms' => $event->duration_ms, 'endpoint' => $event->endpoint_kind,
            'http_status' => $event->http_status,
            'failure_category' => in_array($event->error_category,
                ['provider_http_error', 'empty_response', 'transport_error', 'response_parse_error'], true)
                ? $event->error_category : ($event->status === 'failed' ? 'unknown' : null),
            'tokens' => ['input' => $event->input_tokens, 'output' => $event->output_tokens,
                'total' => $event->total_tokens, 'cached_input' => $event->cached_input_tokens,
                'cache_write' => $event->cache_write_tokens, 'reasoning' => $event->reasoning_tokens,
                'audio_input' => $event->audio_input_tokens, 'audio_output' => $event->audio_output_tokens],
            'cost' => AiCostEvidence::forEvent($event),
        ];
    }

    public function summary(Builder $query): array
    {
        $summary = $this->emptySummary();
        $domains = [];
        // Stream one persisted attempt once; monetary addition never passes through SQL SUM or float.
        foreach ((clone $query)->reorder()->orderBy('id')->cursor() as $event) {
            $this->add($summary, $event);
            $domain = $event->domain ?? 'unknown';
            $domains[$domain] ??= $this->emptySummary();
            $this->add($domains[$domain], $event);
        }
        $this->completeSummary($summary);
        foreach ($domains as &$domain) {
            $this->completeSummary($domain);
        }
        unset($domain);
        $summary['executions'] = (clone $query)->distinct()->count('execution_id');
        $summary['by_domain'] = $domains;

        return $summary;
    }

    public function apiSummary(Builder $query): array
    {
        $summary = $this->summary($query);
        $summary['currencies'] = (object) $summary['currencies'];
        foreach ($summary['by_domain'] as &$domain) {
            $domain['currencies'] = (object) $domain['currencies'];
        }
        unset($domain);
        $summary['by_domain'] = (object) $summary['by_domain'];

        return $summary;
    }

    private function completeSummary(array &$summary): void
    {
        $sources = array_keys(array_filter($summary['source_counts']));
        $summary['source_state'] = count($sources) > 1 ? 'mixed' : ($sources[0] ?? 'unknown');
        $known = $summary['attempts'] - $summary['unknown_cost_attempts'] - $summary['unknown_currency_attempts'];
        $summary['cost_state'] = $known === 0 ? 'unknown' : ($summary['complete'] ? 'known' : 'partial');
        $summary['currency_state'] = count($summary['currencies']) > 1 ? 'mixed'
            : (count($summary['currencies']) === 1 ? 'single' : 'unknown');
        if ($summary['attempts'] === 0) {
            $summary['complete'] = false;
        }
    }

    private function emptySummary(): array
    {
        return ['attempts' => 0, 'known_token_subtotal' => '0', 'unknown_token_attempts' => 0,
            'unknown_cost_attempts' => 0, 'unknown_currency_attempts' => 0, 'currencies' => [],
            'source_counts' => ['provider_reported' => 0, 'estimated' => 0, 'unknown' => 0],
            'complete' => true];
    }

    private function add(array &$summary, AiModelUsageEvent $event): void
    {
        $summary['attempts']++;
        if ($event->total_tokens === null) {
            $summary['unknown_token_attempts']++;
        } else {
            $summary['known_token_subtotal'] = (string) BigDecimal::of($summary['known_token_subtotal'])->plus($event->total_tokens);
        }
        $cost = AiCostEvidence::forEvent($event);
        $summary['source_counts'][$cost['source']]++;
        if ($cost['state'] !== 'known') {
            $key = $cost['state'] === 'unknown_currency' ? 'unknown_currency_attempts' : 'unknown_cost_attempts';
            $summary[$key]++;
            $summary['complete'] = false;

            return;
        }
        $bucket = $summary['currencies'][$cost['currency']] ?? [
            'known_subtotal' => '0.000000000000', 'provider_reported_subtotal' => '0.000000000000',
            'estimated_subtotal' => '0.000000000000', 'attempts' => 0];
        $bucket['known_subtotal'] = (string) BigDecimal::of($bucket['known_subtotal'])->plus($cost['amount']);
        $key = $cost['source'].'_subtotal';
        $bucket[$key] = (string) BigDecimal::of($bucket[$key])->plus($cost['amount']);
        $bucket['attempts']++;
        $summary['currencies'][$cost['currency']] = $bucket;
    }
}
