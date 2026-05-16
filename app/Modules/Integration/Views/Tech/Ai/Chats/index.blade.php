@extends('layouts.default_tech')

@section('title', 'AI Chats')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center w-100">
        <div>
            <h1 class="h4 mb-0">AI Chats</h1>
            <div class="small text-muted">Technician chat workspace</div>
        </div>
        @if($agents->isNotEmpty())
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newAiChatModal">
                <i class="bi bi-plus-lg" aria-hidden="true"></i>
                New chat
            </button>
        @endif
    </div>
@endsection

@section('content')
    <div class="container-fluid">
        @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        <!-- ------------------------------------------------- -->
        <!-- AI Chat Workspace -->
        <!-- ------------------------------------------------- -->
        <div class="row g-3">
            <div class="col-12 col-xl-4">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center justify-content-between gap-2">
                        <h2 class="h6 mb-0">My chats</h2>
                        <span class="badge text-bg-light border">{{ $chats->count() }}</span>
                    </div>
                    <div class="list-group list-group-flush">
                        @forelse($chats as $chat)
                            @php
                                $isActive = $selectedChat && (int) $selectedChat->id === (int) $chat->id;
                                $latestMessage = $chat->messages->first();
                            @endphp
                            <a
                                href="{{ route('tech.ai.chats.index', ['chat' => $chat->id]) }}"
                                class="list-group-item list-group-item-action {{ $isActive ? 'active' : '' }}"
                                @if($isActive) aria-current="page" @endif>
                                <div class="d-flex align-items-start justify-content-between gap-2">
                                    <div class="min-w-0">
                                        <div class="fw-semibold text-truncate">{{ $chat->title }}</div>
                                        <div class="small {{ $isActive ? 'text-white-50' : 'text-muted' }} text-truncate">
                                            {{ $chat->agent?->name ?? 'No agent' }}
                                        </div>
                                    </div>
                                    <div class="small {{ $isActive ? 'text-white-50' : 'text-muted' }} flex-shrink-0">
                                        {{ $chat->last_message_at?->diffForHumans() ?? $chat->created_at?->diffForHumans() }}
                                    </div>
                                </div>
                                <div class="small {{ $isActive ? 'text-white-50' : 'text-muted' }} text-truncate mt-1">
                                    {{ $latestMessage?->body ?? 'No messages yet.' }}
                                </div>
                            </a>
                        @empty
                            <div class="p-3 text-muted small">No chats yet.</div>
                        @endforelse
                    </div>
                </div>
            </div>

            <div class="col-12 col-xl-8">
                <div class="card h-100">
                    @if($selectedChat)
                        @php
                            $hasPendingAiResponse = $selectedChat->messages->contains(fn ($message) => ($message->metadata['status'] ?? null) === 'pending');
                        @endphp
                        <div class="card-header d-flex align-items-center justify-content-between gap-3">
                            <div class="min-w-0">
                                <h2 class="h6 mb-0 text-truncate">{{ $selectedChat->title }}</h2>
                                <div class="small text-muted">
                                    {{ $selectedChat->agent?->name ?? 'No agent' }}
                                    @if($selectedChat->agent?->provider)
                                        · {{ $selectedChat->agent->provider->name }}
                                    @endif
                                </div>
                            </div>
                            @if($agents->isNotEmpty())
                                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#newAiChatModal">
                                    <i class="bi bi-plus-lg" aria-hidden="true"></i>
                                    New
                                </button>
                            @endif
                        </div>
                        <div class="card-body d-flex flex-column" style="height: calc(100vh - 18rem); min-height: 32rem;">
                            <!-- Pending assistant messages are written before the provider call so the technician gets immediate feedback. -->
                            <div id="aiChatMessages" class="flex-grow-1 overflow-auto border rounded bg-white p-3 pe-2" style="min-height: 0;">
                                @forelse($selectedChat->messages->sortBy('created_at') as $message)
                                    @php
                                        $isPendingMessage = ($message->metadata['status'] ?? null) === 'pending';
                                    @endphp
                                    <div class="mb-3 d-flex {{ $message->role === 'user' ? 'justify-content-end' : 'justify-content-start' }}">
                                        <div class="border rounded px-3 py-2 {{ $message->role === 'user' ? 'bg-primary text-white' : 'bg-light' }}" style="max-width: 82%;">
                                            <div class="small {{ $message->role === 'user' ? 'text-white-50' : 'text-muted' }} mb-1">
                                                {{ $message->role === 'user' ? ($message->user?->name ?? 'You') : 'Assistant' }}
                                                · {{ $message->created_at?->format('Y-m-d H:i') }}
                                            </div>
                                            @if($isPendingMessage)
                                                <div class="d-flex align-items-center gap-2 text-muted">
                                                    <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                                                    <span>{{ $message->body }}</span>
                                                </div>
                                            @else
                                                <div style="white-space: pre-wrap;">{!! \App\Modules\Integration\Support\AiMessageFormatter::render($message->body) !!}</div>
                                            @endif
                                        </div>
                                    </div>
                                @empty
                                    <div class="text-muted small">This chat has no messages yet.</div>
                                @endforelse
                            </div>

                            <form method="POST" action="{{ route('tech.ai.chats.messages.store', $selectedChat) }}" class="mt-3 flex-shrink-0">
                                @csrf
                                <label for="message" class="form-label">Message</label>
                                <textarea id="message" name="message" rows="4" class="form-control @error('message') is-invalid @enderror" required placeholder="Ask about Knowledge, active tickets, or another allowed source...">{{ old('message') }}</textarea>
                                @error('message')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <div class="d-flex justify-content-end mt-2">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-send" aria-hidden="true"></i>
                                        Send
                                    </button>
                                </div>
                            </form>
                        </div>
                    @else
                        <div class="card-body">
                            @if($agents->isEmpty())
                                <div class="alert alert-light border mb-0">
                                    No AI agents are available for your roles yet.
                                </div>
                            @else
                                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newAiChatModal">
                                    <i class="bi bi-plus-lg" aria-hidden="true"></i>
                                    Start first chat
                                </button>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <!-- ------------------------------------------------- -->
        <!-- New Chat Modal -->
        <!-- ------------------------------------------------- -->
        @if($agents->isNotEmpty())
            <div class="modal fade" id="newAiChatModal" tabindex="-1" aria-labelledby="newAiChatModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <form method="POST" action="{{ route('tech.ai.chats.store') }}">
                            @csrf
                            <div class="modal-header">
                                <h2 class="modal-title h5" id="newAiChatModalLabel">New AI chat</h2>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="ai_agent_id" class="form-label">Agent</label>
                                        <select id="ai_agent_id" name="ai_agent_id" class="form-select @error('ai_agent_id') is-invalid @enderror" required>
                                            @foreach($agents as $agent)
                                                <option value="{{ $agent->id }}" @selected(old('ai_agent_id', $agents->firstWhere('is_default', true)?->id ?? $agents->first()?->id) == $agent->id)>
                                                    {{ $agent->name }}
                                                    @if($agent->is_default)
                                                        (default)
                                                    @endif
                                                </option>
                                            @endforeach
                                        </select>
                                        @error('ai_agent_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="col-md-6">
                                        <label for="title" class="form-label">Title</label>
                                        <input id="title" name="title" class="form-control @error('title') is-invalid @enderror" value="{{ old('title') }}" placeholder="Optional">
                                        @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="col-12">
                                        <label for="new_message" class="form-label">First message</label>
                                        <textarea id="new_message" name="message" rows="5" class="form-control @error('message') is-invalid @enderror" placeholder="Start with a question or leave blank to create an empty chat.">{{ old('message') }}</textarea>
                                        @error('message')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-primary">Start chat</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endif
    </div>

    @if($selectedChat && $hasPendingAiResponse)
        <script>
            window.setTimeout(function () {
                window.location.reload();
            }, 2500);
        </script>
    @endif

    @if($selectedChat)
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const messagePane = document.getElementById('aiChatMessages');

                if (messagePane) {
                    messagePane.scrollTop = messagePane.scrollHeight;
                }
            });
        </script>
    @endif
@endsection

@section('sidebar')
    <x-nav.knowledge-menu />
@endsection

@section('rightbar')
    @if($selectedChat?->agent)
        <div class="card">
            <div class="card-header">
                <h2 class="h6 mb-0">Agent</h2>
            </div>
            <div class="card-body small">
                <div class="fw-semibold">{{ $selectedChat->agent->name }}</div>
                <div class="text-muted mb-2">{{ $selectedChat->agent->provider?->name ?? 'No provider' }}</div>
                <div class="text-muted text-uppercase fw-semibold mb-1" style="font-size: .68rem;">Data access</div>
                <div class="d-flex flex-wrap gap-1">
                    @foreach($selectedChat->agent->data_sources ?? [] as $source)
                        <span class="badge text-bg-light border">{{ str_replace('_', ' ', $source) }}</span>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
@endsection
