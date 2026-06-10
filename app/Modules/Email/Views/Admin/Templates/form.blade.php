@extends('layouts.default_tech')

@section('title', $template->exists ? 'Edit Email Template' : 'Create Email Template')

<!-- -------------------------------------------------------------------------------------------------- -->
<!-- Page header -->
<!-- Shared create/edit screen for outbound email templates. -->
<!-- -------------------------------------------------------------------------------------------------- -->
@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1 class="mb-0">{{ $template->exists ? 'Edit Email Template' : 'Create Email Template' }}</h1>
        <x-buttons.back url="{{ route('tech.admin.system.templatesManagement.email.index') }}">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Template form -->
    <!-- Stores subject and body variants used by outbound email flows. -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    <x-card.default title="Template">
        <form method="POST" action="{{ $template->exists ? route('tech.admin.system.templatesManagement.email.update', $template) : route('tech.admin.system.templatesManagement.email.store') }}">
            @csrf
            @if ($template->exists)
                @method('PUT')
            @endif

            <div class="row">
                <div class="col-md-4 mb-3">
                    <label for="scope" class="form-label">Scope</label>
                    <select id="scope" name="scope" class="form-select @error('scope') is-invalid @enderror">
                        @foreach ($scopes as $value => $label)
                            <option value="{{ $value }}" @selected(old('scope', $template->scope) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('scope')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-4 mb-3">
                    <label for="key" class="form-label">Key</label>
                    <input id="key" name="key" type="text" class="form-control @error('key') is-invalid @enderror" value="{{ old('key', $template->key) }}" required>
                    @error('key')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-4 mb-3">
                    <label for="name" class="form-label">Name</label>
                    <input id="name" name="name" type="text" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $template->name) }}" required>
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="mb-3">
                <label for="subject" class="form-label">Subject</label>
                <input id="subject" name="subject" type="text" class="form-control @error('subject') is-invalid @enderror" value="{{ old('subject', $template->subject) }}" required>
                @error('subject')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label for="body_html" class="form-label">HTML body</label>
                <textarea id="body_html" name="body_html" rows="8" class="form-control @error('body_html') is-invalid @enderror">{{ old('body_html', $template->body_html) }}</textarea>
                @error('body_html')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label for="body_text" class="form-label">Text body</label>
                <textarea id="body_text" name="body_text" rows="8" class="form-control @error('body_text') is-invalid @enderror">{{ old('body_text', $template->body_text) }}</textarea>
                @error('body_text')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label for="variables" class="form-label">Variables</label>
                <textarea id="variables" name="variables" rows="3" class="form-control @error('variables') is-invalid @enderror">{{ old('variables', implode("\n", (array) $template->variables)) }}</textarea>
                <div class="form-text">One variable per line or comma-separated.</div>
                @error('variables')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <input type="hidden" name="is_default" value="0">
                    <div class="form-check">
                        <input id="is_default" name="is_default" type="checkbox" class="form-check-input" value="1" @checked(old('is_default', $template->is_default))>
                        <label for="is_default" class="form-check-label">Default template</label>
                    </div>
                </div>

                <div class="col-md-6 mb-3">
                    <input type="hidden" name="is_active" value="0">
                    <div class="form-check">
                        <input id="is_active" name="is_active" type="checkbox" class="form-check-input" value="1" @checked(old('is_active', $template->is_active ?? true))>
                        <label for="is_active" class="form-check-label">Active</label>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Save template</button>
        </form>
    </x-card.default>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="email" />
@endsection

@section('rightbar')
    @if(!empty($preview))
        <x-card.default title="Rendered Preview">
            <div class="small text-muted mb-2">Subject</div>
            <div class="fw-semibold mb-3">{{ $preview['subject'] }}</div>
            <div class="small text-muted mb-2">HTML</div>
            <div class="border rounded bg-white p-2 mb-3" style="max-height: 420px; overflow: auto;">
                <iframe
                    title="Email template preview"
                    srcdoc="{{ e($preview['html']) }}"
                    class="w-100 border-0"
                    style="min-height: 360px;"></iframe>
            </div>
            <div class="small text-muted mb-2">Text</div>
            <pre class="small bg-light border rounded p-2 mb-0" style="white-space: pre-wrap;">{{ $preview['text'] }}</pre>
        </x-card.default>
    @endif

    <x-card.default title="Available variables">
        <p class="small text-muted">
            Use variables as placeholders in subject and body, for example <code>@{{ message_body }}</code>.
        </p>
        <ul class="small text-muted mb-0">
            @foreach(array_keys($sampleVariables ?? []) as $variable)
                <li><code>{{ $variable }}</code></li>
            @endforeach
        </ul>
    </x-card.default>
@endsection
