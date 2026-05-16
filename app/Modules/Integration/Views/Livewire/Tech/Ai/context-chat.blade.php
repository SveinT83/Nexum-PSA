{{-- Compact rightbar AI chat bound to the current page context. --}}
<div>
    @if($agents->isNotEmpty())
        <div class="card mt-3" @if($hasPendingResponse) wire:poll.2500ms="processPendingResponse" @endif>
            <div class="card-header py-2">
                <div class="min-w-0 d-flex align-items-center gap-2">
                    <span class="d-inline-flex align-items-center justify-content-center rounded bg-primary-subtle text-primary-emphasis border" style="width: 1.75rem; height: 1.75rem;">
                        <i class="bi bi-cpu" aria-hidden="true"></i>
                    </span>
                    <h2 class="h6 mb-0">AI</h2>
                </div>
            </div>

            <div class="card-body p-2">
                <div class="mb-2">
                    <label for="rightbar_ai_agent" class="form-label small mb-1">Agent</label>
                    <select id="rightbar_ai_agent" wire:model="selectedAgentId" class="form-select form-select-sm">
                        @foreach($agents as $agent)
                            <option value="{{ $agent->id }}">
                                {{ $agent->name }}
                                @if(in_array($domain ?? '', $agent->default_domains ?? [], true))
                                    (domain)
                                @elseif($agent->is_default)
                                    (default)
                                @endif
                            </option>
                        @endforeach
                    </select>
                </div>

                <div id="rightbarAiMessages" class="border rounded bg-white p-2 mb-2 overflow-auto" style="max-height: 18rem;">
                    @forelse($chat?->messages?->sortBy('created_at') ?? collect() as $chatMessage)
                        @php($isPending = ($chatMessage->metadata['status'] ?? null) === 'pending')
                        <div class="mb-2">
                            <div class="small text-muted mb-1">{{ $chatMessage->role === 'user' ? 'You' : 'AI' }}</div>
                            <div class="rounded px-2 py-1 small {{ $chatMessage->role === 'user' ? 'bg-primary-subtle border text-primary-emphasis' : 'bg-light border' }}" style="white-space: pre-wrap;">
                                @if($isPending)
                                    <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                                @endif
                                {!! \App\Modules\Integration\Support\AiMessageFormatter::render($chatMessage->body) !!}
                            </div>
                        </div>
                    @empty
                        <div class="small text-muted">Ask about this page. Page and record context are sent with the chat.</div>
                    @endforelse
                </div>

                <form wire:submit.prevent="send">
                    <label for="rightbar_ai_message" class="visually-hidden">Message</label>
                    <textarea id="rightbar_ai_message" wire:key="rightbar-ai-message-{{ $messageInputVersion }}" wire:model.defer="message" rows="3" class="form-control form-control-sm @error('message') is-invalid @enderror" placeholder="Ask about this page..."></textarea>
                    @error('message')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="d-grid mt-2">
                        <button type="submit" class="btn btn-sm btn-primary" wire:loading.attr="disabled" wire:target="send">
                            <i class="bi bi-send" aria-hidden="true"></i>
                            Send
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <script>
        window.scrollRightbarAiToBottom = window.scrollRightbarAiToBottom || function () {
            [0, 50, 150, 300].forEach(function (delay) {
                window.setTimeout(function () {
                    const pane = document.getElementById('rightbarAiMessages');

                    if (pane) {
                        pane.scrollTop = pane.scrollHeight;
                    }
                }, delay);
            });
        };

        window.addEventListener('rightbar-ai-scroll-bottom', window.scrollRightbarAiToBottom);
        window.scrollRightbarAiToBottom();
    </script>
</div>
