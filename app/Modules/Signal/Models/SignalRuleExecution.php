<?php

namespace App\Modules\Signal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SignalRuleExecution extends Model
{
    protected $fillable = [
        'signal_id',
        'signal_rule_id',
        'retry_of_execution_id',
        'attempt',
        'status',
        'actions',
        'results',
        'error',
        'executed_at',
    ];

    protected $casts = [
        'actions' => 'array',
        'results' => 'array',
        'executed_at' => 'datetime',
    ];

    public function signal(): BelongsTo
    {
        return $this->belongsTo(Signal::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(SignalRule::class, 'signal_rule_id');
    }

    public function retryOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retry_of_execution_id');
    }

    public function retries(): HasMany
    {
        return $this->hasMany(self::class, 'retry_of_execution_id');
    }

    public function rootExecution(): self
    {
        return $this->retryOf ?: $this;
    }

    public function hasRetryableActions(): bool
    {
        $root = $this->rootExecution();
        $attempts = collect([$root, ...$root->retries]);
        $successful = $attempts
            ->flatMap(fn (self $attempt) => collect((array) $attempt->results)->values()->map(
                fn (mixed $result, int $index): array => is_array($result)
                    ? ['action_index' => $result['action_index'] ?? $index, ...$result]
                    : ['action_index' => $index, 'status' => 'unknown'],
            ))
            ->filter(fn (array $result): bool => in_array($result['status'] ?? null, ['done', 'queued', 'skipped'], true))
            ->pluck('action_index')
            ->unique();

        return collect(array_keys((array) $root->actions))->diff($successful)->isNotEmpty();
    }
}
