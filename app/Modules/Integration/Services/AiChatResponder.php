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
    private function send(AiAgent $agent, array $messages): string
    {
        $provider = $agent->provider;

        if (! $provider || $provider->status !== 'active') {
            throw new RuntimeException('The selected agent has no active provider.');
        }

        $model = $agent->model ?: $provider->default_model;

        if (! $model) {
            throw new RuntimeException('Select a model for this agent or its provider before chatting.');
        }

        return match ($provider->provider_key) {
            'openai', 'custom_openai_compatible' => $this->openAiCompatible($provider->base_url ?: 'https://api.openai.com/v1', $provider->getSecret('api_key'), $model, $messages),
            'mistral' => $this->openAiCompatible($provider->base_url ?: 'https://api.mistral.ai/v1', $provider->getSecret('api_key'), $model, $messages),
            'openrouter' => $this->openAiCompatible($provider->base_url ?: 'https://openrouter.ai/api/v1', $provider->getSecret('api_key'), $model, $messages),
            'ollama' => $this->ollama($provider->base_url, $model, $messages),
            default => throw new RuntimeException('Chat is not wired for '.$provider->provider_key.' yet.'),
        };
    }

    private function openAiCompatible(?string $baseUrl, ?string $apiKey, string $model, array $messages): string
    {
        if (! $apiKey) {
            throw new RuntimeException('API key is missing for this provider.');
        }

        $response = Http::acceptJson()
            ->withToken($apiKey)
            ->timeout(60)
            ->post(rtrim((string) $baseUrl, '/').'/chat/completions', [
                'model' => $model,
                'messages' => $messages,
                'temperature' => 0.2,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException($this->failureMessage($response->status(), $response->body()));
        }

        $content = $response->json('choices.0.message.content');

        if (! filled($content)) {
            throw new RuntimeException('Provider returned an empty response.');
        }

        return trim($content);
    }

    private function ollama(?string $baseUrl, string $model, array $messages): string
    {
        if (! $baseUrl) {
            throw new RuntimeException('Ollama URL is missing for this provider.');
        }

        $response = Http::acceptJson()
            ->timeout(120)
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

    private function pageContextInstructions(AiChat $chat): string
    {
        $context = data_get($chat->metadata, 'page_context', []);

        if (! is_array($context) || $context === []) {
            return '';
        }

        return collect([
            'Current tdPSA page context:',
            '- Domain: '.(data_get($context, 'domain') ?: 'unknown'),
            '- Route: '.(data_get($context, 'route_name') ?: 'unknown'),
            '- Title: '.(data_get($context, 'title') ?: 'unknown'),
            '- URL: '.(data_get($context, 'url') ?: 'unknown'),
            'Use this as local context for the conversation. Do not claim that the page data has been changed unless a write tool is explicitly available and executed.',
        ])->implode("\n");
    }

    private function toolInstructions(AiAgent $agent): string
    {
        $tools = $agent->allowed_tools ?? [];
        $dataSources = $agent->data_sources ?? [];

        $hasKnowledgeSearch = in_array('knowledge.search', $tools, true) || in_array('search', $tools, true);

        $instructions = [
            'Use available tdPSA tools before saying you do not have access.',
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
