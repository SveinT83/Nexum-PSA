<?php

namespace App\Modules\System\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    #[Test]
    public function application_responses_include_security_headers(): void
    {
        $this->withServerVariables(['HTTPS' => 'on'])
            ->get('/login')
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

        $response = $this->withServerVariables(['HTTPS' => 'on'])->get('/login');
        $setCookie = strtolower(implode("\n", $response->headers->all('Set-Cookie')));

        $this->assertStringContainsString('secure', $setCookie);
        $this->assertStringContainsString('httponly', $setCookie);
        $this->assertStringContainsString('samesite=lax', $setCookie);
    }
}
