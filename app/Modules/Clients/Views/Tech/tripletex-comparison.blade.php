{{-- Shared identity comparison and explicit number adoption. --}}
<section class="card mb-3">
    <div class="card-header">Compare and confirm</div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6"><strong>Nexum</strong><p>{{ $review['client']->name }} · {{ $review['client']->client_number }}<br>{{ $review['client']->org_no }}</p></div>
            <div class="col-md-6"><strong>Tripletex</strong><p>{{ $review['remote']['name'] }} · {{ $review['remote']['customerNumber'] }}<br>{{ $review['remote']['organizationNumber'] ?? '' }}</p></div>
        </div>
        <form method="post" action="{{ $action }}">
            @csrf
            <input type="hidden" name="client_id" value="{{ $review['client']->id }}">
            <input type="hidden" name="customer_id" value="{{ $review['remote']['id'] }}">
            <input type="hidden" name="expected_local" value="{{ $review['client']->client_number }}">
            <input type="hidden" name="expected_remote" value="{{ $review['remote']['customerNumber'] }}">
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" id="accept-number" name="accept_change" value="1" required>
                <label class="form-check-label" for="accept-number">I confirm this is the same customer and accept the Tripletex customer number.</label>
            </div>
            <button class="btn btn-primary" type="submit">Save reviewed link</button>
        </form>
    </div>
</section>
