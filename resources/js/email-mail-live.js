/**
 * One private Mail hint channel with a visibility-aware polling fallback.
 * Database catch-up remains authoritative; socket payloads are never applied.
 */
class EmailMailLive {
    constructor() {
        this.initialized = false;
        this.userId = null;
        this.channel = null;
        this.catchUp = null;
        this.mode = 'off';
        this.failureTimer = null;
        this.pollTimer = null;
        this.safetyTimer = null;
        this.httpFailureCount = 0;
        this.lifecycleBound = false;
    }

    init(userId, catchUp) {
        if (this.initialized || !Number.isInteger(userId) || userId < 1 || typeof catchUp !== 'function') return;

        this.userId = userId;
        this.catchUp = catchUp;
        this.initialized = true;
        this.bindLifecycle();
        this.armConnectionFailure();

        const initialize = window.Echo
            ? Promise.resolve(window.Echo)
            : window.initializeEmailEcho?.();

        Promise.resolve(initialize)
            .then(() => this.subscribe())
            .catch(() => this.enterPollMode());
    }

    subscribe() {
        if (!window.Echo || !this.userId || this.channel) return;

        this.channel = window.Echo.private(`email.user.${this.userId}`)
            .listen('.email.projection.invalidated.v1', () => this.runCatchUp(false));

        const connection = window.Echo.connector?.pusher?.connection;
        if (!connection) {
            this.enterPollMode();
            return;
        }

        connection.bind('connected', () => this.enterReverbMode());
        connection.bind('disconnected', () => this.armConnectionFailure());
        connection.bind('unavailable', () => this.armConnectionFailure());
        connection.bind('error', () => this.armConnectionFailure());

        if (connection.state === 'connected') {
            this.enterReverbMode();
        }
    }

    enterReverbMode() {
        this.clearTimer('failureTimer');
        this.clearTimer('pollTimer');
        this.httpFailureCount = 0;
        this.setMode('reverb');
        this.runCatchUp(false);
        this.scheduleSafetyRefresh();
    }

    enterPollMode() {
        this.clearTimer('failureTimer');
        this.clearTimer('safetyTimer');
        this.setMode('poll');
        this.schedulePoll(0);
    }

    armConnectionFailure() {
        if (!this.visibleAndOnline()) return;

        this.clearTimer('failureTimer');
        this.failureTimer = window.setTimeout(() => this.enterPollMode(), 5000);
    }

    scheduleSafetyRefresh() {
        this.clearTimer('safetyTimer');
        if (this.mode !== 'reverb' || !this.visibleAndOnline()) return;

        this.safetyTimer = window.setTimeout(async () => {
            await this.runCatchUp(true);
            this.scheduleSafetyRefresh();
        }, 120000);
    }

    schedulePoll(delay = 15000) {
        this.clearTimer('pollTimer');
        if (this.mode !== 'poll' || !this.visibleAndOnline()) return;

        this.pollTimer = window.setTimeout(async () => {
            const succeeded = await this.runCatchUp(true);
            if (succeeded) {
                this.httpFailureCount = 0;
                this.schedulePoll(15000);
                return;
            }

            this.httpFailureCount += 1;
            const backoff = [15000, 30000, 60000][Math.min(this.httpFailureCount - 1, 2)];
            const jitter = Math.floor(Math.random() * Math.max(1, Math.floor(backoff * 0.1)));
            this.schedulePoll(backoff + jitter);
        }, delay);
    }

    async runCatchUp(forceBoundedRefresh) {
        if (!this.catchUp || !this.visibleAndOnline()) return false;

        try {
            await this.catchUp(Boolean(forceBoundedRefresh));
            return true;
        } catch (_) {
            return false;
        }
    }

    bindLifecycle() {
        if (this.lifecycleBound) return;
        this.lifecycleBound = true;

        window.addEventListener('online', () => this.resume());
        window.addEventListener('offline', () => this.pause());
        window.addEventListener('pageshow', () => this.resume());
        document.addEventListener('visibilitychange', () => {
            document.visibilityState === 'visible' ? this.resume() : this.pause();
        });
    }

    resume() {
        if (!this.visibleAndOnline()) return;

        this.runCatchUp(true);
        if (this.mode === 'reverb') {
            this.scheduleSafetyRefresh();
        } else {
            this.enterPollMode();
        }
    }

    pause() {
        this.clearTimer('failureTimer');
        this.clearTimer('pollTimer');
        this.clearTimer('safetyTimer');
    }

    visibleAndOnline() {
        return document.visibilityState === 'visible' && navigator.onLine !== false;
    }

    setMode(mode) {
        if (this.mode === mode) return;
        this.mode = mode;
        window.dispatchEvent(new CustomEvent('email-mail-live-mode', { detail: { mode } }));
    }

    clearTimer(property) {
        if (this[property]) window.clearTimeout(this[property]);
        this[property] = null;
    }
}

window.EmailMailLive = new EmailMailLive();
