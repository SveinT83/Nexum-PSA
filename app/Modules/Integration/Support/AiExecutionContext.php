<?php

namespace App\Modules\Integration\Support;

use App\Modules\Integration\Models\AiChat;
use Illuminate\Support\Str;

final class AiExecutionContext
{
    public function __construct(
        public readonly string $executionId,
        public readonly string $featureKey,
        public readonly string $operationKey,
        public readonly string $domain,
        public readonly string $billingClassification = 'internal',
        public readonly ?int $actorUserId = null,
        public readonly ?int $workContextId = null,
        public readonly ?string $subjectType = null,
        public readonly ?string $subjectId = null,
        public readonly ?int $aiChatId = null,
        public readonly ?int $aiChatMessageId = null,
        public readonly ?string $correlationId = null,
    ) {}

    /**
     * Temporary context for complete() callers until the call-path coverage slice.
     */
    public static function fallback(): self
    {
        return new self(
            executionId: (string) Str::uuid(),
            featureKey: 'integration.ai_completion',
            operationKey: 'complete',
            domain: 'integration',
        );
    }

    /**
     * Build the context already available to the conversational responder.
     */
    public static function forChat(AiChat $chat, ?int $pendingMessageId = null): self
    {
        $metadata = $chat->metadata ?? [];
        $source = data_get($metadata, 'source');
        $record = data_get($metadata, 'page_context.record');
        $domain = self::stringValue(data_get($metadata, 'page_context.domain')) ?: 'integration';
        $subjectType = is_array($record) ? self::stringValue($record['type'] ?? null) : null;
        $subjectId = is_array($record)
            ? self::stringValue($record['id'] ?? $record['key'] ?? null)
            : null;
        $workContextId = data_get($metadata, 'work_context_id')
            ?? data_get($metadata, 'page_context.work_context_id');

        return new self(
            executionId: (string) Str::uuid(),
            featureKey: $source === 'rightbar' ? 'integration.context_chat' : 'integration.ai_chat',
            operationKey: 'respond',
            domain: $domain,
            actorUserId: $chat->user_id ? (int) $chat->user_id : null,
            workContextId: is_numeric($workContextId) ? (int) $workContextId : null,
            subjectType: $subjectType,
            subjectId: $subjectId,
            aiChatId: $chat->id,
            aiChatMessageId: $pendingMessageId,
            correlationId: self::stringValue(data_get($metadata, 'correlation_id')),
        );
    }

    private static function stringValue(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
