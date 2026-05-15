<?php

namespace App\Modules\Integration\Livewire\Tech\Ai;

use App\Modules\Integration\Models\AiChat;
use App\Modules\Integration\Services\AiAgentResolver;
use App\Modules\Integration\Services\AiChatResponder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Component;

class ContextChat extends Component
{
    public ?int $selectedAgentId = null;
    public ?int $chatId = null;
    public string $message = '';
    public ?string $domain = null;
    public ?string $routeName = null;
    public ?string $pageTitle = null;
    public ?string $pageUrl = null;
    public int $messageInputVersion = 0;

    public function mount(?string $pageTitle = null): void
    {
        $request = request();
        $resolver = app(AiAgentResolver::class);

        $this->routeName = $request->route()?->getName();
        $this->pageUrl = $request->url();
        $this->pageTitle = filled($pageTitle) ? trim($pageTitle) : $this->routeName;
        $this->domain = $resolver->domainFromRoute($this->routeName, $request->path());
        $this->selectedAgentId = $resolver->defaultAgent($request->user(), $this->domain)?->id;
        $this->chatId = $this->currentContextChat()?->id;
    }

    public function send(): void
    {
        $this->validate([
            'selectedAgentId' => 'required|integer',
            'message' => 'required|string|max:20000',
        ]);

        $user = request()->user();
        $agent = app(AiAgentResolver::class)
            ->availableAgents($user)
            ->firstWhere('id', (int) $this->selectedAgentId);

        abort_unless($agent, 403);

        $chat = $this->currentContextChat() ?: AiChat::create([
            'user_id' => $user->id,
            'ai_agent_id' => $agent->id,
            'title' => $this->contextTitle(),
            'status' => 'open',
            'metadata' => [
                'source' => 'rightbar',
                'page_context' => $this->pageContext(),
            ],
            'last_message_at' => now(),
        ]);

        if ((int) $chat->ai_agent_id !== (int) $agent->id) {
            $chat->forceFill(['ai_agent_id' => $agent->id])->save();
        }

        $chat->messages()->create([
            'user_id' => $user->id,
            'role' => 'user',
            'body' => $this->message,
            'metadata' => ['page_context' => $this->pageContext()],
        ]);

        $chat->messages()->create([
            'role' => 'assistant',
            'body' => 'AI is thinking...',
            'metadata' => ['status' => 'pending'],
        ]);

        $chat->forceFill([
            'metadata' => array_replace_recursive($chat->metadata ?? [], [
                'source' => 'rightbar',
                'page_context' => $this->pageContext(),
            ]),
            'last_message_at' => now(),
        ])->save();

        $this->chatId = $chat->id;
        $this->message = '';
        $this->messageInputVersion++;
        $this->dispatch('rightbar-ai-scroll-bottom');
    }

    public function updatedDomain(): void
    {
        $this->selectedAgentId = app(AiAgentResolver::class)->defaultAgent(request()->user(), $this->domain)?->id;
        $this->chatId = null;
    }

    public function processPendingResponse(AiChatResponder $responder): void
    {
        $chat = $this->currentContextChat();
        $pending = $chat?->messages
            ->where('role', 'assistant')
            ->first(fn ($chatMessage) => ($chatMessage->metadata['status'] ?? null) === 'pending');

        if (! $chat || ! $pending) {
            return;
        }

        $responder->respond($chat, $pending->id);
        $this->dispatch('rightbar-ai-scroll-bottom');
    }

    public function render()
    {
        $agents = $this->availableAgents();
        $chat = $this->currentContextChat();

        if (! $this->selectedAgentId && $agents->isNotEmpty()) {
            $this->selectedAgentId = app(AiAgentResolver::class)->defaultAgent(request()->user(), $this->domain)?->id;
        }

        return view('integration::Livewire.Tech.Ai.context-chat', [
            'agents' => $agents,
            'chat' => $chat,
            'hasPendingResponse' => $chat?->messages?->contains(fn ($message) => ($message->metadata['status'] ?? null) === 'pending') ?? false,
            'domainLabel' => $this->domain ? (app(AiAgentResolver::class)->domainOptions()[$this->domain] ?? Str::headline($this->domain)) : 'Current page',
        ]);
    }

    private function availableAgents(): Collection
    {
        $user = request()->user();

        if (! $user) {
            return collect();
        }

        return app(AiAgentResolver::class)->availableAgents($user);
    }

    private function currentContextChat(): ?AiChat
    {
        $user = request()->user();

        if (! $user) {
            return null;
        }

        if ($this->chatId) {
            return AiChat::query()
                ->with(['agent.provider', 'messages.user'])
                ->where('user_id', $user->id)
                ->find($this->chatId);
        }

        return AiChat::query()
            ->with(['agent.provider', 'messages.user'])
            ->where('user_id', $user->id)
            ->latest('last_message_at')
            ->latest()
            ->limit(50)
            ->get()
            ->first(fn (AiChat $chat) => data_get($chat->metadata, 'source') === 'rightbar'
                && data_get($chat->metadata, 'page_context.url') === $this->pageUrl);
    }

    private function pageContext(): array
    {
        return [
            'domain' => $this->domain,
            'route_name' => $this->routeName,
            'title' => $this->pageTitle,
            'url' => $this->pageUrl,
        ];
    }

    private function contextTitle(): string
    {
        return Str::limit('Page: '.($this->pageTitle ?: $this->routeName ?: $this->pageUrl ?: 'tdPSA'), 120, '');
    }
}
