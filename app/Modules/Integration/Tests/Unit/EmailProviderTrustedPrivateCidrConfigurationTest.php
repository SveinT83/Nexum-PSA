<?php

namespace App\Modules\Integration\Tests\Unit;

use App\Modules\Integration\Exceptions\EmailProviderSecurityException;
use App\Modules\Integration\Services\EmailProviderIpPolicy;
use App\Modules\Integration\Support\EmailProviderTrustedPrivateCidrConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmailProviderTrustedPrivateCidrConfigurationTest extends TestCase
{
    #[Test]
    public function approved_exact_private_host_is_exposed_only_under_its_named_group(): void
    {
        $configured = EmailProviderTrustedPrivateCidrConfiguration::exactRfc1918Ipv4Host(
            'tronderdata_mail_dev',
            '10.254.253.252/32',
        );

        $this->assertSame([
            'tronderdata_mail_dev' => ['10.254.253.252/32'],
        ], $configured);
        $this->assertArrayNotHasKey('legacy_trusted_private_accounts', $configured);

        config()->set('email_provider_security.trusted_private_cidrs', $configured);
        $policy = new EmailProviderIpPolicy;

        $this->assertSame(
            '10.254.253.252',
            $policy->authorize('10.254.253.252', 'trusted_private', 'tronderdata_mail_dev'),
        );
        $this->assertSecurityReason(
            fn () => $policy->authorize('10.254.253.251', 'trusted_private', 'tronderdata_mail_dev'),
            'trusted_cidr_mismatch',
        );
        $this->assertSecurityReason(
            fn () => $policy->authorize('10.254.253.252', 'trusted_private', 'legacy_account'),
            'trusted_cidr_mismatch',
        );
    }

    #[Test]
    #[DataProvider('invalidConfigurationValues')]
    public function missing_or_invalid_environment_values_omit_the_named_group(mixed $value): void
    {
        $this->assertSame(
            [],
            EmailProviderTrustedPrivateCidrConfiguration::exactRfc1918Ipv4Host(
                'tronderdata_mail_dev',
                $value,
            ),
        );
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidConfigurationValues(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'boolean' => [false];
        yield 'array' => [['10.254.253.252/32']];
        yield 'whitespace' => [' 10.254.253.252/32'];
        yield 'control character' => ["10.254.253.252/32\n10.254.253.251/32"];
        yield 'multiple ranges' => ['10.254.253.252/32,10.254.253.251/32'];
        yield 'broader private range' => ['10.254.253.0/24'];
        yield 'non-canonical prefix' => ['10.254.253.252/032'];
        yield 'public address' => ['8.8.8.8/32'];
        yield 'loopback' => ['127.0.0.1/32'];
        yield 'link local' => ['169.254.1.2/32'];
        yield 'carrier-grade nat' => ['100.64.0.1/32'];
        yield 'ipv6 private host' => ['fd20:30::5/128'];
        yield 'hostname' => ['mail.internal.test/32'];
    }

    private function assertSecurityReason(callable $callback, string $reason): void
    {
        try {
            $callback();
            $this->fail("Expected endpoint security failure [$reason].");
        } catch (EmailProviderSecurityException $exception) {
            $this->assertSame($reason, $exception->reasonCode);
            $this->assertNull($exception->getPrevious());
        }
    }
}
