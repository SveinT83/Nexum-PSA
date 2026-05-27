<?php

namespace App\Modules\Integration\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Models\Core\User;
use App\Modules\Integration\Jobs\GenerateAiChatResponse;
use App\Modules\Integration\Models\AiChat;
use App\Modules\Integration\Services\AiAgentResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AiChatController extends Controller
{
    /**
     * Show the technician's AI chat workspace.
     */
    public function index(Request $request): View
    {
        $user = $request->user();
        $selectedChat = $this->selectedChat($request, $user);

        return view('integration::Tech.Ai.Chats.index', [
            'agents' => app(AiAgentResolver::class)->availableAgents($user),
            'chats' => AiChat::query()
                ->with(['agent', 'messages' => fn ($query) => $query->latest()->limit(1)])
                ->where('user_id', $user->id)
                ->latest('last_message_at')
                ->latest()
                ->get(),
            'selectedChat' => $selectedChat,
        ]);
    }

    /**
     * Start a new chat with the selected available agent.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $agent = app(AiAgentResolver::class)->availableAgents($user)->firstWhere('id', (int) $request->input('ai_agent_id'));

        abort_unless($agent, 403);

        $data = $request->validate([
            'ai_agent_id' => 'required|integer',
            'title' => 'nullable|string|max:255',
            'message' => 'nullable|string|max:20000',
        ]);

        $chat = AiChat::create([
            'user_id' => $user->id,
            'ai_agent_id' => $agent->id,
            'title' => ($data['title'] ?? null) ?: $this->titleFromMessage($data['message'] ?? null, $agent->name),
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        if (filled($data['message'] ?? null)) {
            $chat->messages()->create([
                'user_id' => $user->id,
                'role' => 'user',
                'body' => $data['message'],
            ]);
            $this->respondAfterResponse($chat);
        }

        return redirect()->route('tech.ai.chats.index', ['chat' => $chat->id])
            ->with('success', 'AI chat started.');
    }

    /**
     * Add a user message to an existing technician-owned chat.
     */
    public function message(Request $request, AiChat $chat): RedirectResponse
    {
        abort_unless((int) $chat->user_id === (int) $request->user()->id, 403);

        $data = $request->validate([
            'message' => 'required|string|max:20000',
        ]);

        $chat->messages()->create([
            'user_id' => $request->user()->id,
            'role' => 'user',
            'body' => $data['message'],
        ]);
        $chat->forceFill(['last_message_at' => now()])->save();
        $this->respondAfterResponse($chat);

        return redirect()->route('tech.ai.chats.index', ['chat' => $chat->id]);
    }

    /**
     * Show a pending assistant reply immediately, then run the slow provider
     * call after the HTTP response has been sent to the technician.
     */
    private function respondAfterResponse(AiChat $chat): void
    {
        $pending = $chat->messages()->create([
            'role' => 'assistant',
            'body' => 'AI is thinking...',
            'metadata' => ['status' => 'pending'],
        ]);
        $chat->forceFill(['last_message_at' => now()])->save();
        GenerateAiChatResponse::dispatchAfterResponse($chat->id, $pending->id);
    }

    private function selectedChat(Request $request, User $user): ?AiChat
    {
        $chatId = $request->integer('chat');

        if (! $chatId) {
            return AiChat::query()
                ->with(['agent.provider', 'messages.user'])
                ->where('user_id', $user->id)
                ->latest('last_message_at')
                ->latest()
                ->first();
        }

        return AiChat::query()
            ->with(['agent.provider', 'messages.user'])
            ->where('user_id', $user->id)
            ->findOrFail($chatId);
    }

    private function titleFromMessage(?string $message, string $agentName): string
    {
        if (filled($message)) {
            return Str::limit(preg_replace('/\s+/', ' ', trim($message)), 80, '');
        }

        return 'New chat with '.$agentName;
    }
}
