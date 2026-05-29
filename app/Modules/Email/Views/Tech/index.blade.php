@extends('layouts.default_tech')

@section('title', 'Inbox')

@section('pageHeader')
    <h1>Inbox</h1>
@endsection

@section('content')
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <form method="get" action="{{ route('tech.inbox.index') }}" class="d-flex" role="search">
            <input type="text" name="q" value="{{ $search }}" class="form-control me-2" placeholder="Search subject, from, body...">
            <button class="btn btn-outline-secondary" type="submit">Search</button>
        </form>

        <form method="post" action="{{ route('tech.inbox.poll') }}" onsubmit="return confirm('Fetch new mail now for all active accounts?');">
            @csrf
            <button type="submit" class="btn btn-outline-success">Check now</button>
        </form>

    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width: 28%">From</th>
                        <th style="width: 42%">Subject</th>
                        <th style="width: 20%">Received</th>
                        <th style="width: 10%" class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($messages as $msg)
                    <tr
                        class="cursor-pointer inbox-index-row"
                        role="link"
                        tabindex="0"
                        data-href="{{ route('tech.inbox.show', $msg) }}"
                        aria-label="Open inbox email #{{ $msg->id }}">
                        <td>
                            <div class="fw-semibold">{{ $msg->from_name ?: $msg->from_email }}</div>
                            <div class="text-muted small">{{ $msg->from_email }}</div>
                        </td>
                        <td>
                            <a href="{{ route('tech.inbox.show', $msg) }}" class="text-decoration-none">
                                {{ str($msg->subject)->limit(100) ?: '(no subject)' }}
                            </a>
                            <div class="text-muted small">{{ str($msg->body_text ?? '')->limit(120) }}</div>
                        </td>
                        <td>
                            <div>{{ optional($msg->received_at)->format('Y-m-d H:i') ?? $msg->created_at->format('Y-m-d H:i') }}</div>
                            <div class="text-muted small">#{{ $msg->id }}</div>
                        </td>
                        <td class="text-end">
                            <form action="{{ route('tech.inbox.spam', $msg) }}" method="post" class="d-inline"
                                  onsubmit="return confirm('Mark this sender as spam and create/update an email rule?');">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-warning" title="Mark as spam">
                                    <i class="bi bi-shield-exclamation" aria-hidden="true"></i>
                                    <span class="visually-hidden">Mark email #{{ $msg->id }} as spam</span>
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="text-center py-5 text-muted">No unrouted emails found.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $messages->links() }}</div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('tr[data-href]').forEach(function (row) {
                row.addEventListener('click', function (event) {
                    if (event.target.closest('a, button, input, select, textarea, form')) {
                        return;
                    }

                    window.location.href = row.dataset.href;
                });

                row.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        window.location.href = row.dataset.href;
                    }
                });
            });
        });
    </script>
@endsection

@section('sidebar')
    <x-nav.work-menu />
@endsection
