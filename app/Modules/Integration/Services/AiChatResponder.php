<?php

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Models\AiAgent;
use App\Modules\Integration\Models\AiChat;
use App\Modules\Integration\Models\AiSystemSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class AiChatResponder
{
    private const OPENAI_COMPATIBLE_TIMEOUT_SECONDS = 180;
    private const OPENAI_RESPONSES_TIMEOUT_SECONDS = 120;

    public function __construct(private AiToolContextBuilder $toolContextBuilder)
    {
    }

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
            $reply = $this->send($agent, $this->messagesForProvider($chat, $agent));
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
    public function complete(AiAgent $agent, array $messages, ?int $timeoutSeconds = null): string
    {
        return $this->send($agent, $messages, $timeoutSeconds);
    }

    /**
     * Send a non-streaming chat request to the configured provider.
     */
    private function send(AiAgent $agent, array $messages, ?int $timeoutSeconds = null): string
    {
        $provider = $agent->provider;

        if (! $provider || $provider->status !== 'active') {
            throw new RuntimeException('The selected agent has no active provider.');
        }

        $model = $agent->model ?: $provider->default_model;

        if (! $model) {
            throw new RuntimeException('Select a model for this agent or its provider before chatting.');
        }

        $timeoutSeconds = $timeoutSeconds ?: self::OPENAI_COMPATIBLE_TIMEOUT_SECONDS;

        return match ($provider->provider_key) {
            'openai' => $this->openAiCompatible($provider->base_url ?: 'https://api.openai.com/v1', $provider->getSecret('api_key'), $model, $messages, $timeoutSeconds, $this->shouldPreferResponsesEndpoint($model)),
            'custom_openai_compatible' => $this->openAiCompatible($provider->base_url ?: 'https://api.openai.com/v1', $provider->getSecret('api_key'), $model, $messages, $timeoutSeconds),
            'mistral' => $this->openAiCompatible($provider->base_url ?: 'https://api.mistral.ai/v1', $provider->getSecret('api_key'), $model, $messages, $timeoutSeconds),
            'openrouter' => $this->openAiCompatible($provider->base_url ?: 'https://openrouter.ai/api/v1', $provider->getSecret('api_key'), $model, $messages, $timeoutSeconds),
            'ollama' => $this->ollama($provider->base_url, $model, $messages, $timeoutSeconds),
            default => throw new RuntimeException('Chat is not wired for '.$provider->provider_key.' yet.'),
        };
    }

    private function openAiCompatible(
        ?string $baseUrl,
        ?string $apiKey,
        string $model,
        array $messages,
        int $timeoutSeconds = self::OPENAI_COMPATIBLE_TIMEOUT_SECONDS,
        bool $preferResponsesEndpoint = false,
    ): string
    {
        if (! $apiKey) {
            throw new RuntimeException('API key is missing for this provider.');
        }

        if ($preferResponsesEndpoint) {
            return $this->openAiCompatibleResponse($baseUrl, $apiKey, $model, $messages, min($timeoutSeconds, self::OPENAI_RESPONSES_TIMEOUT_SECONDS));
        }

        if ($this->shouldUseCompletionEndpoint($model)) {
            return $this->openAiCompatibleCompletion($baseUrl, $apiKey, $model, $messages, $timeoutSeconds);
        }

        $response = Http::acceptJson()
            ->withToken($apiKey)
            ->timeout($timeoutSeconds)
            ->post(rtrim((string) $baseUrl, '/').'/chat/completions', [
                'model' => $model,
                'messages' => $messages,
            ]);

        if (! $response->successful()) {
            if ($this->isNotChatModelError($response->body())) {
                return $this->openAiCompatibleCompletion($baseUrl, $apiKey, $model, $messages, $timeoutSeconds);
            }

            throw new RuntimeException($this->failureMessage($response->status(), $response->body()));
        }

        $content = $response->json('choices.0.message.content');

        if (! filled($content)) {
            throw new RuntimeException('Provider returned an empty response.');
        }

        return trim($content);
    }

    private function openAiCompatibleCompletion(?string $baseUrl, string $apiKey, string $model, array $messages, int $timeoutSeconds = self::OPENAI_COMPATIBLE_TIMEOUT_SECONDS): string
    {
        $response = Http::acceptJson()
            ->withToken($apiKey)
            ->timeout($timeoutSeconds)
            ->post(rtrim((string) $baseUrl, '/').'/completions', [
                'model' => $model,
                'prompt' => $this->completionPrompt($messages),
                'max_tokens' => 2000,
            ]);

        if (! $response->successful()) {
            if ($this->isCompletionUnsupportedError($response->body())) {
                return $this->openAiCompatibleResponse($baseUrl, $apiKey, $model, $messages, min($timeoutSeconds, self::OPENAI_RESPONSES_TIMEOUT_SECONDS));
            }

            throw new RuntimeException($this->failureMessage($response->status(), $response->body()));
        }

        $content = $response->json('choices.0.text');

        if (! filled($content)) {
            throw new RuntimeException('Provider returned an empty response.');
        }

        return trim($content);
    }

    private function openAiCompatibleResponse(?string $baseUrl, string $apiKey, string $model, array $messages, int $timeoutSeconds = self::OPENAI_RESPONSES_TIMEOUT_SECONDS): string
    {
        $response = Http::acceptJson()
            ->withToken($apiKey)
            ->timeout($timeoutSeconds)
            ->post(rtrim((string) $baseUrl, '/').'/responses', [
                'model' => $model,
                'input' => $this->completionPrompt($messages),
                'max_output_tokens' => 1200,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException($this->failureMessage($response->status(), $response->body()));
        }

        $content = $this->responseOutputText($response->json());

        if (! filled($content)) {
            throw new RuntimeException($this->emptyResponseMessage($response->json()));
        }

        return trim($content);
    }

    private function ollama(?string $baseUrl, string $model, array $messages, int $timeoutSeconds = 120): string
    {
        if (! $baseUrl) {
            throw new RuntimeException('Ollama URL is missing for this provider.');
        }

        $response = Http::acceptJson()
            ->timeout($timeoutSeconds)
            ->post(rtrim($baseUrl, '/').'/api/chat', [
                'model' => $model,
                'messages' => $messages,
                'stream' => false,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException($this->failureMessage($response->status(), $response->body()));
        }

        $content = $response->json('message.content');

        if (! filled($content)) {
            throw new RuntimeException('Ollama returned an empty response.');
        }

        return trim($content);
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
