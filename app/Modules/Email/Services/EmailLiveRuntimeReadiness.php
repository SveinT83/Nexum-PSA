<?php

namespace App\Modules\Email\Services;

class EmailLiveRuntimeReadiness
{
    /** Fail closed until private transport settings are exact and proxy-safe. */
    public function ready(): bool
    {
        if (! config('email_live.enabled', false)
            || ! config('email_live.runtime_approved', false)
            || config('broadcasting.default') !== 'reverb') {
            return false;
        }

        $listenHost = strtolower(trim((string) config('reverb.servers.reverb.host')));
        if (! in_array($listenHost, ['127.0.0.1', '::1', 'localhost'], true)) {
            return false;
        }

        $publicHost = trim((string) config('broadcasting.connections.reverb.options.host'));
        $publicPort = (int) config('broadcasting.connections.reverb.options.port', 0);
        $publicScheme = (string) config('broadcasting.connections.reverb.options.scheme');
        if ($publicHost === ''
            || preg_match('/^[A-Za-z0-9.-]+$/D', $publicHost) !== 1
            || $publicPort < 1
            || ! in_array($publicScheme, ['http', 'https'], true)) {
            return false;
        }

        $origins = config('email_live.allowed_origins', []);
        if (! is_array($origins) || $origins === []) {
            return false;
        }

        foreach ($origins as $origin) {
            if (! $this->exactOrigin($origin)) {
                return false;
            }
        }

        return true;
    }

    private function exactOrigin(mixed $origin): bool
    {
        if (! is_string($origin)
            || str_contains($origin, '*')
            || filter_var($origin, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($origin);

        return is_array($parts)
            && in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            && filled($parts['host'] ?? null)
            && ! isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])
            && (! isset($parts['path']) || $parts['path'] === '' || $parts['path'] === '/');
    }
}
