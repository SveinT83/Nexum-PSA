{{-- Shared customer document: all customer web surfaces use this six-column projection. --}}
<section class="customer-contract-document">
    <!-- Document identity and parties -->
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 border-bottom pb-3 mb-4">
        <div>
            <h2 class="h4 mb-1">{{ $customerDocument['document']['type'] }} #{{ $customerDocument['document']['contract_number'] }}</h2>
            <div class="text-muted">{{ $customerDocument['document']['status'] }}</div>
            @if(filled($customerDocument['description']))
                <div class="mt-2" style="white-space: pre-wrap; overflow-wrap: anywhere;">{{ $customerDocument['description'] }}</div>
            @endif
        </div>
        <div class="text-md-end small">
            <div class="fw-semibold">{{ $customerDocument['parties']['customer']['name'] }}</div>
            @if(filled($customerDocument['parties']['customer']['organization_number']))
                <div class="text-muted">Org.nr. {{ $customerDocument['parties']['customer']['organization_number'] }}</div>
            @endif
        </div>
    </div>

    <div class="row g-3 mb-4">
        @foreach($customerDocument['parties'] as $party)
            <div class="col-md-6">
                <div class="border rounded p-3 h-100">
                    <div class="small text-uppercase text-muted">{{ $party['label'] }}</div>
                    <div class="fw-semibold">{{ $party['name'] }}</div>
                    @if(filled($party['organization_number']))
                        <div class="small">Org.nr. {{ $party['organization_number'] }}</div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    <!-- Contract period -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-body">
            <h3 class="h6 mb-0">Avtaleperiode</h3>
        </div>
        <div class="card-body">
            <dl class="row small mb-0">
                @foreach($customerDocument['dates'] as $date)
                    @if(filled($date['value']))
                        <dt class="col-sm-4 text-muted">{{ $date['label'] }}</dt>
                        <dd class="col-sm-8">{{ $date['value'] }}</dd>
                    @endif
                @endforeach
            </dl>
        </div>
    </div>

    <!-- Customer-facing service table: exactly six approved columns. -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-body">
            <h3 class="h6 mb-0">Tjenester</h3>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        @foreach(array_keys(\App\Modules\Commercial\Support\ContractCustomerDocument::COLUMNS) as $columnKey)
                            <th>{{ $customerDocument['columns'][$columnKey] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse($customerDocument['lines'] as $line)
                        <tr>
                            <td class="fw-semibold">{{ $line['service'] }}</td>
                            <td style="white-space: pre-wrap; overflow-wrap: anywhere; min-width: 13rem;">{{ $line['short_description'] }}</td>
                            <td class="text-nowrap">{{ $line['scope'] }}</td>
                            <td class="text-end text-nowrap">{{ $line['unit_price']['display'] }}</td>
                            <td>
                                <span class="text-nowrap">{{ $line['billing']['label'] }}</span>
                                @if(($line['billing']['setup_fee']['minor'] ?? 0) > 0)
                                    <div class="small text-muted text-nowrap">Oppstart: {{ $line['billing']['setup_fee']['display'] }}</div>
                                @endif
                            </td>
                            <td class="text-end fw-semibold text-nowrap">{{ $line['total']['display'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">Ingen tjenester er lagt til.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Exact totals remain separate by cadence; zero summaries are omitted. -->
    <div class="row g-3 mb-4">
        @foreach([
            'monthly' => 'Månedlig beløp eks. mva.',
            'quarterly' => 'Kvartalsbeløp eks. mva.',
            'yearly' => 'Årlig beløp eks. mva.',
            'one_time' => 'Engangsbeløp eks. mva.',
        ] as $cadence => $label)
            @if(($customerDocument['totals'][$cadence]['minor'] ?? 0) > 0)
                <div class="col-sm-6 col-xl-3">
                    <div class="border rounded bg-light p-3 h-100">
                        <div class="small text-muted">{{ $label }}</div>
                        <div class="fw-bold">{{ $customerDocument['totals'][$cadence]['display'] }}</div>
                    </div>
                </div>
            @endif
        @endforeach
    </div>

    @if($customerDocument['rates'])
        <!-- Customer-visible rates are explicitly classified and globally deduplicated. -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-body">
                <h3 class="h6 mb-0">{{ $customerDocument['rates']['title'] }}</h3>
            </div>
            <ul class="list-group list-group-flush">
                @foreach($customerDocument['rates']['items'] as $rate)
                    <li class="list-group-item d-flex justify-content-between gap-3">
                        <span>{{ $rate['name'] }}</span>
                        <span class="fw-semibold text-nowrap">{{ $rate['display'] }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if($customerDocument['support'])
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-body">
                <h3 class="h6 mb-0">{{ $customerDocument['support']['title'] }}</h3>
            </div>
            <div class="card-body small" style="white-space: pre-wrap; overflow-wrap: anywhere;">{{ $customerDocument['support']['content'] }}</div>
        </div>
    @endif

    @foreach($customerDocument['appendices'] as $appendix)
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-body d-flex flex-wrap justify-content-between gap-2">
                <h3 class="h6 mb-0">Vedlegg {{ $appendix['number'] }} – {{ $appendix['title'] }}</h3>
                <span class="small text-muted">Versjon {{ $appendix['version'] }} · {{ $appendix['date'] }}</span>
            </div>
            <div class="card-body small" style="white-space: pre-wrap; overflow-wrap: anywhere;">{{ $appendix['content'] }}</div>
        </div>
    @endforeach

    @if($customerDocument['approval']['accepted'])
        <div class="alert alert-success">
            <div class="fw-semibold">{{ $customerDocument['approval']['title'] }}</div>
            <div class="small">{{ $customerDocument['approval']['text'] }}</div>
        </div>
    @endif
</section>
