{{-- Candidate discovery is read-only. Every customer identity requires an explicit choice. --}}
@can('client.view')
<div id="registration-candidates" class="mb-3" aria-live="polite">
    <p class="form-text mb-1" data-status></p>
    <div class="list-group" data-choices></div>
    <label class="form-check mt-2" data-distinct hidden>
        <input class="form-check-input" name="confirm_distinct" type="checkbox" value="1">
        <span class="form-check-label">I reviewed the matches and need a separate customer.</span>
    </label>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('registration-candidates');
    const form = root.closest('form');
    const status = root.querySelector('[data-status]');
    const list = root.querySelector('[data-choices]');
    const distinct = root.querySelector('[data-distinct]');
    const fields = {email: 'user_email', billing_email: 'billing_email', phone: 'user_phone', org_no: 'org_no'};
    let timer, controller, version = 0;

    function reset() {
        version++;
        clearTimeout(timer);
        controller?.abort();
        list.replaceChildren();
        status.textContent = '';
        distinct.hidden = true;
        form.elements.confirm_distinct.checked = false;
    }

    function render(choices) {
        list.replaceChildren();
        status.textContent = choices.length ? 'Possible existing customers. Review the matches before creating a customer.' : '';
        distinct.hidden = choices.length === 0;
        for (const choice of choices) {
            const local = choice.action === 'use_existing';
            const element = document.createElement(local ? 'a' : 'button');
            element.className = 'list-group-item list-group-item-action';
            const action = local ? 'Use Nexum customer' : choice.action === 'review' ? 'Administrator review required' : 'Use Tripletex customer';
            const fieldNames = {org_no: 'organization number', email: 'email', phone: 'phone'};
            element.textContent = action + ' · ' + choice.number + ' · ' + choice.name + ' (' + choice.matched_fields.map(key => fieldNames[key]).join(', ') + ')';
            if (local) {
                element.href = @json(route('tech.clients.show', ['client' => '__client__'])).replace('__client__', String(choice.id));
            } else {
                element.type = 'button';
                element.disabled = choice.action === 'review';
                element.addEventListener('click', () => form.dispatchEvent(new CustomEvent('registration:select-provider', {detail: choice})));
            }
            list.append(element);
        }
    }

    function search() {
        reset();
        const data = {};
        for (const [key, name] of Object.entries(fields)) {
            const input = form.elements[name];
            const value = input.value.trim();
            if (key === 'phone' && value.replace(/\D/g, '').length < 7) continue;
            if (key === 'org_no' && value.replace(/[\s-]/g, '').length < 6) continue;
            if (input.type === 'email' && !input.validity.valid) continue;
            if (value) data[key] = value;
        }
        if (!Object.keys(data).length || form.elements.tripletex_customer_id?.value) return;
        const current = version;
        timer = setTimeout(async () => {
            controller = new AbortController();
            try {
                const response = await fetch(@json(route('tech.clients.registration-candidates')), {
                    method: 'POST', credentials: 'same-origin', signal: controller.signal,
                    headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': form.elements._token.value},
                    body: JSON.stringify(data)
                });
                if (!response.ok) throw new Error();
                const body = await response.json();
                if (current === version) render(body.candidates);
            } catch (error) {
                if (current === version) status.textContent = 'Existing customers could not be checked. Try again before saving.';
            }
        }, 500);
    }

    Object.values(fields).forEach(name => form.elements[name].addEventListener('input', search));
    form.addEventListener('registration:provider-selected', reset);
    document.getElementById('tripletex-customer-clear')?.addEventListener('click', search);
    const initial = @json(session('registration_candidates', []));
    if (initial.length) render(initial); else search();
});
</script>
@endcan
