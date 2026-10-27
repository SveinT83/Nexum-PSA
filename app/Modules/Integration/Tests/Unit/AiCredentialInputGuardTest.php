<?php

namespace App\Modules\Integration\Tests\Unit;

use App\Modules\Integration\Services\AiCredentialInputGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AiCredentialInputGuardTest extends TestCase
{
    public static function credentials(): array
    {
        $value = 'canarySyntheticCredential987654';

        return [
            ['password='.$value], ['"password": "'.$value.'"'], ['passord er '.$value],
            ['access_token: '.$value], ['refresh-token='.$value], ['app password: abcd efgh ijkl mnop'],
            ['Recovery code: '.$value], ['Authorization: Basic '.$value], ['Bearer '.$value],
            ['-----BEGIN RSA PRIVATE KEY-----'], ['-----BEGIN OPENSSH PRIVATE KEY-----'],
            ['sk-proj-'.$value], ['ghp_'.$value], ['github_pat_'.$value], ['AKIAABCDEFGHIJKLMNOP'],
            ['https://user:'.$value.'@example.test'], [rawurlencode('password='.$value)],
            ['pass&#119;ord='.$value], ['pass\\u0077ord='.$value], ["pass\u{200B}word=".$value],
        ];
    }

    #[Test, DataProvider('credentials')]
    public function detects_supported_credential_representations_without_returning_them(string $input): void
    {
        $this->assertTrue((new AiCredentialInputGuard)->rejects($input));
    }

    #[Test]
    public function ordinary_security_discussion_urls_and_ticket_references_remain_valid(): void
    {
        $guard = new AiCredentialInputGuard;
        foreach (['How do I reset a password?', 'Explain API key rotation.', 'TD-2026-000123',
            'Use https://example.test/help for recovery codes.', 'The access token has expired.', 'The access token is expired.', 'The password is missing.'] as $text) {
            $this->assertFalse($guard->rejects($text), $text);
        }
        $this->assertTrue($guard->rejects(['context' => ['note' => 'password=syntheticCanary987']]));
        $this->assertTrue($guard->rejects(['message' => ['password' => 'syntheticCanary987']]));
        $this->assertTrue($guard->rejects(['message' => ['password' => ['value' => 'syntheticCanary987']]]));
    }
}
