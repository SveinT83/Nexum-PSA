@extends('layouts.default_tech')

@section('title', 'Inbox')

@section('pageHeader')
    <h1>Inbox</h1>
@endsection

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <a href="{{ route('tech.inbox.index', ['q' => $search ?? request('q')]) }}" class="btn btn-outline-secondary">← Back to Inbox</a>
        </div>
        <form method="get" action="{{ route('tech.inbox.index') }}" class="d-flex" role="search">
            <input type="text" name="q" value="{{ $search ?? request('q') ?? '' }}" class="form-control me-2" placeholder="Search subject, from, body...">
            <button class="btn btn-outline-secondary" type="submit">Search</button>
        </form>
    </div>

    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div class="fw-semibold text-truncate">{{ $message->subject ?: '(no subject)' }}</div>
            <div class="text-muted small">#{{ $message->id }}</div>
        </div>
        <div class="card-body">
            <div class="mb-3">
                <div><span class="text-muted">From:</span> {{ $message->from_name ?: $message->from_email }} <span class="text-muted">&lt;{{ $message->from_email }}&gt;</span></div>
                <div class="text-muted small">Received: {{ optional($message->received_at)->format('Y-m-d H:i') ?? $message->created_at->format('Y-m-d H:i') }}</div>
            </div>

            @if($message->body_html_sanitized)
                <div class="border rounded p-3" style="background: #fff;">
                    {!! $message->body_html_sanitized !!}
                </div>
            @elseif($message->body_text)
                <pre class="border rounded p-3 bg-light" style="white-space: pre-wrap;">{{ $message->body_text }}</pre>
            @else
                <div class="text-muted">No body content.</div>
            @endif

            @if(($message->attachments->count() ?? 0) > 0)
                <hr>
                <div class="mb-2 fw-semibold">Attachments</div>
                <ul class="list-unstyled mb-0">
                    @foreach($message->attachments as $att)
                        <li class="mb-1">
                            <a href="{{ route('tech.inbox.download', $att) }}" class="text-decoration-none">
                                {{ $att->filename ?: basename($att->path) }}
                            </a>
                            @if($att->size_bytes)
                                <span class="text-muted small">({{ number_format($att->size_bytes/1024, 0) }} KB)</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
        <div class="card-footer d-flex justify-content-end">
            <form action="{{ route('tech.inbox.delete', $message) }}" method="post" onsubmit="return confirm('Delete this email locally? Files will also be removed.');">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-outline-danger">Delete</button>
            </form>
        </div>
    </div>
@endsection
