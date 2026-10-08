{{-- Explicit selection preserves identity; suggestions alone never link customers. --}}
<input type="hidden" name="tripletex_number_mode" value="1">
<input type="hidden" name="tripletex_request_key" value="{{ old('tripletex_request_key', (string) \Illuminate\Support\Str::uuid()) }}">
<input type="hidden" id="tripletex-customer-id" name="tripletex_customer_id" value="{{ old('tripletex_customer_id') }}">
<div class="mb-3 border-bottom pb-3" id="tripletex-customer-picker">
    <label for="tripletex-customer-search" class="form-label">Existing Tripletex customer</label>
    <div class="input-group">
        <input type="search" id="tripletex-customer-search" class="form-control" placeholder="Search name, customer number or organization number" autocomplete="off">
        <button class="btn btn-outline-secondary" type="button" id="tripletex-customer-search-button">Search</button>
        <button class="btn btn-outline-secondary" type="button" id="tripletex-customer-clear">Create new</button>
    </div>
    <div class="list-group mt-1" id="tripletex-customer-results"></div>
    <p class="form-text mb-0" id="tripletex-customer-selection" role="status">
        {{ old('tripletex_customer_id') ? 'Existing customer selected. Search again to review the selection.' : 'A new customer will be created in Tripletex and Nexum. Select an existing customer to reuse its number.' }}
    </p>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('tripletex-customer-picker');
    const results = document.getElementById('tripletex-customer-results');
    const status = document.getElementById('tripletex-customer-selection');
    const id = document.getElementById('tripletex-customer-id');
    const form = root.closest('form');
    let searchVersion = 0;
    let detailVersion = 0;
    const touched = new Set();
    const autoFields = ['billing_email', 'site_name', 'site_address', 'site_co_address', 'site_zip', 'site_city', 'site_country', 'user_name', 'user_email', 'user_phone'];
    const defaults = {site_name: 'General sites', user_name: 'General contact'};
    autoFields.forEach(key => {
        const input = form.elements[key];
        if (!input) return;
        if (input.value && input.value !== defaults[key]) touched.add(key);
        input.addEventListener('input', () => touched.add(key));
        input.addEventListener('change', () => touched.add(key));
    });
    const fill = values => autoFields.forEach(key => {
        const input = form.elements[key];
        if (input && !touched.has(key)) input.value = values[key] || defaults[key] || '';
    });
    const loadProfile = async (customer, selectedLabel) => {
        const version = ++detailVersion;
        fill({});
        status.textContent = 'Selected: ' + selectedLabel + '. Loading details…';
        try {
            const url = @json(route('tech.clients.tripletex.profile', ['customer' => 0])).replace('/0/profile', '/' + customer.id + '/profile');
            const response = await fetch(url, {headers: {'Accept': 'application/json'}, credentials: 'same-origin'});
            const body = await response.json();
            if (version !== detailVersion || String(id.value) !== String(customer.id)) return;
            if (!response.ok) throw new Error(body.message || 'Customer details could not be loaded.');
            fill(body.data);
            status.textContent = 'Selected: ' + selectedLabel + '. Review the address, billing email and contact before saving.';
        } catch (error) {
            if (version === detailVersion) status.textContent = 'Selected: ' + selectedLabel + '. ' + error.message;
        }
    };
    document.getElementById('tripletex-customer-search-button').addEventListener('click', async () => {
        const version = ++searchVersion;
        const q = document.getElementById('tripletex-customer-search').value.trim();
        results.replaceChildren();
        if (q.length < 2) { status.textContent = 'Enter at least two characters.'; return; }
        status.textContent = 'Searching Tripletex…';
        try {
            const response = await fetch(@json(route('tech.clients.tripletex.lookup')) + '?q=' + encodeURIComponent(q), {
                headers: {'Accept': 'application/json'}, credentials: 'same-origin'
            });
            const body = await response.json();
            if (version !== searchVersion) return;
            if (!response.ok) throw new Error(body.message || 'Tripletex could not be checked.');
            status.textContent = body.data.length ? 'Select the intended customer.' : 'No matching customers found.';
            body.data.forEach(customer => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'list-group-item list-group-item-action';
                button.textContent = customer.customerNumber + ' · ' + customer.name + (customer.organizationNumber ? ' · ' + customer.organizationNumber : '');
                button.addEventListener('click', () => {
                    id.value = customer.id;
                    form.elements.client_number.value = customer.customerNumber;
                    form.elements.name.value = customer.name;
                    form.elements.org_no.value = customer.organizationNumber || '';
                    results.replaceChildren();
                    loadProfile(customer, button.textContent);
                });
                results.append(button);
            });
        } catch (error) {
            if (version === searchVersion) status.textContent = error.message;
        }
    });
    document.getElementById('tripletex-customer-clear').addEventListener('click', () => {
        searchVersion++;
        detailVersion++;
        fill({});
        id.value = '';
        form.elements.client_number.value = @json($suggestedClientNumber);
        results.replaceChildren();
        status.textContent = 'A new customer will be created in Tripletex and Nexum.';
    });
});
</script>
