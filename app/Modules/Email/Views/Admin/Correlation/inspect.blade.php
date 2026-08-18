@extends('layouts.default_tech')

@section('title', 'Inspect canonical candidate #'.$candidate->id)

@section('pageHeader')
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
    <h1>Inspect canonical candidate #{{ $candidate->id }}</h1>
    <a class="btn btn-outline-secondary"
       href="{{ route('tech.admin.settings.email.correlation.show', $candidate->email_canonical_correlation_run_id) }}">
        Back to run
    </a>
</div>
@endsection

@section('sidebar')
<x-nav.admin-menu group="email" />
@endsection

@section('content')
<div class="container-fluid">
    {{-- Candidate inspection is an audited, ordinary-View read with no personal-state mutation. --}}
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
        <div>
            <p class="text-body-secondary mb-0">
                Exact current content for a shadow decision. Opening this report does not mark either message read.
            </p>
        </div>
    </div>

    <div class="row g-4">
        @foreach(['left' => $left, 'right' => $right] as $side => $message)
            <div class="col-12 col-xl-6">
                <article class="card shadow-sm h-100" aria-labelledby="canonical-inspection-{{ $side }}-heading">
                    <div class="card-header">
                        <h2 class="h5 mb-0" id="canonical-inspection-{{ $side }}-heading">
                            {{ ucfirst($side) }} message #{{ $message->id }}
                        </h2>
                    </div>
                    <div class="card-body">
                        <dl class="row small">
                            <dt class="col-sm-4">Account</dt><dd class="col-sm-8">{{ $message->account?->address }}</dd>
                            <dt class="col-sm-4">Subject</dt><dd class="col-sm-8">{{ $message->displaySubject() ?: '(no subject)' }}</dd>
                            <dt class="col-sm-4">From</dt><dd class="col-sm-8">{{ $message->from_email ?: '(unknown)' }}</dd>
                            <dt class="col-sm-4">Received</dt><dd class="col-sm-8">{{ optional($message->received_at)->format('Y-m-d H:i:s') }}</dd>
                            <dt class="col-sm-4">Folders</dt>
                            <dd class="col-sm-8">
                                {{ $message->placements->pluck('folder.path')->filter()->unique()->join(', ') ?: '(no active placement label)' }}
                            </dd>
                        </dl>

                        <h3 class="h6">Message body</h3>
                        <div class="border rounded p-3 bg-body-tertiary overflow-auto" style="max-height: 32rem">
                            @if(filled($message->body_html_sanitized))
                                {!! $message->body_html_sanitized !!}
                            @elseif(filled($message->body_text))
                                <pre class="mb-0 text-wrap">{{ $message->body_text }}</pre>
                            @else
                                <p class="text-body-secondary mb-0">No stored body is available.</p>
                            @endif
                        </div>

                        @if($message->attachments->isNotEmpty())
                            <h3 class="h6 mt-3">Attachment metadata</h3>
                            <ul class="mb-0">
                                @foreach($message->attachments as $attachment)
                                    <li>{{ $attachment->filename }} · {{ $attachment->content_type }} · {{ number_format((int) $attachment->size_bytes) }} bytes</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </article>
            </div>
        @endforeach
    </div>
</div>
@endsection
