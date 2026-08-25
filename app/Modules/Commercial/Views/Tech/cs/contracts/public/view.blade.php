<!DOCTYPE html>
<html lang="nb">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $customerDocument['document']['type'] }} #{{ $customerDocument['document']['contract_number'] }} – {{ $customerDocument['parties']['customer']['name'] }}</title>
    @PwaHead
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #f8f9fa; }
        .contract-container { max-width: 1100px; margin: 40px auto; background: white; padding: 48px; box-shadow: 0 .5rem 1rem rgba(0, 0, 0, .15); border-radius: 8px; }
        @media print {
            body { background: white; }
            .contract-container { margin: 0; padding: 0; box-shadow: none; max-width: 100%; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
<main class="container pb-5">
    <div class="contract-container">
        @if(session('success'))
            <div class="alert alert-success border-0 shadow-sm mb-4">
                <i class="bi bi-check-circle-fill me-2" aria-hidden="true"></i>{{ session('success') }}
            </div>
        @endif
        @if(session('error'))
            <div class="alert alert-danger border-0 shadow-sm mb-4">
                <i class="bi bi-exclamation-octagon-fill me-2" aria-hidden="true"></i>{{ session('error') }}
            </div>
        @endif

        @include('commercial::Shared.customer-document-web', ['customerDocument' => $customerDocument])

        <div class="no-print mt-5 pt-4 border-top">
            @if(in_array($contract->approval_status, ['sent_quote', 'sent_contract'], true))
                <div class="card border-primary shadow-sm">
                    <div class="card-body p-4">
                        <h2 class="h5 mb-3">Godkjenn dokumentet</h2>
                        <form action="{{ route('contracts.public.accept', $contract->secure_token) }}" method="POST">
                            @csrf
                            <div class="mb-3">
                                <label for="name" class="form-label fw-bold">Fullt navn</label>
                                <input type="text" name="name" id="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name') }}" required>
                                @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="mb-3 form-check">
                                <input type="checkbox" name="confirm" value="1" id="confirm" class="form-check-input @error('confirm') is-invalid @enderror" required>
                                <label class="form-check-label" for="confirm">
                                    Jeg bekrefter at jeg har lest og godtar dokumentet med oppførte vilkår og vedlegg.
                                </label>
                                @error('confirm') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <button type="submit" class="btn btn-primary btn-lg w-100">
                                <i class="bi bi-check2-circle me-2" aria-hidden="true"></i>Godkjenn
                            </button>
                        </form>
                    </div>
                </div>
            @endif
        </div>

        <footer class="mt-5 text-center small text-muted">
            <p>© {{ date('Y') }} {{ $customerDocument['parties']['supplier']['name'] }}</p>
            <button onclick="window.print()" class="btn btn-sm btn-link no-print">
                <i class="bi bi-printer me-1" aria-hidden="true"></i>Skriv ut
            </button>
        </footer>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
@RegisterServiceWorkerScript
</body>
</html>
