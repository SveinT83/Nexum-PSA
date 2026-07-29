<?php

namespace App\Modules\Integration\Models;

use App\Models\Core\User;
use App\Modules\WorkContext\Models\WorkContext;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiModelUsageEvent extends Model
{
    use HasFactory;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'execution_id',
        'attempt_number',
        'ai_provider_id',
        'ai_agent_id',
        'actor_user_id',
        'work_context_id',
        'subject_type',
        'subject_id',
        'ai_chat_id',
        'ai_chat_message_id',
        'feature_key',
        'operation_key',
        'domain',
        'billing_classification',
        'correlation_id',
        'requested_model',
        'actual_model',
        'endpoint_kind',
        'provider_request_id',
        'started_at',
        'finished_at',
        'duration_ms',
        'status',
        'http_status',
        'finish_reason',
        'error_category',
        'error_code',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'cached_input_tokens',
        'cache_write_tokens',
        'reasoning_tokens',
        'audio_input_tokens',
        'audio_output_tokens',
        'usage_source',
        'provider_reported_cost',
        'cost_currency',
        'provider_timing',
        'non_token_usage',
        'provider_usage',
    ];

    protected $casts = [
        'attempt_number' => 'integer',
        'actor_user_id' => 'integer',
        'work_context_id' => 'integer',
        'ai_agent_id' => 'integer',
        'ai_chat_id' => 'integer',
        'ai_chat_message_id' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'duration_ms' => 'integer',
        'http_status' => 'integer',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'total_tokens' => 'integer',
        'cached_input_tokens' => 'integer',
        'cache_write_tokens' => 'integer',
        'reasoning_tokens' => 'integer',
        'audio_input_tokens' => 'integer',
        'audio_output_tokens' => 'integer',
        'provider_reported_cost' => 'decimal:12',
        'provider_timing' => 'array',
        'non_token_usage' => 'array',
        'provider_usage' => 'array',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(AiAgent::class, 'ai_agent_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function workContext(): BelongsTo
    {
        return $this->belongsTo(WorkContext::class, 'work_context_id');
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(AiChat::class, 'ai_chat_id');
    }

    public function chatMessage(): BelongsTo
    {
        return $this->belongsTo(AiChatMessage::class, 'ai_chat_message_id');
    }
}
