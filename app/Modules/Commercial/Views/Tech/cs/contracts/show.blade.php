@extends('layouts.default_tech')

@section('title', 'Kontrakt')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <div>
            @if($validation['legacy_attestation_preview_available'])
                <h1>{{ $customerDocument['document']['type'] }} #{{ $customerDocument['document']['contract_number'] }} – forhåndsvisning</h1>
                <p class="text-muted mb-0 small">Kunde: <strong>{{ $customerDocument['parties']['customer']['name'] }}</strong></p>
            @else
                <h1>Historisk kundedokument – dokumenttype må velges</h1>
                <p class="text-muted mb-0 small">Ingen rekonstruksjon vises før originalunderlaget har fastslått dokumenttypen.</p>
            @endif
        </div>
        <x-buttons.back url="{{ route('tech.contracts.index') }}">Tilbake</x-buttons.back>
    </div>
@endsection

@section('content')
    <div class="row g-4">
        <div class="col-xl-9">
            @if($validation['customer_document_evidence_blockers'] !== [])
                <div class="alert alert-danger border-0 shadow-sm mb-4" role="alert">
                    <h2 class="h6 mb-2">Historisk kundedokument er sperret</h2>
                    <ul class="mb-2 small">
                        @foreach($validation['customer_document_evidence_blockers'] as $blocker)
                            <li>{{ $blocker }}</li>
                        @endforeach
                    </ul>
                    @if($validation['legacy_attestation_preview_available'])
                        <p class="mb-0 small">
                            Visningen under er kun et internt, skrivebeskyttet rekonstruksjonsgrunnlag.
                            PDF, kundelenke og kundeportal er sperret til grunnlaget er sammenlignet med
                            originalt sendt eller godkjent underlag og attestert av en navngitt tekniker.
                        </p>
                    @else
                        <p class="mb-0 small">
                            Ingen rekonstruksjon vises før dokumenttypen er kontrollert mot originalunderlaget.
                            PDF, kundelenke og kundeportal forblir sperret.
                        </p>
                    @endif

                    @if($validation['legacy_attestation_available'])
                        <hr>
                        <h3 class="h6">Attester kontrollert rekonstruksjon</h3>
                        <p class="small">
                            Dagens parts- og tjenestedata er ikke i seg selv historisk bevis. Kontroller
                            alle viste parter, beløp, perioder og vedlegg mot originalunderlaget før du
                            fryser denne rekonstruksjonen. Et eksisterende snapshot blir aldri erstattet.
                        </p>

                        @if($validation['legacy_attestation_document_type_ambiguous'])
                            <p class="small fw-semibold mb-2">
                                Statusen beviser ikke om originalen var et tilbud eller en avtale. Velg
                                typen som originalunderlaget dokumenterer, og kontroller den nye visningen.
                            </p>
                            <div class="btn-group btn-group-sm mb-3" role="group" aria-label="Historisk dokumenttype">
                                @foreach(['Tilbud', 'Avtale'] as $documentType)
                                    <a href="{{ route('tech.contracts.show', ['contract' => $contract, 'legacy_document_type' => $documentType]) }}"
                                       class="btn {{ $validation['legacy_attestation_document_type'] === $documentType ? 'btn-danger' : 'btn-outline-danger' }}">
                                        Kontroller som {{ strtolower($documentType) }}
                                    </a>
                                @endforeach
                            </div>
                        @endif

                        @if($validation['customer_document_missing_identity'] !== [])
                            <p class="small fw-semibold mb-1">Rekonstruksjonen mangler nødvendige partsdata:</p>
                            <ul class="small">
                                @foreach($validation['customer_document_missing_identity'] as $missingIdentity)
                                    <li>{{ ucfirst($missingIdentity) }} mangler i dagens grunnlag.</li>
                                @endforeach
                            </ul>
                        @endif

                        @if($validation['legacy_attestation_preview_available'])
                            <form action="{{ route('tech.contracts.customer-document.attest-legacy', $contract) }}"
                                  method="POST"
                                  onsubmit="return confirm('Har du kontrollert hele rekonstruksjonen mot originalunderlaget? Snapshotet kan ikke erstattes etterpå.')">
                            @csrf
                            <input type="hidden" name="legacy_attestation_fingerprint"
                                   value="{{ $validation['legacy_attestation_fingerprint'] }}">
                            <input type="hidden" name="legacy_attestation_document_type"
                                   value="{{ $validation['legacy_attestation_document_type'] }}">
                            <label for="attestation_note" class="form-label small fw-semibold">
                                Kontrollnotat og referanse til originalunderlag
                            </label>
                            <textarea id="attestation_note" name="attestation_note" rows="3"
                                      class="form-control form-control-sm mb-2"
                                      minlength="20" maxlength="2000" required>{{ old('attestation_note') }}</textarea>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" value="1"
                                       id="confirm_legacy_attestation" name="confirm_legacy_attestation" required>
                                <label class="form-check-label small" for="confirm_legacy_attestation">
                                    Jeg har kontrollert hele dokumentet mot originalt sendt eller godkjent underlag.
                                </label>
                            </div>
                            <button type="submit" class="btn btn-sm btn-outline-danger"
                                    @disabled($validation['customer_document_missing_identity'] !== [] || $validation['legacy_attestation_fingerprint'] === null || $validation['legacy_attestation_document_type'] === null)>
                                Attester og frys rekonstruksjonen
                            </button>
                            @if($validation['legacy_attestation_document_type_ambiguous'] && $validation['legacy_attestation_document_type'] === null)
                                <div class="small text-danger mt-2">
                                    Velg dokumenttypen originalunderlaget beviser før attestering.
                                </div>
                            @elseif($validation['legacy_attestation_fingerprint'] === null && $validation['customer_document_missing_identity'] === [])
                                <div class="small text-danger mt-2">
                                    Rekonstruksjonen er ikke et komplett v1-dokument og kan ikke attesteres.
                                </div>
                            @endif
                            </form>
                        @endif
                    @endif
                </div>
            @endif

            @if($validation['show_readiness_status'] && !$validation['ready'])
                <div class="alert alert-warning border-0 shadow-sm d-flex align-items-center mb-4">
                    <i class="bi bi-exclamation-triangle-fill fs-4 me-3" aria-hidden="true"></i>
                    <div>
                        <h2 class="h6 mb-1">Kontrakten er ikke klar</h2>
                        <ul class="mb-0 small">
                            @if(!$validation['has_items']) <li>Minst én tjeneste må legges til.</li> @endif
                            @if(!$validation['has_terms']) <li>Vilkårssnapshot mangler.</li> @endif
                            @if($validation['has_missing_terms'] && $validation['has_terms']) <li>Vilkårene må oppdateres etter tjenesteendringen.</li> @endif
                            @if(!$validation['future_start_date']) <li>Avtalestart må være i fremtiden før utsending.</li> @endif
                            @if(!$validation['valid_contract_period'] && $contract->start_date) <li>Bindingstiden må ligge innenfor avtalens start- og sluttdato.</li> @endif
                            @foreach($validation['customer_document_missing_identity'] as $missingIdentity)
                                <li>{{ ucfirst($missingIdentity) }} mangler.</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @elseif($validation['show_readiness_status'])
                <div class="alert alert-success border-0 shadow-sm mb-4">
                    <i class="bi bi-check-circle-fill me-2" aria-hidden="true"></i>Kontrakten er klar for utsending.
                </div>
            @endif

            @if($validation['legacy_attestation_preview_available'])
                @include('commercial::Shared.customer-document-web', ['customerDocument' => $customerDocument])
            @else
                <div class="alert alert-secondary border-0 shadow-sm" role="status">
                    Velg «Kontroller som tilbud» eller «Kontroller som avtale» ovenfor for å bygge riktig,
                    skrivebeskyttet rekonstruksjon.
                </div>
            @endif
        </div>

        <div class="col-xl-3">
            @if($contract->approval_status === 'won' && $validation['legacy_attestation_preview_available'])
                <div class="alert alert-success border-0 shadow-sm">
                    <div class="fw-semibold"><i class="bi bi-trophy-fill me-2" aria-hidden="true"></i>Godkjent</div>
                    <div class="small">{{ $customerDocument['approval']['text'] }}</div>
                </div>
            @endif

            <x-card.default title="Handlinger">
                <div class="d-grid gap-2">
                    <div>
                        <label for="cc_email" class="form-label small fw-bold">Kopi til e-post</label>
                        <input type="email" id="cc_email" class="form-control form-control-sm" value="{{ $contract->cc_email }}">
                    </div>

                    @if($contract->isEditable())
                        <form action="{{ route('tech.contracts.send-quote', $contract) }}" method="POST">
                            @csrf
                            <input type="hidden" name="cc_email" class="cc-input-sync" value="{{ $contract->cc_email }}">
                            <button type="submit" class="btn btn-outline-primary w-100" @disabled(!$validation['ready'])>
                                <i class="bi bi-send me-2" aria-hidden="true"></i>Send tilbud
                            </button>
                        </form>
                        <form action="{{ route('tech.contracts.send-contract', $contract) }}" method="POST">
                            @csrf
                            <input type="hidden" name="cc_email" class="cc-input-sync" value="{{ $contract->cc_email }}">
                            <button type="submit" class="btn btn-primary w-100" @disabled(!$validation['ready'])>
                                <i class="bi bi-send-check me-2" aria-hidden="true"></i>Send avtale
                            </button>
                        </form>
                    @endif

                    @if(in_array($contract->approval_status, ['sent_quote', 'sent_contract'], true) && $validation['customer_access_available'])
                        <a href="{{ route('contracts.public.view', $contract->secure_token) }}" target="_blank" rel="noopener" class="btn btn-outline-info">
                            <i class="bi bi-link-45deg me-1" aria-hidden="true"></i>Offentlig kundelenke
                        </a>
                        <form action="{{ route('tech.contracts.resend', $contract) }}" method="POST">
                            @csrf
                            <input type="hidden" name="cc_email" class="cc-input-sync" value="{{ $contract->cc_email }}">
                            <button type="submit" class="btn btn-outline-info w-100">Send e-post på nytt</button>
                        </form>
                    @endif

                    @if(in_array($contract->approval_status, ['draft', 'negotiation', 'quote_lost', 'sent_quote', 'sent_contract'], true))
                        <form action="{{ route('tech.contracts.approve-manual', $contract) }}" method="POST" onsubmit="return confirm('Vil du godkjenne kontrakten manuelt?')">
                            @csrf
                            <button type="submit" class="btn btn-success w-100"
                                    @disabled($validation['customer_document_missing_identity'] !== [] || $validation['customer_document_evidence_blockers'] !== [])>
                                <i class="bi bi-check2-all me-2" aria-hidden="true"></i>Godkjenn manuelt
                            </button>
                        </form>
                    @endif

                    @if($validation['pdf_available'])
                        <a href="{{ route('tech.contracts.pdf', $contract) }}" class="btn btn-outline-dark">
                            <i class="bi bi-file-earmark-pdf me-2" aria-hidden="true"></i>Last ned PDF
                        </a>
                    @endif

                    @if($contract->isEditable())
                        <hr>
                        <a href="{{ route('tech.contracts.edit', $contract) }}" class="btn btn-sm btn-outline-warning">Rediger kontraktsdata</a>
                        <a href="{{ route('tech.contracts.services.edit', $contract) }}" class="btn btn-sm btn-outline-warning">Rediger tjenester</a>
                        <a href="{{ route('tech.contracts.terms', $contract) }}" class="btn btn-sm btn-outline-warning">Rediger vilkår</a>
                    @endif
                </div>
            </x-card.default>

            <!-- Internal operational metadata remains outside the customer document. -->
            <x-card.default title="Intern kontraktsinfo">
                <dl class="small mb-0">
                    <dt class="text-muted">Intern status</dt>
                    <dd>{{ ucfirst(str_replace('_', ' ', $contract->approval_status)) }}</dd>
                    <dt class="text-muted">Intern SLA-kobling</dt>
                    <dd>{{ $contract->sla?->name ?? $defaultSla?->name ?? 'Ikke konfigurert' }}</dd>
                </dl>
            </x-card.default>
        </div>
    </div>

    <script>
        document.getElementById('cc_email')?.addEventListener('input', function () {
            document.querySelectorAll('.cc-input-sync').forEach((input) => input.value = this.value);
        });
    </script>
@endsection

@section('sidebar')
    <x-nav.sales-menu />
@endsection

@section('rightbar')
@endsection
