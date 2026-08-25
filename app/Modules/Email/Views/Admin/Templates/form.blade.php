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
        <form
            method="POST"
            action="{{ $template->exists ? route('tech.admin.system.templatesManagement.email.update', $template) : route('tech.admin.system.templatesManagement.email.store') }}"
            data-email-template-form
            data-preview-url="{{ $previewUrl }}"
        >
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
                <x-forms.html-editor
                    id="body_html"
                    name="body_html"
                    :value="old('body_html', $template->body_html)"
                    :rows="10"
                    :height="360"
                    class="@error('body_html') is-invalid @enderror"
                />
                @error('body_html')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>

            <!-- -------------------------------------------------------------------------------------------------- -->
            <!-- Outer email layout -->
            <!-- Branding follows company settings until an admin explicitly materializes a custom layout. -->
            <!-- -------------------------------------------------------------------------------------------------- -->
            @php($selectedLayoutMode = old('layout_mode', $template->layout_mode ?: \App\Modules\Email\Models\EmailTemplate::LAYOUT_BRANDING))
            <div class="border rounded p-3 mb-3">
                <input type="hidden" name="layout_mode" value="{{ $selectedLayoutMode }}" data-layout-mode>
                <textarea class="d-none" aria-hidden="true" tabindex="-1" data-branding-layout-source>{{ $brandingLayout }}</textarea>

                <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <h2 class="h6 mb-0">Layout HTML</h2>
                            <span class="badge {{ $selectedLayoutMode === 'custom' ? 'text-bg-warning' : 'text-bg-success' }}" data-layout-mode-badge>
                                {{ $selectedLayoutMode === 'custom' ? 'Custom layout' : 'Branding managed' }}
                            </span>
                        </div>
                        <p class="small text-muted mb-0">
                            Branding managed uses the current company logo and light-theme colors. Subject, body, and plaintext edits keep following branding.
                        </p>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-primary {{ $selectedLayoutMode === 'custom' ? 'd-none' : '' }}" data-customize-layout>
                            Customize layout
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger {{ $selectedLayoutMode === 'custom' ? '' : 'd-none' }}" data-reset-layout>
                            Reset to branding
                        </button>
                    </div>
                </div>

                <div class="{{ $selectedLayoutMode === 'custom' ? '' : 'd-none' }}" data-custom-layout-panel>
                    <label for="layout_html" class="form-label">Advanced Layout HTML</label>
                    <textarea id="layout_html" name="layout_html" rows="18" class="form-control font-monospace @error('layout_html') is-invalid @enderror" spellcheck="false" data-layout-html>{{ old('layout_html', $template->layout_html) }}</textarea>
                    <div class="form-text">
                        Complete email document. Keep exactly one reserved <code>@{{ email_body }}</code> slot where Body HTML should be inserted.
                    </div>
                    @error('layout_html')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
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

            <!-- -------------------------------------------------------------------------------------------------- -->
            <!-- Authoritative rendered preview -->
            <!-- Unsaved fields are rendered by the same server service used for outbound delivery. -->
            <!-- -------------------------------------------------------------------------------------------------- -->
            <div class="border rounded p-3 mb-3">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                    <h2 class="h6 mb-0">Rendered preview</h2>
                    <span class="small text-muted" data-template-preview-status>Preview of the current fields.</span>
                </div>
                <div class="small text-muted mb-1">Subject</div>
                <div class="fw-semibold mb-3" data-template-preview-subject>{{ $preview['subject'] }}</div>
                <ul class="nav nav-tabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#templateHtmlPreview" type="button" role="tab">HTML</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#templateTextPreview" type="button" role="tab">Plain text</button>
                    </li>
                </ul>
                <div class="tab-content border border-top-0 rounded-bottom bg-white">
                    <div class="tab-pane fade show active" id="templateHtmlPreview" role="tabpanel">
                        <iframe
                            title="Email template preview"
                            srcdoc="{{ $preview['html'] }}"
                            class="w-100 border-0"
                            sandbox=""
                            referrerpolicy="no-referrer"
                            data-template-preview
                            style="min-height: 620px;"></iframe>
                    </div>
                    <div class="tab-pane fade p-3" id="templateTextPreview" role="tabpanel">
                        <pre class="small text-body mb-0" style="white-space: pre-wrap;" data-template-preview-text>{{ $preview['text'] }}</pre>
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
