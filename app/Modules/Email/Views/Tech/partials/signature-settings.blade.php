@php
    /** @var \App\Modules\Email\Services\EmailSignatureRenderer $mailSignatureRenderer */
    $mailSignatureRenderer = app(\App\Modules\Email\Services\EmailSignatureRenderer::class);
    $mailSignature = $mailSignatureRenderer->signatureFor(auth()->user());
    $mailSignaturePreviewHtml = $mailSignatureRenderer->renderBodyHtml($mailSignature, auth()->user());
    $mailSignatureTokens = $mailSignatureRenderer->tokenDescriptions();
@endphp

<!-- Mail-owned personal email signature settings -->
<div id="mail-signature" class="card">
    <div class="card-header py-2 d-flex align-items-center justify-content-between gap-2">
        <h2 class="h6 mb-0">Email signature</h2>
        <span class="badge text-bg-light border">Mail</span>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('tech.mail.signature.update') }}">
            @csrf
            @method('PATCH')

            <div class="mb-3">
                <label for="mail_signature_name" class="form-label">Signature name</label>
                <input id="mail_signature_name" name="signature_name" class="form-control @error('signature_name') is-invalid @enderror" value="{{ old('signature_name', $mailSignature->name ?: 'Default') }}" maxlength="120">
                @error('signature_name')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <label for="mail_signature_body_html" class="form-label">Signature HTML</label>
                <textarea id="mail_signature_body_html" name="signature_body_html" class="form-control font-monospace @error('signature_body_html') is-invalid @enderror" rows="8">{{ old('signature_body_html', $mailSignature->body_html ?: $mailSignatureRenderer->defaultBodyHtml()) }}</textarea>
                @error('signature_body_html')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="row g-2 mb-3">
                <div class="col-sm-6 col-xl-3">
                    <input type="hidden" name="use_on_compose" value="0">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="mail_signature_compose" name="use_on_compose" value="1" @checked(old('use_on_compose', $mailSignature->use_on_compose))>
                        <label class="form-check-label" for="mail_signature_compose">Compose</label>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <input type="hidden" name="use_on_reply" value="0">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="mail_signature_reply" name="use_on_reply" value="1" @checked(old('use_on_reply', $mailSignature->use_on_reply))>
                        <label class="form-check-label" for="mail_signature_reply">Reply</label>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <input type="hidden" name="use_on_reply_all" value="0">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="mail_signature_reply_all" name="use_on_reply_all" value="1" @checked(old('use_on_reply_all', $mailSignature->use_on_reply_all))>
                        <label class="form-check-label" for="mail_signature_reply_all">Reply all</label>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <input type="hidden" name="use_on_forward" value="0">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="mail_signature_forward" name="use_on_forward" value="1" @checked(old('use_on_forward', $mailSignature->use_on_forward))>
                        <label class="form-check-label" for="mail_signature_forward">Forward</label>
                    </div>
                </div>
            </div>

            <div class="border rounded p-2 mb-3 bg-body-tertiary">
                <div class="small fw-semibold mb-2">Preview</div>
                <div class="small bg-white border rounded p-2">
                    {!! $mailSignaturePreviewHtml ?: '<span class="text-muted">No visible signature content.</span>' !!}
                </div>
            </div>

            <details class="mb-3">
                <summary class="small text-muted">Available tokens</summary>
                <div class="d-flex flex-wrap gap-1 mt-2">
                    @foreach($mailSignatureTokens as $token => $description)
                        <span class="badge text-bg-light border" title="{{ $description }}">{{ $token }}</span>
                    @endforeach
                </div>
            </details>

            <div class="d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-1" aria-hidden="true"></i>Save signature
                </button>
                <button type="submit" name="use_default_template" value="1" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Use default template
                </button>
            </div>
        </form>
    </div>
</div>
