{{-- Web Push device lifecycle --}}
<section
    id="webPushDeviceManager"
    class="card shadow-sm mb-4"
    data-list-url="{{ route('tech.profile.notifications.web-push.devices.index') }}"
    data-store-url="{{ route('tech.profile.notifications.web-push.devices.store') }}"
    data-current-url="{{ route('tech.profile.notifications.web-push.devices.current') }}"
    data-destroy-url-template="{{ route('tech.profile.notifications.web-push.devices.destroy', ['subscription' => '__subscription__']) }}"
    data-test-url="{{ route('tech.profile.notifications.web-push.test') }}"
>
    <div class="card-header py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h2 class="h6 mb-0">
                <i class="bi bi-bell-fill me-1"></i>
                Web Push devices
            </h2>
        </div>
        <span id="webPushDeviceCount" class="badge text-bg-secondary">0 devices</span>
    </div>
    <div class="card-body">
        <script type="application/json" id="webPushReadinessData">@json($webPushReadiness)</script>

        <div id="webPushStatus" class="alert alert-secondary py-2 mb-3" role="status">
            Checking this browser and device…
        </div>

        <p class="small text-muted mb-3">
            Enable Web Push only on devices you control. Nexum never displays or returns browser
            endpoint and encryption keys in the device inventory. Registering a device does not
            enable any business-event notification.
        </p>

        <div id="webPushIosHelp" class="alert alert-info py-2 d-none">
            On iPhone and iPad, install Nexum on the Home Screen and open the installed app before
            enabling Web Push.
        </div>

        <div class="d-flex flex-wrap gap-2 mb-3">
            <button type="button" id="webPushEnableButton" class="btn btn-primary btn-sm" disabled>
                <i class="bi bi-bell-plus me-1"></i>
                Enable on this device
            </button>
            <button type="button" id="webPushTestButton" class="btn btn-outline-primary btn-sm" disabled>
                <i class="bi bi-lightning me-1"></i>
                Send test to this device
            </button>
            <button type="button" id="webPushRefreshButton" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-clockwise me-1"></i>
                Refresh
            </button>
        </div>

        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Device</th>
                        <th>Browser / platform</th>
                        <th>Registered</th>
                        <th>Last seen</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody id="webPushDeviceRows">
                    <tr>
                        <td colspan="5" class="text-muted">Loading registered devices…</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</section>

