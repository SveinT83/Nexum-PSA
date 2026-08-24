if (import.meta.env.VITE_EMAIL_LIVE_ENABLED === 'true') {
    let initialization = null;

    // The server-rendered Mail workspace calls this only when its independent
    // runtime gate is enabled. A stale client build therefore cannot open a
    // socket merely because the Vite-time flag was once true.
    window.initializeEmailEcho = () => {
        if (window.Echo) {
            return Promise.resolve(window.Echo);
        }

        initialization ??= Promise.all([
            import('laravel-echo'),
            import('pusher-js'),
        ]).then(([{ default: Echo }, { default: Pusher }]) => {
            window.Pusher = Pusher;

            window.Echo = new Echo({
                broadcaster: 'reverb',
                key: import.meta.env.VITE_REVERB_APP_KEY,
                wsHost: import.meta.env.VITE_REVERB_HOST,
                wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
                wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
                forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
                enabledTransports: ['ws', 'wss'],
                authEndpoint: '/tech/mail/broadcasting/auth',
            });

            return window.Echo;
        });

        return initialization;
    };
}
