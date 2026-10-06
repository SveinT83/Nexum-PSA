@php($fieldId = 'tripletex-'.($connection?->id ?? 'new'))
<form method="post" action="{{ $connection ? route('tech.admin.system.integrations.tripletex.update', $connection->id) : route('tech.admin.system.integrations.tripletex.store') }}" autocomplete="off">
    @csrf
    @if($connection) @method('PUT') @endif
    <input type="hidden" name="version" value="{{ $connection?->config['version'] ?? 0 }}">
    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label" for="{{ $fieldId }}-name">Name</label>
            <input class="form-control" id="{{ $fieldId }}-name" name="name" value="{{ $connection?->name }}" maxlength="100" required>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="{{ $fieldId }}-environment">Environment</label>
            <select class="form-select" id="{{ $fieldId }}-environment" name="environment">
                <option value="test" @selected(($connection?->config['environment'] ?? 'test') === 'test')>Test</option>
                <option value="production" @selected(($connection?->config['environment'] ?? '') === 'production')>Production</option>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="{{ $fieldId }}-company">Tripletex company ID</label>
            <input class="form-control" id="{{ $fieldId }}-company" name="company_id" type="number" min="1" value="{{ $connection?->config['company_id'] }}" required>
        </div>
        <div class="col-md-8">
            <label class="form-label" for="{{ $fieldId }}-token">API token</label>
            <input class="form-control" id="{{ $fieldId }}-token" name="refresh_token" type="password" autocomplete="new-password" maxlength="8000" placeholder="{{ $connection ? 'Leave blank to keep the existing token' : 'Enter your company API token' }}" @required(!$connection)>
        </div>
    </div>
    <button class="btn btn-primary mt-3" type="submit">Save connection</button>
</form>
