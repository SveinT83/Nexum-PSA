@props([
    'clients' => []
])

<form action="{{ route('tech.context.set') }}" method="POST" class="d-flex align-items-center" id="context-selector-form">
    @csrf
    @if(request('cat'))
        <input type="hidden" name="cat" value="{{ request('cat') }}">
    @endif
    <div class="input-group input-group-sm">
        <label class="input-group-text bg-light border-secondary-subtle fw-bold text-muted px-2" for="active_client_id_selector">
            <i class="bi bi-filter"></i> Context
        </label>
        <select name="active_client_id" id="active_client_id_selector" class="form-select border-secondary-subtle" onchange="this.form.submit()">
            <option value="none">All (Internal + All Clients)</option>
            <option value="internal" {{ session('only_internal') ? 'selected' : '' }}>Internal Only</option>
            @if(count($clients) > 0)
                <optgroup label="Clients">
                    @foreach($clients as $client)
                        <option value="{{ $client->id }}" {{ (session('active_client_id') == $client->id) ? 'selected' : '' }}>
                            {{ $client->name }}
                        </option>
                    @endforeach
                </optgroup>
            @endif
        </select>
    </div>
</form>