@section('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const root = document.getElementById('webPushDeviceManager');
        const readinessElement = document.getElementById('webPushReadinessData');

        if (!root || !readinessElement) {
            return;
        }

        const readiness = JSON.parse(readinessElement.textContent || '{}');
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const statusElement = document.getElementById('webPushStatus');
        const rowsElement = document.getElementById('webPushDeviceRows');
        const countElement = document.getElementById('webPushDeviceCount');
        const enableButton = document.getElementById('webPushEnableButton');
        const testButton = document.getElementById('webPushTestButton');
        const refreshButton = document.getElementById('webPushRefreshButton');
        const iosHelp = document.getElementById('webPushIosHelp');

        let currentSubscription = null;
        let currentDeviceId = null;

        function setStatus(message, level = 'secondary') {
            statusElement.className = `alert alert-${level} py-2 mb-3`;
            statusElement.textContent = message;
        }

        function setBusy(busy) {
            refreshButton.disabled = busy;
            enableButton.disabled = busy || !canEnable();
            testButton.disabled = busy || !currentSubscription || !currentDeviceId || !readiness.ready;
        }

        function canEnable() {
            return Boolean(
                readiness.ready
                && window.isSecureContext
                && 'serviceWorker' in navigator
                && 'PushManager' in window
                && 'Notification' in window
                && Notification.permission !== 'denied'
                && !requiresIosHomeScreen()
            );
        }

        function requiresIosHomeScreen() {
            const userAgent = navigator.userAgent || '';
            const ios = /iPad|iPhone|iPod/.test(userAgent);
            const standalone = window.matchMedia('(display-mode: standalone)').matches
                || navigator.standalone === true;

            return ios && !standalone;
        }

        function requestOptions(method, body = null) {
            const options = {
                method,
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
            };

            if (body !== null) {
                options.headers['Content-Type'] = 'application/json';
                options.body = JSON.stringify(body);
            }

            return options;
        }

        async function fetchJson(url, options = requestOptions('GET')) {
            const response = await fetch(url, options);
            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(payload.message || 'The Web Push request failed.');
            }

            return payload;
        }

        async function sharedWorkerRegistration() {
            const existing = await navigator.serviceWorker.getRegistration('/');

            if (existing) {
                return existing;
            }

            return navigator.serviceWorker.register('/sw.js', {scope: '/'});
        }

        function subscriptionPayload(subscription) {
            const payload = subscription.toJSON();
            const supportedEncodings = PushManager.supportedContentEncodings || [];

            return {
                endpoint: payload.endpoint,
                keys: payload.keys,
                content_encoding: supportedEncodings[0] || 'aes128gcm',
            };
        }

        function publicKeyBytes(value) {
            const padding = '='.repeat((4 - value.length % 4) % 4);
            const base64 = (value + padding).replace(/-/g, '+').replace(/_/g, '/');
            const raw = window.atob(base64);
            const bytes = new Uint8Array(raw.length);

            for (let index = 0; index < raw.length; index++) {
                bytes[index] = raw.charCodeAt(index);
            }

            return bytes;
        }

        function formatDate(value) {
            if (!value) {
                return '—';
            }

            const date = new Date(value);

            return Number.isNaN(date.getTime())
                ? '—'
                : new Intl.DateTimeFormat(undefined, {
                    dateStyle: 'medium',
                    timeStyle: 'short',
                }).format(date);
        }

        function destroyUrl(deviceId) {
            return root.dataset.destroyUrlTemplate.replace('__subscription__', encodeURIComponent(deviceId));
        }

        function renderDevices(devices) {
            rowsElement.textContent = '';
            countElement.textContent = `${devices.length} ${devices.length === 1 ? 'device' : 'devices'}`;

            if (devices.length === 0) {
                const row = document.createElement('tr');
                const cell = document.createElement('td');
                cell.colSpan = 5;
                cell.className = 'text-muted';
                cell.textContent = 'No Web Push devices are registered.';
                row.appendChild(cell);
                rowsElement.appendChild(row);

                return;
            }

            devices.forEach(function (device) {
                const row = document.createElement('tr');
                const labelCell = document.createElement('td');
                const label = document.createElement('span');
                label.className = 'fw-semibold';
                label.textContent = device.label;
                labelCell.appendChild(label);

                if (device.id === currentDeviceId) {
                    const badge = document.createElement('span');
                    badge.className = 'badge text-bg-primary ms-2';
                    badge.textContent = 'This device';
                    labelCell.appendChild(badge);
                }

                const browserCell = document.createElement('td');
                browserCell.textContent = `${device.browser} / ${device.platform}`;

                const registeredCell = document.createElement('td');
                registeredCell.textContent = formatDate(device.registered_at);

                const seenCell = document.createElement('td');
                seenCell.textContent = formatDate(device.last_seen_at);

                const actionCell = document.createElement('td');
                actionCell.className = 'text-end';
                const revokeButton = document.createElement('button');
                revokeButton.type = 'button';
                revokeButton.className = 'btn btn-sm btn-outline-danger';
                revokeButton.dataset.deviceId = device.id;
                revokeButton.textContent = 'Revoke';
                revokeButton.addEventListener('click', () => revokeDevice(device));
                actionCell.appendChild(revokeButton);

                row.append(labelCell, browserCell, registeredCell, seenCell, actionCell);
                rowsElement.appendChild(row);
            });
        }

        async function loadDevices() {
            const payload = await fetchJson(root.dataset.listUrl);
            renderDevices(payload.devices || []);
        }

        async function resolveCurrentDevice() {
            currentDeviceId = null;
            currentSubscription = null;

            if (!window.isSecureContext || !('serviceWorker' in navigator) || !('PushManager' in window)) {
                return;
            }

            const registration = await navigator.serviceWorker.getRegistration('/');
            if (!registration) {
                return;
            }

            currentSubscription = await registration.pushManager.getSubscription();
            if (!currentSubscription) {
                return;
            }

            const payload = await fetchJson(
                root.dataset.currentUrl,
                requestOptions('POST', {endpoint: currentSubscription.endpoint})
            );
            currentDeviceId = payload.device?.id || null;
        }

        async function evaluateBrowser() {
            await resolveCurrentDevice();

            if (!readiness.ready) {
                setStatus(readiness.message || 'Web Push is not ready in this environment.', 'warning');
                return;
            }

            if (!window.isSecureContext) {
                setStatus('Web Push requires HTTPS or a trusted local development origin.', 'warning');
                return;
            }

            if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
                setStatus('This browser does not support the required Web Push features.', 'warning');
                return;
            }

            if (requiresIosHomeScreen()) {
                iosHelp.classList.remove('d-none');
                setStatus('Install Nexum on the Home Screen before enabling Web Push on this device.', 'info');
                return;
            }

            iosHelp.classList.add('d-none');

            if (Notification.permission === 'denied') {
                setStatus('Notifications are blocked in browser or operating-system settings.', 'danger');
                return;
            }

            if (currentSubscription && currentDeviceId) {
                setStatus('This device is registered for Web Push.', 'success');
                return;
            }

            if (currentSubscription) {
                enableButton.innerHTML = '<i class="bi bi-bell-plus me-1"></i> Register this device';
                setStatus('This browser has a subscription that is not registered to your Nexum account.', 'info');
                return;
            }

            enableButton.innerHTML = '<i class="bi bi-bell-plus me-1"></i> Enable on this device';
            setStatus(
                Notification.permission === 'granted'
                    ? 'Browser permission is granted, but this device is not registered.'
                    : 'Web Push is available. Permission is requested only when you click Enable.',
                'secondary'
            );
        }

        async function enableCurrentDevice() {
            setBusy(true);

            try {
                if (Notification.permission === 'default') {
                    const permission = await Notification.requestPermission();
                    if (permission !== 'granted') {
                        throw new Error('Notification permission was not granted.');
                    }
                }

                if (Notification.permission !== 'granted') {
                    throw new Error('Notifications are blocked in browser or operating-system settings.');
                }

                const registration = await sharedWorkerRegistration();
                currentSubscription = await registration.pushManager.getSubscription();

                if (!currentSubscription) {
                    currentSubscription = await registration.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: publicKeyBytes(readiness.public_key),
                    });
                }

                const payload = await fetchJson(
                    root.dataset.storeUrl,
                    requestOptions('POST', subscriptionPayload(currentSubscription))
                );
                currentDeviceId = payload.device.id;
                setStatus('This device is registered for Web Push.', 'success');
                await loadDevices();
            } catch (error) {
                setStatus(error.message, 'danger');
            } finally {
                setBusy(false);
            }
        }

        async function testCurrentDevice() {
            if (!currentSubscription || !currentDeviceId) {
                return;
            }

            setBusy(true);

            try {
                const payload = await fetchJson(
                    root.dataset.testUrl,
                    requestOptions('POST', {endpoint: currentSubscription.endpoint})
                );
                setStatus(payload.message, 'success');
            } catch (error) {
                setStatus(error.message, 'danger');
            } finally {
                setBusy(false);
            }
        }

        async function revokeDevice(device) {
            if (!window.confirm(`Revoke ${device.label}?`)) {
                return;
            }

            setBusy(true);

            try {
                await fetchJson(destroyUrl(device.id), requestOptions('DELETE'));

                if (device.id === currentDeviceId && currentSubscription) {
                    await currentSubscription.unsubscribe();
                    currentSubscription = null;
                    currentDeviceId = null;
                }

                await evaluateBrowser();
                await loadDevices();
            } catch (error) {
                setStatus(error.message, 'danger');
            } finally {
                setBusy(false);
            }
        }

        async function refresh() {
            setBusy(true);

            try {
                await evaluateBrowser();
                await loadDevices();
            } catch (error) {
                setStatus(error.message, 'danger');
            } finally {
                setBusy(false);
            }
        }

        enableButton.addEventListener('click', enableCurrentDevice);
        testButton.addEventListener('click', testCurrentDevice);
        refreshButton.addEventListener('click', refresh);

        refresh();
    });
</script>
@endsection
