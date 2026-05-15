@php
    // Shared Knowledge workspace navigation across Documentation and Knowledge modules.
    $knowledgeMenuItems = [
        [
            'label' => 'Documentations',
            'route' => 'tech.documentations.index',
            'pattern' => 'tech.documentations.*',
            'icon' => 'bi-folder2-open',
        ],
        [
            'label' => 'Knowledge Base',
            'route' => 'tech.knowledge.index',
            'pattern' => 'tech.knowledge.*',
            'icon' => 'bi-journal-text',
        ],
        [
            'label' => 'AI Chats',
            'route' => 'tech.ai.chats.index',
            'pattern' => 'tech.ai.chats.*',
            'icon' => 'bi-stars',
        ],
    ];
@endphp

<!-- ------------------------------------------------- -->
<!-- Knowledge Workspace Navigation -->
<!-- ------------------------------------------------- -->
<nav class="py-3" aria-label="Knowledge workspace navigation">
    <div class="px-2 mb-2">
        <div class="small text-uppercase fw-semibold text-muted">Knowledge workspace</div>
    </div>

    <div class="nav nav-pills flex-column gap-1">
        @foreach($knowledgeMenuItems as $item)
            @continue(!Route::has($item['route']))

            @php
                $isActive = request()->routeIs($item['pattern']);
            @endphp

            <a
                href="{{ route($item['route']) }}"
                class="nav-link d-flex align-items-center gap-2 px-3 py-2 {{ $isActive ? 'active' : 'link-dark bg-light border' }}"
                @if($isActive) aria-current="page" @endif>
                <i class="bi {{ $item['icon'] }}" aria-hidden="true"></i>
                <span>{{ $item['label'] }}</span>
            </a>
        @endforeach
    </div>
</nav>
