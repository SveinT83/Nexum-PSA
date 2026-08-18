<?php

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Models\AiAgent;
use App\Modules\Integration\Models\AiChat;
use App\Modules\Integration\Models\AiSystemSetting;
use App\Modules\Integration\Support\AiExecutionContext;
use App\Modules\Integration\Support\AiExecutionTrace;
use App\Modules\Integration\Support\AiModelResult;
use App\Modules\Integration\Support\AiModelUsage;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class AiChatResponder
{
    private const OPENAI_COMPATIBLE_TIMEOUT_SECONDS = 180;

    private const OPENAI_RESPONSES_TIMEOUT_SECONDS = 120;

    public function __construct(
        private AiToolContextBuilder $toolContextBuilder,
        private AiModelExecutor $modelExecutor,
        private AiOutboundPolicyGuard $outboundPolicyGuard,
    ) {}

    /**
     * Generate and persist an assistant reply for the current chat.
     */
    public function respond(AiChat $chat, ?int $pendingMessageId = null): void
    {
        $chat->loadMissing(['agent.provider', 'messages']);
        if ($pendingMessageId && ! $this->pendingMessageStillOpen($chat, $pendingMessageId)) {
            return;
        }

        $agent = $chat->agent;

        if (! $agent) {
            $this->storeAssistantMessage($chat, 'This chat has no AI agent assigned.', $pendingMessageId);

            return;
        }

        try {
            $reply = $this->send(
                $agent,
                $this->messagesForProvider($chat, $agent),
                executionContext: AiExecutionContext::forChat($chat, $pendingMessageId),
            );
            $this->storeAssistantMessage($chat, $reply, $pendingMessageId);
            $agent->provider?->forceFill([
                'is_healthy' => true,
                'last_error' => null,
            ])->save();
        } catch (\Throwable $exception) {
            $agent->provider?->forceFill([
                'is_healthy' => false,
                'last_error' => $exception->getMessage(),
            ])->save();

            $this->storeAssistantMessage($chat, 'AI provider error: '.$exception->getMessage(), $pendingMessageId);
        }
    }

    /**
     * Send a non-streaming chat request to the configured provider.
     */
    public function complete(
        AiAgent $agent,
        array $messages,
        ?int $timeoutSeconds = null,
        ?AiExecutionContext $executionContext = null,
        ?int $absoluteBudgetSeconds = null,
    ): string {
        return $this->send(
            $agent,
            $messages,
            $timeoutSeconds,
            $executionContext,
            $absoluteBudgetSeconds,
        );
    }

    /**
     * Send a non-streaming chat request to the configured provider.
     */
    private function send(
        AiAgent $agent,
        array $messages,
        ?int $timeoutSeconds = null,
        ?AiExecutionContext $executionContext = null,
        ?int $absoluteBudgetSeconds = null,
    ): string {
        $provider = $agent->provider;

        if (! $provider || $provider->status !== 'active') {
            throw new RuntimeException('The selected agent has no active provider.');
        }

        $model = $agent->model ?: $provider->default_model;

        if (! $model) {
            throw new RuntimeException('Select a model for this agent or its provider before chatting.');
        }

        $timeoutSeconds = $timeoutSeconds ?: self::OPENAI_COMPATIBLE_TIMEOUT_SECONDS;
        $absoluteDeadlineNanoseconds = $absoluteBudgetSeconds === null
            ? null
            : hrtime(true) + (max(1, $absoluteBudgetSeconds) * 1_000_000_000);
        $messages = $this->outboundPolicyGuard->prepare($agent, $model, $messages);
        $trace = new AiExecutionTrace($executionContext ?? AiExecutionContext::fallback());

        return match ($provider->provider_key) {
            'openai' => $this->openAiCompatible($agent, $provider->base_url ?: 'https://api.openai.com/v1', $provider->getSecret('api_key'), $model, $messages, $trace, $timeoutSeconds, $this->shouldPreferResponsesEndpoint($model), $absoluteDeadlineNanoseconds),
            'custom_openai_compatible' => $this->openAiCompatible($agent, $provider->base_url ?: 'https://api.openai.com/v1', $provider->getSecret('api_key'), $model, $messages, $trace, $timeoutSeconds, absoluteDeadlineNanoseconds: $absoluteDeadlineNanoseconds),
            'mistral' => $this->openAiCompatible($agent, $provider->base_url ?: 'https://api.mistral.ai/v1', $provider->getSecret('api_key'), $model, $messages, $trace, $timeoutSeconds, absoluteDeadlineNanoseconds: $absoluteDeadlineNanoseconds),
            'openrouter' => $this->openAiCompatible($agent, $provider->base_url ?: 'https://openrouter.ai/api/v1', $provider->getSecret('api_key'), $model, $messages, $trace, $timeoutSeconds, absoluteDeadlineNanoseconds: $absoluteDeadlineNanoseconds),
            'ollama' => $this->ollama($agent, $provider->base_url, $model, $messages, $trace, $timeoutSeconds, $absoluteDeadlineNanoseconds),
            default => throw new RuntimeException('Chat is not wired for '.$provider->provider_key.' yet.'),
        };
    }

    private function openAiCompatible(
        AiAgent $agent,
        ?string $baseUrl,
        ?string $apiKey,
        string $model,
        array $messages,
        AiExecutionTrace $trace,
        int $timeoutSeconds = self::OPENAI_COMPATIBLE_TIMEOUT_SECONDS,
        bool $preferResponsesEndpoint = false,
        ?int $absoluteDeadlineNanoseconds = null,
    ): string {
        if (! $apiKey) {
            throw new RuntimeException('API key is missing for this provider.');
        }

        if ($preferResponsesEndpoint) {
            return $this->openAiCompatibleResponse($agent, $baseUrl, $apiKey, $model, $messages, $trace, min($timeoutSeconds, self::OPENAI_RESPONSES_TIMEOUT_SECONDS), $absoluteDeadlineNanoseconds);
        }

        if ($this->shouldUseCompletionEndpoint($model)) {
            return $this->openAiCompatibleCompletion($agent, $baseUrl, $apiKey, $model, $messages, $trace, $timeoutSeconds, $absoluteDeadlineNanoseconds);
        }

        $attempt = $this->modelExecutor->attempt(
            agent: $agent,
            trace: $trace,
            endpointKind: 'chat_completions',
            requestedModel: $model,
            request: fn (): Response => Http::acceptJson()
                ->withToken($apiKey)
                ->timeout($this->remainingRequestTimeout($timeoutSeconds, $absoluteDeadlineNanoseconds))
                ->post(rtrim((string) $baseUrl, '/').'/chat/completions', [
                    'model' => $model,
                    'messages' => $messages,
                ]),
            normalize: fn (Response $response): AiModelResult => $this->openAiCompatibleResult($response, 'chat_completions'),
        );
        $response = $attempt->response;

        if (! $response->successful()) {
            if ($this->isNotChatModelError($response->body())) {
                return $this->openAiCompatibleCompletion($agent, $baseUrl, $apiKey, $model, $messages, $trace, $timeoutSeconds, $absoluteDeadlineNanoseconds);
            }

            throw new RuntimeException($this->failureMessage($response->status(), $response->body()));
        }

        if (! $attempt->result->successful) {
            throw new RuntimeException('Provider returned an empty response.');
        }

        return (string) $attempt->result->content;
    }

    private function openAiCompatibleCompletion(
        AiAgent $agent,
        ?string $baseUrl,
        string $apiKey,
        string $model,
        array $messages,
        AiExecutionTrace $trace,
        int $timeoutSeconds = self::OPENAI_COMPATIBLE_TIMEOUT_SECONDS,
        ?int $absoluteDeadlineNanoseconds = null,
    ): string {
        $attempt = $this->modelExecutor->attempt(
            agent: $agent,
            trace: $trace,
            endpointKind: 'completions',
            requestedModel: $model,
            request: fn (): Response => Http::acceptJson()
                ->withToken($apiKey)
                ->timeout($this->remainingRequestTimeout($timeoutSeconds, $absoluteDeadlineNanoseconds))
                ->post(rtrim((string) $baseUrl, '/').'/completions', [
                    'model' => $model,
                    'prompt' => $this->completionPrompt($messages),
                    'max_tokens' => 2000,
                ]),
            normalize: fn (Response $response): AiModelResult => $this->openAiCompatibleResult($response, 'completions'),
        );
        $response = $attempt->response;

        if (! $response->successful()) {
            if ($this->isCompletionUnsupportedError($response->body())) {
                return $this->openAiCompatibleResponse($agent, $baseUrl, $apiKey, $model, $messages, $trace, min($timeoutSeconds, self::OPENAI_RESPONSES_TIMEOUT_SECONDS), $absoluteDeadlineNanoseconds);
            }

            throw new RuntimeException($this->failureMessage($response->status(), $response->body()));
        }

        if (! $attempt->result->successful) {
            throw new RuntimeException('Provider returned an empty response.');
        }

        return (string) $attempt->result->content;
    }

    private function openAiCompatibleResponse(
        AiAgent $agent,
        ?string $baseUrl,
        string $apiKey,
        string $model,
        array $messages,
        AiExecutionTrace $trace,
        int $timeoutSeconds = self::OPENAI_RESPONSES_TIMEOUT_SECONDS,
        ?int $absoluteDeadlineNanoseconds = null,
    ): string {
        $attempt = $this->modelExecutor->attempt(
            agent: $agent,
            trace: $trace,
            endpointKind: 'responses',
            requestedModel: $model,
            request: fn (): Response => Http::acceptJson()
                ->withToken($apiKey)
                ->timeout($this->remainingRequestTimeout($timeoutSeconds, $absoluteDeadlineNanoseconds))
                ->post(rtrim((string) $baseUrl, '/').'/responses', [
                    'model' => $model,
                    'input' => $this->completionPrompt($messages),
                    'max_output_tokens' => 1200,
                ]),
            normalize: fn (Response $response): AiModelResult => $this->openAiCompatibleResult($response, 'responses'),
        );
        $response = $attempt->response;

        if (! $response->successful()) {
            throw new RuntimeException($this->failureMessage($response->status(), $response->body()));
        }

        if (! $attempt->result->successful) {
            throw new RuntimeException($this->emptyResponseMessage((array) $response->json()));
        }

        return (string) $attempt->result->content;
    }

    private function ollama(
        AiAgent $agent,
        ?string $baseUrl,
        string $model,
        array $messages,
        AiExecutionTrace $trace,
        int $timeoutSeconds = 120,
        ?int $absoluteDeadlineNanoseconds = null,
    ): string {
        if (! $baseUrl) {
            throw new RuntimeException('Ollama URL is missing for this provider.');
        }

        $attempt = $this->modelExecutor->attempt(
            agent: $agent,
            trace: $trace,
            endpointKind: 'ollama_chat',
            requestedModel: $model,
            request: fn (): Response => Http::acceptJson()
                ->timeout($this->remainingRequestTimeout($timeoutSeconds, $absoluteDeadlineNanoseconds))
                ->post(rtrim($baseUrl, '/').'/api/chat', [
                    'model' => $model,
                    'messages' => $messages,
                    'stream' => false,
                ]),
            normalize: fn (Response $response): AiModelResult => $this->ollamaResult($response),
        );
        $response = $attempt->response;

        if (! $response->successful()) {
            throw new RuntimeException($this->failureMessage($response->status(), $response->body()));
        }

        if (! $attempt->result->successful) {
            throw new RuntimeException('Ollama returned an empty response.');
        }

        return (string) $attempt->result->content;
    }

    private function openAiCompatibleResult(Response $response, string $endpointKind): AiModelResult
    {
        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];
        $usage = AiModelUsage::fromOpenAiCompatible($payload);
        $actualModel = $this->stringValue(data_get($payload, 'model'));
        $providerRequestId = $this->providerRequestId($response, $payload);
        $finishReason = $this->stringValue(
            data_get($payload, 'choices.0.finish_reason')
                ?? data_get($payload, 'incomplete_details.reason')
                ?? data_get($payload, 'status')
        );

        if (! $response->successful()) {
            return AiModelResult::failure(
                usage: $usage,
                actualModel: $actualModel,
                providerRequestId: $providerRequestId,
                finishReason: $finishReason,
                httpStatus: $response->status(),
                errorCategory: 'provider_http_error',
                errorCode: $this->stringValue(data_get($payload, 'error.code')) ?: 'http_'.$response->status(),
            );
        }

        $content = match ($endpointKind) {
            'chat_completions' => data_get($payload, 'choices.0.message.content'),
            'completions' => data_get($payload, 'choices.0.text'),
            'responses' => $this->responseOutputText($payload),
            default => null,
        };

        if (! filled($content)) {
            return AiModelResult::failure(
                usage: $usage,
                actualModel: $actualModel,
                providerRequestId: $providerRequestId,
                finishReason: $finishReason,
                httpStatus: $response->status(),
                errorCategory: 'empty_response',
            );
        }

        return AiModelResult::success(
            content: trim((string) $content),
            usage: $usage,
            actualModel: $actualModel,
            providerRequestId: $providerRequestId,
            finishReason: $finishReason,
            httpStatus: $response->status(),
        );
    }

    private function ollamaResult(Response $response): AiModelResult
    {
        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];
        $usage = AiModelUsage::fromOllama($payload);
        $actualModel = $this->stringValue(data_get($payload, 'model'));
        $finishReason = $this->stringValue(data_get($payload, 'done_reason'));

        if (! $response->successful()) {
            return AiModelResult::failure(
                usage: $usage,
                actualModel: $actualModel,
                providerRequestId: $this->providerRequestId($response, $payload),
                finishReason: $finishReason,
                httpStatus: $response->status(),
                errorCategory: 'provider_http_error',
                errorCode: $this->stringValue(data_get($payload, 'error.code')) ?: 'http_'.$response->status(),
            );
        }

        $content = data_get($payload, 'message.content');

        if (! filled($content)) {
            return AiModelResult::failure(
                usage: $usage,
                actualModel: $actualModel,
                providerRequestId: $this->providerRequestId($response, $payload),
                finishReason: $finishReason,
                httpStatus: $response->status(),
                errorCategory: 'empty_response',
            );
        }

        return AiModelResult::success(
            content: trim((string) $content),
            usage: $usage,
            actualModel: $actualModel,
            providerRequestId: $this->providerRequestId($response, $payload),
            finishReason: $finishReason,
            httpStatus: $response->status(),
        );
    }

    private function providerRequestId(Response $response, array $payload): ?string
    {
        return $this->stringValue(data_get($payload, 'id'))
            ?: $this->stringValue($response->header('x-request-id'))
            ?: $this->stringValue($response->header('openai-request-id'));
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function messagesForProvider(AiChat $chat, AiAgent $agent): array
    {
        $messages = [[
            'role' => 'system',
            'content' => $agent->instructions."\n\n".$this->toolInstructions($agent),
        ]];

        $pageContext = $this->pageContextInstructions($chat);

        if (filled($pageContext)) {
            $messages[] = [
                'role' => 'system',
                'content' => $pageContext,
            ];
        }

        $latestUserMessage = $chat->messages
            ->where('role', 'user')
            ->sortByDesc('created_at')
            ->first();
        $toolContext = $latestUserMessage
            ? $this->toolContextBuilder->build($agent, $latestUserMessage->body, $chat)
            : '';

        if (filled($toolContext)) {
            $messages[] = [
                'role' => 'system',
                'content' => $toolContext,
            ];
        }

        $messageLimit = max(1, AiSystemSetting::current()->context_message_limit);

        $contextMessages = $chat->messages
            ->sortBy('created_at')
            ->filter(fn ($message) => ($message->metadata['status'] ?? null) !== 'pending')
            ->filter(fn ($message) => in_array($message->role, ['user', 'assistant'], true))
            ->take(-$messageLimit);

        foreach ($contextMessages as $message) {
            $messages[] = [
                'role' => $message->role,
                'content' => $message->body,
            ];
        }

        return $messages;
    }

    private function completionPrompt(array $messages): string
    {
        $lines = collect($messages)
            ->map(function (array $message): string {
                $role = Str::headline((string) ($message['role'] ?? 'message'));
                $content = trim((string) ($message['content'] ?? ''));

                return $role.":\n".$content;
            })
            ->filter()
            ->values()
            ->all();

        $lines[] = "Assistant:\n";

        return implode("\n\n", $lines);
    }

    private function shouldUseCompletionEndpoint(string $model): bool
    {
        $model = Str::lower($model);

        return Str::contains($model, [
            'instruct',
            'davinci',
            'babbage',
            'curie',
            'ada',
        ]);
    }

    private function shouldPreferResponsesEndpoint(string $model): bool
    {
        $model = Str::lower($model);

        return Str::startsWith($model, [
            'gpt-5',
            'o1',
            'o3',
            'o4',
            'computer-use',
            'codex',
        ]);
    }

    private function isNotChatModelError(string $body): bool
    {
        $body = str_replace('\/', '/', Str::lower($body));

        return Str::contains($body, 'not a chat model')
            || Str::contains($body, 'v1/completions');
    }

    private function isCompletionUnsupportedError(string $body): bool
    {
        $body = str_replace('\/', '/', Str::lower($body));

        return Str::contains($body, 'not supported in the v1/completions endpoint')
            || Str::contains($body, 'responses api');
    }

    private function responseOutputText(array $payload): string
    {
        $direct = data_get($payload, 'output_text')
            ?: data_get($payload, 'message.content')
            ?: data_get($payload, 'choices.0.message.content');

        if (filled($direct)) {
            return (string) $direct;
        }

        return collect(data_get($payload, 'output', []))
            ->flatMap(function (array $item): array {
                $content = $item['content'] ?? [];

                if (is_string($content)) {
                    return [['text' => $content]];
                }

                return (array) $content;
            })
            ->map(function (mixed $content): ?string {
                if (is_string($content)) {
                    return $content;
                }

                if (! is_array($content)) {
                    return null;
                }

                $text = $content['text'] ?? $content['content'] ?? null;

                if (is_array($text)) {
                    $text = collect($text)
                        ->map(fn (mixed $part): ?string => is_array($part) ? ($part['text'] ?? $part['content'] ?? null) : (is_string($part) ? $part : null))
                        ->filter()
                        ->implode("\n");
                }

                return is_string($text) ? $text : null;
            })
            ->filter()
            ->implode("\n");
    }

    /**
     * Preserve ordinary per-request timeout behavior unless a caller supplies
     * an absolute budget. Budgeted workflows share one monotonic deadline
     * across endpoint fallbacks so each subsequent socket receives only the
     * time that remains.
     */
    private function remainingRequestTimeout(
        int $configuredTimeoutSeconds,
        ?int $absoluteDeadlineNanoseconds,
    ): int|float {
        if ($absoluteDeadlineNanoseconds === null) {
            return $configuredTimeoutSeconds;
        }

        $remainingSeconds = ($absoluteDeadlineNanoseconds - hrtime(true)) / 1_000_000_000;
        if ($remainingSeconds <= 0.05) {
            throw new RuntimeException('AI provider request time budget exhausted.');
        }

        return min($configuredTimeoutSeconds, $remainingSeconds);
    }

    private function emptyResponseMessage(array $payload): string
    {
        $details = array_filter([
            data_get($payload, 'status') ? 'status='.data_get($payload, 'status') : null,
            data_get($payload, 'incomplete_details.reason') ? 'reason='.data_get($payload, 'incomplete_details.reason') : null,
            data_get($payload, 'error.message') ? 'error='.data_get($payload, 'error.message') : null,
        ]);

        return 'Provider returned an empty response'.($details ? ' ('.implode(', ', $details).').' : '.');
    }

    private function pageContextInstructions(AiChat $chat): string
    {
        $context = data_get($chat->metadata, 'page_context', []);

        if (! is_array($context) || $context === []) {
            return '';
        }

        $lines = collect([
            'Current Nexum PSA page context:',
            '- Domain: '.(data_get($context, 'domain') ?: 'unknown'),
            '- Route: '.(data_get($context, 'route_name') ?: 'unknown'),
            '- Title: '.(data_get($context, 'title') ?: 'unknown'),
            '- URL: '.(data_get($context, 'url') ?: 'unknown'),
        ]);

        $record = data_get($context, 'record');

        if (is_array($record) && data_get($record, 'type') === 'ticket') {
            $lines = $lines->merge($this->ticketContextInstructions($record));
        }

        $lines->push('Use this as local context for the conversation. Do not claim that the page data has been changed unless a write tool is explicitly available and executed.');

        return $lines->implode("\n");
    }

    private function ticketContextInstructions(array $ticket): array
    {
        $lines = [
            '',
            'Current ticket context:',
            '- Ticket: '.(data_get($ticket, 'key') ?: 'unknown'),
            '- Subject: '.(data_get($ticket, 'subject') ?: 'unknown'),
            '- Status: '.(data_get($ticket, 'status.name') ?: 'unknown').' ('.(data_get($ticket, 'status.slug') ?: 'unknown').')',
            '- Workflow: '.(data_get($ticket, 'workflow.name') ?: 'none'),
            '- Queue: '.(data_get($ticket, 'queue') ?: 'none'),
            '- Priority: '.(data_get($ticket, 'priority') ?: 'none'),
            '- Client: '.(data_get($ticket, 'client') ?: 'none'),
            '- Contact: '.trim((string) data_get($ticket, 'contact.name').' <'.(string) data_get($ticket, 'contact.email').'>'),
            '- Owner: '.(data_get($ticket, 'owner') ?: 'unassigned'),
            '- Channel: '.(data_get($ticket, 'channel') ?: 'unknown'),
            '- Unread customer activity: '.(data_get($ticket, 'is_unread') ? 'yes' : 'no'),
        ];

        $tags = collect(data_get($ticket, 'tags', []))->filter()->implode(', ');
        if (filled($tags)) {
            $lines[] = '- Tags: '.$tags;
        }

        $description = trim((string) data_get($ticket, 'description'));
        if (filled($description)) {
            $lines[] = '';
            $lines[] = 'Ticket description:';
            $lines[] = Str::limit($description, 3000);
        }

        $messages = collect(data_get($ticket, 'recent_messages', []))->filter(fn ($message) => is_array($message));
        if ($messages->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Recent ticket messages, oldest to newest:';

            foreach ($messages as $message) {
                $author = data_get($message, 'author_name') ?: data_get($message, 'author_type') ?: 'unknown';
                $type = data_get($message, 'type') ?: 'message';
                $visibility = data_get($message, 'visibility') ?: 'unknown';
                $solution = data_get($message, 'is_solution') ? ' solution' : '';
                $intent = data_get($message, 'reply_intent') ? ' intent='.data_get($message, 'reply_intent') : '';
                $body = Str::limit(trim((string) data_get($message, 'body')), 2000);

                $lines[] = '- ['.$type.'/'.$visibility.$solution.$intent.'] '.$author.' at '.(data_get($message, 'created_at') ?: 'unknown').': '.$body;
            }
        }

        $events = collect(data_get($ticket, 'recent_events', []))->filter(fn ($event) => is_array($event));
        if ($events->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Recent ticket events:';

            foreach ($events as $event) {
                $lines[] = '- '.(data_get($event, 'type') ?: 'event').' at '.(data_get($event, 'created_at') ?: 'unknown').': '.Str::limit((string) data_get($event, 'message'), 500);
            }
        }

        return $lines;
    }

    private function toolInstructions(AiAgent $agent): string
    {
        $tools = $agent->allowed_tools ?? [];
        $dataSources = $agent->data_sources ?? [];

        $hasKnowledgeSearch = in_array('knowledge.search', $tools, true) || in_array('search', $tools, true);

        $instructions = [
            'Use available Nexum PSA tools before saying you do not have access.',
            'When tool results are provided as system context, answer from those results instead of telling the user to open the same page.',
            'If the user asks for operational counts, priorities, or recommendations, use read tools first and name the records behind the recommendation.',
            'If a needed read or write tool is not available for this agent, say which tool or scope is missing.',
            'Never claim that data was changed unless a write tool is explicitly available and executed.',
        ];

        if (in_array('knowledge', $dataSources, true) && $hasKnowledgeSearch) {
            $instructions[] = 'Available read tool: knowledge.search.';
        }

        if (in_array('active_tickets', $dataSources, true) && $this->hasTicketReadTool($tools, $agent->allowed_api_scopes ?? [])) {
            $instructions[] = 'Available read tool: tickets.read for ticket counts, ownership, and prioritization.';
        }

        return implode("\n", $instructions);
    }

    private function hasTicketReadTool(array $tools, array $scopes): bool
    {
        return (in_array('records.read', $tools, true) || in_array('read_records', $tools, true))
            && in_array('tickets.read', $scopes, true);
    }

    private function storeAssistantMessage(AiChat $chat, string $body, ?int $pendingMessageId = null): void
    {
        if ($pendingMessageId) {
            $pending = $chat->messages()
                ->whereKey($pendingMessageId)
                ->where('role', 'assistant')
                ->first();

            if ($pending) {
                $pending->forceFill([
                    'body' => $body,
                    'metadata' => ['status' => 'complete'],
                ])->save();
                $chat->forceFill(['last_message_at' => now()])->save();

                return;
            }
        }

        $chat->messages()->create([
            'role' => 'assistant',
            'body' => $body,
            'metadata' => ['status' => 'complete'],
        ]);
        $chat->forceFill(['last_message_at' => now()])->save();
    }

    private function pendingMessageStillOpen(AiChat $chat, int $pendingMessageId): bool
    {
        $pending = $chat->messages()
            ->whereKey($pendingMessageId)
            ->where('role', 'assistant')
            ->first();

        return $pending && ($pending->metadata['status'] ?? null) === 'pending';
    }

    private function failureMessage(int $status, string $body): string
    {
        return 'HTTP '.$status.($body !== '' ? ': '.Str::limit($body, 220) : '');
    }
}
