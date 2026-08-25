<?php

namespace App\Http\Middleware;

use App\Modules\Email\Services\EmailLiveRuntimeReadiness;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Add baseline browser security headers to every HTTP response.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = $response->headers;

        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', $this->contentSecurityPolicy());
        }

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    /**
     * Keep the policy compatible with current Blade views while blocking high-risk defaults.
     */
    private function contentSecurityPolicy(): string
    {
        $connectSources = ["'self'", 'https:'];
        if (app(EmailLiveRuntimeReadiness::class)->ready()) {
            $host = trim((string) config('broadcasting.connections.reverb.options.host'));
            $port = (int) config('broadcasting.connections.reverb.options.port', 443);
            $scheme = config('broadcasting.connections.reverb.options.scheme', 'https') === 'https'
                ? 'wss'
                : 'ws';

            if ($host !== '' && preg_match('/^[A-Za-z0-9.-]+$/D', $host) === 1 && $port > 0) {
                $connectSources[] = "{$scheme}://{$host}:{$port}";
            }
        }

        return implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "img-src 'self' data: blob: https:",
            "font-src 'self' data: https:",
            "style-src 'self' 'unsafe-inline' https:",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' https:",
            'connect-src '.implode(' ', array_unique($connectSources)),
            "frame-src 'self' https:",
        ]);
    }
}
