<?php

namespace App\Modules\Integration\Livewire\Tech\Ai;

use App\Modules\Integration\Models\AiChat;
use App\Modules\Integration\Services\AiAgentResolver;
use App\Modules\Integration\Services\AiChatResponder;
use App\Modules\Ticket\Models\Ticket;
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
    public ?array $recordContext = null;
    public int $messageInputVersion = 0;

    public function mount(?string $pageTitle = null): void
    {
        $request = request();
        $resolver = app(AiAgentResolver::class);

        $this->routeName = $request->route()?->getName();
        $this->pageUrl = $request->url();
        $this->pageTitle = filled($pageTitle) ? trim($pageTitle) : $this->routeName;
        $this->domain = $resolver->domainFromRoute($this->routeName, $request->path());
        $this->recordContext = $this->buildRecordContext();
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
        $context = [
            'domain' => $this->domain,
            'route_name' => $this->routeName,
            'title' => $this->pageTitle,
            'url' => $this->pageUrl,
        ];

        if ($this->recordContext !== null) {
            $context['record'] = $this->recordContext;
        }

        return $context;
    }

    /**
     * Add compact record-level context for rightbar chats on resource pages.
     */
    private function buildRecordContext(): ?array
    {
        if ($this->routeName === 'tech.tickets.show' || request()->route('ticket')) {
            return $this->ticketContext();
        }

        return null;
    }

    private function ticketContext(): ?array
    {
        $routeTicket = request()->route('ticket');
        $ticket = $routeTicket instanceof Ticket
            ? $routeTicket
            : Ticket::query()->where('ticket_key', (string) $routeTicket)->first();

        if (! $ticket) {
            return null;
        }

        $ticket->loadMissing([
            'queue',
            'status',
            'priority',
            'workflow',
            'category',
            'client',
            'site',
            'contact.site',
            'owner',
            'asset',
            'tags',
            'messages.author',
            'events',
        ]);

        $messages = $ticket->messages
            ->sortByDesc('created_at')
            ->take(10)
            ->reverse()
            ->values()
            ->map(fn ($message) => [
                'author_type' => $message->author_type,
                'author_name' => $message->author?->name,
                'type' => $message->type,
                'visibility' => $message->visibility,
                'subject' => $message->subject,
                'body' => Str::limit(strip_tags((string) $message->body), 2000),
                'is_solution' => (bool) data_get($message->metadata, 'is_solution'),
                'reply_intent' => data_get($message->metadata, 'reply_intent'),
                'created_at' => optional($message->created_at)->toIso8601String(),
                'read_at' => optional($message->read_at)->toIso8601String(),
            ])
            ->all();

        $events = $ticket->events
            ->sortByDesc('created_at')
            ->take(6)
            ->values()
            ->map(fn ($event) => [
                'type' => $event->type,
                'message' => $event->message,
                'created_at' => optional($event->created_at)->toIso8601String(),
            ])
            ->all();

        return [
            'type' => 'ticket',
            'key' => $ticket->ticket_key,
            'subject' => $ticket->subject,
            'description' => Str::limit(strip_tags((string) $ticket->description), 3000),
            'channel' => $ticket->channel,
            'status' => [
                'name' => $ticket->status?->name,
                'slug' => $ticket->status?->slug,
                'state' => $ticket->status?->state,
                'is_closed' => (bool) $ticket->status?->is_closed,
            ],
            'workflow' => [
                'name' => $ticket->workflow?->name,
                'slug' => $ticket->workflow?->slug,
            ],
            'queue' => $ticket->queue?->name,
            'priority' => $ticket->priority?->name,
            'category' => $ticket->category?->name,
            'client' => $ticket->client?->name,
            'site' => $ticket->site?->name,
            'contact' => [
                'name' => $ticket->contact?->name,
                'email' => $ticket->contact?->email,
            ],
            'owner' => $ticket->owner?->name,
            'asset' => $ticket->asset?->name,
            'tags' => $ticket->tags->pluck('name')->values()->all(),
            'is_unread' => (bool) $ticket->is_unread,
            'first_response_due_at' => optional($ticket->first_response_due_at)->toIso8601String(),
            'resolve_due_at' => optional($ticket->resolve_due_at)->toIso8601String(),
            'first_responded_at' => optional($ticket->first_responded_at)->toIso8601String(),
            'resolved_at' => optional($ticket->resolved_at)->toIso8601String(),
            'closed_at' => optional($ticket->closed_at)->toIso8601String(),
            'created_at' => optional($ticket->created_at)->toIso8601String(),
            'updated_at' => optional($ticket->updated_at)->toIso8601String(),
            'recent_messages' => $messages,
            'recent_events' => $events,
        ];
    }

    private function contextTitle(): string
    {
        return Str::limit('Page: '.($this->pageTitle ?: $this->routeName ?: $this->pageUrl ?: 'Nexum PSA'), 120, '');
    }
}
