<?php

namespace App\Modules\System\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    #[Test]
    public function application_responses_include_security_headers(): void
    {
        $this->get('https://localhost/login')
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
            ->assertHeader('Content-Security-Policy');
    }

    #[Test]
    public function session_cookie_uses_secure_attributes_when_enabled(): void
    {
        config([
            'session.secure' => true,
            'session.encrypt' => true,
            'session.same_site' => 'lax',
        ]);

        $response = $this->get('https://localhost/login');
        $setCookie = strtolower(implode("\n", $response->headers->all('Set-Cookie')));

        $this->assertStringContainsString('secure', $setCookie);
        $this->assertStringContainsString('httponly', $setCookie);
        $this->assertStringContainsString('samesite=lax', $setCookie);
    }

    #[Test]
    public function websocket_csp_uses_only_the_exact_configured_reverb_origin(): void
    {
        config()->set('email_live.enabled', true);
        config()->set('email_live.runtime_approved', true);
        config()->set('email_live.allowed_origins', ['https://nexum.example.test']);
        config()->set('broadcasting.default', 'reverb');
        config()->set('reverb.servers.reverb.host', '127.0.0.1');
        config()->set('broadcasting.connections.reverb.options.host', 'mail-live.example.test');
        config()->set('broadcasting.connections.reverb.options.port', 443);
        config()->set('broadcasting.connections.reverb.options.scheme', 'https');

        $policy = (string) $this->get('/login')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString(
            "connect-src 'self' https: wss://mail-live.example.test:443",
            $policy,
        );
        $this->assertDoesNotMatchRegularExpression('/(?:^|\s)wss?:($|\s|;)/', $policy);
        $this->assertStringNotContainsString('*', $policy);
    }

    #[Test]
    public function login_page_uses_current_nexum_branding_copy(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Nexum PSA')
            ->assertSee('name@example.com')
            ->assertDontSee('Welcome to tdPSA')
            ->assertDontSee('admin@tdpsa.com')
            ->assertDontSee('bg-gray-50');
    }
}
