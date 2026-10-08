{{-- Customer synchronization is independent from payroll/time synchronization. --}}
@php
    $customerRuntimeAvailable = (bool) config('tripletex.enabled');
    $customerSyncEnabled = (bool) ($connection->config['customer_sync_enabled'] ?? false);
@endphp
<section class="card mb-3">
    <div class="card-header">Customer synchronization</div>
    <div class="card-body">
        {{-- Explain server setup before offering an activation that cannot succeed. --}}
        @unless($customerRuntimeAvailable)
            <div class="alert alert-warning" role="status" id="tripletex-customer-runtime-status">
                Customer synchronization is unavailable on this server.
                Ask the server administrator to enable customer synchronization before turning it on here.
                @if($customerSyncEnabled)
                    You can still pause the saved customer synchronization setting below.
                @endif
            </div>
        @endunless
        <form method="post" action="{{ route('tech.admin.system.integrations.tripletex.customer-sync', $connection->id) }}">
            @csrf
            <input type="hidden" name="version" value="{{ $connection->config['version'] }}">
            <input type="hidden" name="enabled" value="0">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" id="tripletex-customer-sync" name="enabled" value="1"
                    @checked($customerSyncEnabled) @disabled(!$customerRuntimeAvailable)
                    @unless($customerRuntimeAvailable) aria-describedby="tripletex-customer-runtime-status" @endunless>
                <label class="form-check-label" for="tripletex-customer-sync">Synchronize customers with Tripletex</label>
            </div>
            <p class="form-text">New Clients are created or explicitly linked in Tripletex and use the same customer number. Existing numbers change only through a reviewed link. Billing Email and the linked Site address synchronize both ways; Tripletex wins conflicts. Primary contacts are suggested at creation only.</p>
            <button class="btn btn-outline-primary" type="submit" @disabled(!$customerRuntimeAvailable && !$customerSyncEnabled)>
                {{ !$customerRuntimeAvailable && $customerSyncEnabled ? 'Pause customer synchronization' : 'Save customer synchronization' }}
            </button>
            @can('client.update')
                <a class="btn btn-outline-secondary" href="{{ route('tech.admin.system.integrations.tripletex.customers', $connection->id) }}">Review customer links</a>
            @endcan
        </form>
    </div>
</section>
