@php
    /** @var \App\Modules\Email\Services\EmailSignatureRenderer $mailSignatureRenderer */
    $mailSignatureRenderer = app(\App\Modules\Email\Services\EmailSignatureRenderer::class);
    $mailSignature = $mailSignatureRenderer->signatureFor(auth()->user());
    $mailSignatureModes = [
        'use_on_compose' => 'Compose',
        'use_on_reply' => 'Reply',
        'use_on_reply_all' => 'Reply all',
        'use_on_forward' => 'Forward',
    ];
@endphp

<!-- Mail-owned signature status and modal trigger -->
<div class="card mb-3">
    <div class="card-header p-0" id="mail-signature-rightbar-heading">
        <button
            type="button"
            class="btn btn-sm btn-link text-body text-decoration-none text-start px-3 py-2 w-100 d-flex align-items-center justify-content-between gap-2 collapsed"
            data-bs-toggle="collapse"
            data-bs-target="#mail-signature-rightbar-body"
            aria-expanded="false"
            aria-controls="mail-signature-rightbar-body">
            <span class="fw-semibold small">
                <i class="bi bi-person-vcard me-1" aria-hidden="true"></i>Mail signature
            </span>
            <span class="d-flex align-items-center gap-1 mail-min-w-0">
                <span class="badge text-bg-light border text-truncate" style="max-width: 7rem;">{{ $mailSignature->name ?: 'Default' }}</span>
                <i class="bi bi-chevron-down flex-shrink-0" aria-hidden="true"></i>
            </span>
        </button>
    </div>
    <div id="mail-signature-rightbar-body" class="collapse" role="region" aria-labelledby="mail-signature-rightbar-heading">
        <div class="card-body py-2">
            <div class="small text-muted mb-2">Applied by Mail before SMTP.</div>
            <button
                type="button"
                id="mail-signature-modal-trigger"
                class="btn btn-sm btn-outline-secondary w-100"
                data-bs-toggle="modal"
                data-bs-target="#mail-signature-settings-modal"
                aria-haspopup="dialog"
                aria-controls="mail-signature-settings-modal">
                <i class="bi bi-pen me-1" aria-hidden="true"></i>Signature settings
            </button>
        </div>
    </div>
</div>

<!-- Signature mode settings stay in a viewport-bound Bootstrap dialog above the application shell. -->
<div
    id="mail-signature-settings-modal"
    class="modal fade"
    tabindex="-1"
    role="dialog"
    aria-labelledby="mail-signature-settings-modal-title"
    aria-describedby="mail-signature-settings-modal-description"
    aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
        <form class="modal-content text-body" method="POST" action="{{ route('tech.mail.signature.update') }}">
            @csrf
            @method('PATCH')
            <input type="hidden" name="signature_name" value="{{ $mailSignature->name ?: 'Default' }}">

            <div class="modal-header">
                <div>
                    <h2 id="mail-signature-settings-modal-title" class="modal-title h5">Mail signature</h2>
                    <div class="small text-muted">{{ $mailSignature->name ?: 'Default' }}</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <p id="mail-signature-settings-modal-description" class="small text-muted">
                    Choose which Mail composer modes apply this signature before SMTP.
                </p>

                <div class="d-grid gap-2">
                    @foreach($mailSignatureModes as $field => $label)
                        <input type="hidden" name="{{ $field }}" value="0">
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" id="mail_signature_modal_{{ $field }}" name="{{ $field }}" value="1" @checked($mailSignature->{$field})>
                            <label class="form-check-label" for="mail_signature_modal_{{ $field }}">{{ $label }}</label>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="modal-footer">
                <a href="{{ route('tech.profile.index') }}#mail-signature" class="btn btn-sm btn-outline-secondary me-auto">
                    Edit body
                </a>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="bi bi-save me-1" aria-hidden="true"></i>Save
                </button>
            </div>
        </form>
    </div>
</div>
