<?php

namespace App\Modules\Integration\Tests\Unit;

use App\Modules\Integration\Exceptions\EmailProviderSecurityException;
use App\Modules\Integration\Services\EmailProviderCredentialCipher;
use App\Modules\Integration\Services\EmailProviderDnsResolver;
use App\Modules\Integration\Services\EmailProviderEndpointAuthorizer;
use App\Modules\Integration\Services\EmailProviderEndpointPolicy;
use App\Modules\Integration\Services\EmailProviderIpPolicy;
use App\Modules\Integration\Services\EmailProviderTransportFactory;
use App\Modules\Integration\Support\EmailProviderEndpoint;
use App\Modules\Integration\Support\EmailProviderResolvedEndpoint;
use App\Modules\Integration\Support\EmailProviderRuntimeCredentials;
use LogicException;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Tests\TestCase;

class EmailProviderEndpointSecurityTest extends TestCase
{
    #[Test]
    public function endpoint_policy_normalizes_idna_and_enforces_the_fixed_tls_matrix(): void
    {
        $policy = new EmailProviderEndpointPolicy;
        $imap = $policy->normalize('IMAP', 'MÜNICH.example.', 993, 'ssl');
        $smtp = $policy->normalize('smtp', 'mail.example.test', 587, 'tls');

        $this->assertSame('xn--mnich-kva.example', $imap->host());
        $this->assertSame('implicit_tls', $imap->transport());
        $this->assertSame('standard.imap.993.implicit_tls', $imap->policyIdentifier());
        $this->assertSame('starttls', $smtp->transport());
        $this->assertSame('standard.smtp.587.starttls', $smtp->policyIdentifier());
        $this->assertSecurityReason(
            fn () => $policy->normalize('imap', 'mail.example.test', 993, 'starttls'),
            'transport_mismatch',
        );
        $this->assertSecurityReason(
            fn () => $policy->normalize('smtp', 'mail.example.test', 25, 'starttls'),
            'port_not_allowed',
        );
    }

    #[Test]
    public function custom_ports_require_a_unique_named_installation_policy(): void
    {
        $policy = new EmailProviderEndpointPolicy;

        config()->set('email_provider_security.additional_endpoints', [[
            'name' => 'mail-cluster-imap',
            'protocol' => 'imap',
            'port' => 1993,
            'transport' => 'implicit_tls',
        ]]);

        $endpoint = $policy->normalize('imap', 'mail.example.test', 1993, 'implicit_tls');
        $this->assertSame('installation.mail-cluster-imap', $endpoint->policyIdentifier());

        foreach ([
            [[
                'protocol' => 'imap',
                'port' => 1993,
                'transport' => 'implicit_tls',
            ]],
            [[
                'name' => 'bad name',
                'protocol' => 'imap',
                'port' => 1993,
                'transport' => 'implicit_tls',
            ]],
        ] as $invalid) {
            config()->set('email_provider_security.additional_endpoints', $invalid);
            $this->assertSecurityReason(
                fn () => $policy->normalize('imap', 'mail.example.test', 1993, 'implicit_tls'),
                'endpoint_allowlist_invalid',
            );
        }

        config()->set('email_provider_security.additional_endpoints', [
            [
                'name' => 'duplicate-one',
                'protocol' => 'imap',
                'port' => 1993,
                'transport' => 'implicit_tls',
            ],
            [
                'name' => 'duplicate-two',
                'protocol' => 'imap',
                'port' => 1993,
                'transport' => 'implicit_tls',
            ],
        ]);
        $this->assertSecurityReason(
            fn () => $policy->normalize('imap', 'mail.example.test', 1993, 'implicit_tls'),
            'endpoint_allowlist_duplicate',
        );
    }

    #[Test]
    public function endpoint_policy_rejects_url_control_wildcard_and_zone_syntax(): void
    {
        $policy = new EmailProviderEndpointPolicy;

        foreach ([
            'https://mail.example.test',
            "mail.example.test\ninternal",
            '*.example.test',
            'user@mail.example.test',
            'fe80::1%eth0',
            '[2001:4860:4860::8888]',
            'mail.example.test/path',
        ] as $host) {
            $this->assertSecurityReason(
                fn () => $policy->normalize('imap', $host, 993, 'ssl'),
                'host_syntax_invalid',
            );
        }
    }

    #[Test]
    public function ip_policy_normalizes_mapped_public_ipv4_and_denies_mapped_private_or_loopback(): void
    {
        $policy = new EmailProviderIpPolicy;

        $this->assertSame('8.8.8.8', $policy->authorize('::ffff:8.8.8.8', 'public', null));
        $this->assertSecurityReason(
            fn () => $policy->authorize('::ffff:192.168.1.4', 'public', null),
            'address_not_public',
        );
        $this->assertSecurityReason(
            fn () => $policy->authorize('::ffff:127.0.0.1', 'public', null),
            'address_always_denied',
        );
    }

    #[Test]
    public function translation_and_tunnelling_ranges_cannot_hide_local_addresses(): void
    {
        $policy = new EmailProviderIpPolicy;

        foreach ([
            '::127.0.0.1',
            '::169.254.1.2',
            '64:ff9b::7f00:1',
            '64:ff9b:1::7f00:1',
            '2002:7f00:1::',
            '2002:a9fe:102::',
        ] as $address) {
            $this->assertSecurityReason(
                fn () => $policy->authorize($address, 'public', null),
                'address_always_denied',
            );
        }
    }

    #[Test]
    public function trusted_private_requires_a_named_cidr_and_never_overrides_always_denied_ranges(): void
    {
        config()->set('email_provider_security.trusted_private_cidrs.mail_cluster', [
            '10.20.0.0/16',
            'fd20:30::/48',
        ]);
        $policy = new EmailProviderIpPolicy;

        $this->assertSame('10.20.4.5', $policy->authorize('10.20.4.5', 'trusted_private', 'mail_cluster'));
        $this->assertSame('fd20:30::5', $policy->authorize('fd20:30::5', 'trusted_private', 'mail_cluster'));
        $this->assertSecurityReason(
            fn () => $policy->authorize('10.21.4.5', 'trusted_private', 'mail_cluster'),
            'trusted_cidr_mismatch',
        );
        $this->assertSecurityReason(
            fn () => $policy->authorize('127.0.0.1', 'trusted_private', 'mail_cluster'),
            'address_always_denied',
        );
    }

    #[Test]
    #[DataProvider('specialPurposePublicDestinations')]
    public function current_iana_special_purpose_destinations_are_never_public_provider_targets(string $address): void
    {
        $this->assertSecurityReason(
            fn () => (new EmailProviderIpPolicy)->authorize($address, 'public', null),
            'address_always_denied',
        );
    }

    /** @return iterable<string, array{string}> */
    public static function specialPurposePublicDestinations(): iterable
    {
        yield 'AS112 IPv4' => ['192.31.196.1'];
        yield 'AMT IPv4' => ['192.52.193.1'];
        yield 'direct delegation AS112 IPv4' => ['192.175.48.1'];
        yield 'IPv6 dummy prefix' => ['100:0:0:1::1'];
        yield 'direct delegation AS112 IPv6' => ['2620:4f:8000::1'];
        yield 'former 6bone' => ['3ffe::1'];
        yield 'IPv6 documentation' => ['3fff::1'];
        yield 'SRv6 SID' => ['5f00::1'];
        yield 'deprecated site local' => ['fec0::1'];
    }

    #[Test]
    public function public_provider_targets_require_ordinary_global_unicast_addresses(): void
    {
        $policy = new EmailProviderIpPolicy;

        $this->assertSame('8.8.8.8', $policy->authorize('8.8.8.8', 'public', null));
        $this->assertSame(
            '2001:4860:4860::8888',
            $policy->authorize('2001:4860:4860::8888', 'public', null),
        );
        $this->assertSecurityReason(
            fn () => $policy->authorize('4000::1', 'public', null),
            'address_not_public',
        );
    }

    #[Test]
    public function dns_resolution_is_bounded_and_a_mixed_answer_set_is_denied(): void
    {
        $dns = $this->dns([
            'mail.example.test' => [
                ['type' => 'CNAME', 'target' => 'edge.example.test.'],
                ['type' => 'CNAME', 'target' => 'edge.example.test.'],
            ],
            'edge.example.test' => [
                ['type' => 'A', 'ip' => '8.8.8.8'],
                ['type' => 'A', 'ip' => '10.0.0.5'],
            ],
        ]);
        $authorizer = new EmailProviderEndpointAuthorizer($dns, new EmailProviderIpPolicy);
        $endpoint = new EmailProviderEndpoint(
            'imap',
            'mail.example.test',
            993,
            'implicit_tls',
            'standard.imap.993.implicit_tls',
        );

        $this->assertSecurityReason(
            fn () => $authorizer->authorize($endpoint, 'public', null),
            'dns_answer_set_denied',
        );

        $looping = $this->dns([
            'one.example.test' => [['type' => 'CNAME', 'target' => 'two.example.test']],
            'two.example.test' => [['type' => 'CNAME', 'target' => 'one.example.test']],
        ]);
        $this->assertSecurityReason(fn () => $looping->resolve('one.example.test'), 'dns_cname_loop');
    }

    #[Test]
    public function runtime_diagnostics_and_serialization_do_not_disclose_sensitive_values(): void
    {
        $runtime = $this->runtime('starttls');
        $canaries = [
            'imap.private.example',
            'smtp.private.example',
            '10.20.30.40',
            '10.20.30.41',
            'imap-user-canary',
            'imap-secret-canary',
            'smtp-user-canary',
            'smtp-secret-canary',
        ];

        $diagnostics = [
            json_encode($runtime, JSON_THROW_ON_ERROR),
            print_r($runtime, true),
            var_export($runtime, true),
            $this->capturedVarDump($runtime),
            json_encode((new NormalizerFormatter)->format(new LogRecord(
                new \DateTimeImmutable,
                'security-test',
                Level::Debug,
                'runtime',
                ['runtime' => $runtime],
            )), JSON_THROW_ON_ERROR),
        ];

        foreach ($diagnostics as $diagnostic) {
            foreach ($canaries as $canary) {
                $this->assertStringNotContainsString($canary, $diagnostic);
            }
        }

        try {
            serialize($runtime);
            $this->fail('Runtime credentials must refuse serialization.');
        } catch (LogicException $exception) {
            $this->assertSame('Email provider runtime credentials may not be serialized.', $exception->getMessage());
        }

        $this->expectException(LogicException::class);
        serialize(['queued_job' => ['runtime' => $runtime]]);
    }

    #[Test]
    public function sensitive_plaintext_arguments_are_replaced_in_exception_traces(): void
    {
        $previous = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');

        try {
            (new EmailProviderCredentialCipher)->encrypt([
                'imap_username' => 'trace-user-canary',
                'imap_secret' => '',
                'smtp_username' => 'trace-smtp-user-canary',
                'smtp_secret' => 'trace-secret-canary',
            ]);
            $this->fail('Missing credential material must fail.');
        } catch (EmailProviderSecurityException $exception) {
            $cipherFrame = collect($exception->getTrace())
                ->first(fn (array $frame): bool => ($frame['function'] ?? null) === 'encrypt');
            $trace = print_r($cipherFrame['args'] ?? [], true).(string) $exception;
            $this->assertStringNotContainsString('trace-user-canary', $trace);
            $this->assertStringNotContainsString('trace-smtp-user-canary', $trace);
            $this->assertStringNotContainsString('trace-secret-canary', $trace);
            $this->assertNull($exception->getPrevious());
        } finally {
            if ($previous !== false) {
                ini_set('zend.exception_ignore_args', (string) $previous);
            }
        }
    }

    #[Test]
    public function transports_pin_the_socket_and_keep_original_peer_verification_with_required_tls(): void
    {
        $factory = new EmailProviderTransportFactory;
        $runtime = $this->runtime('starttls');
        $imap = $factory->makeImap($runtime)->getAccountConfig();
        $smtp = $factory->makeSmtp($runtime);
        $stream = $smtp->getStream();

        $this->assertSame('10.20.30.40', $imap['host']);
        $this->assertSame('starttls', $imap['encryption']);
        $this->assertTrue($imap['validate_cert']);
        $this->assertSame('imap.private.example', $imap['ssl_options']['peer_name']);
        $this->assertTrue($imap['ssl_options']['verify_peer']);
        $this->assertTrue($imap['ssl_options']['verify_peer_name']);
        $this->assertFalse($imap['ssl_options']['allow_self_signed']);

        $this->assertInstanceOf(SocketStream::class, $stream);
        $this->assertSame('10.20.30.41', $stream->getHost());
        $this->assertFalse($stream->isTLS());
        $this->assertTrue($smtp->isTlsRequired());
        $this->assertTrue($smtp->isAutoTls());
        $this->assertSame('smtp.private.example', $stream->getStreamOptions()['ssl']['peer_name']);
        $this->assertSame(
            STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
                | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0),
            $stream->getStreamOptions()['ssl']['crypto_method'],
        );
    }

    /** @param array<string, array<int, array<string, mixed>>> $records */
    private function dns(array $records): EmailProviderDnsResolver
    {
        return new class($records) extends EmailProviderDnsResolver
        {
            /** @param array<string, array<int, array<string, mixed>>> $records */
            public function __construct(private readonly array $records) {}

            protected function lookup(string $host): array
            {
                return $this->records[$host] ?? [];
            }
        };
    }

    private function runtime(string $smtpTransport): EmailProviderRuntimeCredentials
    {
        return new EmailProviderRuntimeCredentials(
            providerIntegrationId: 'provider-safe-id',
            configurationVersion: 4,
            credentialVersion: 9,
            imapEndpoint: new EmailProviderResolvedEndpoint(
                new EmailProviderEndpoint(
                    'imap',
                    'imap.private.example',
                    143,
                    'starttls',
                    'standard.imap.143.starttls',
                ),
                '10.20.30.40',
            ),
            smtpEndpoint: new EmailProviderResolvedEndpoint(
                new EmailProviderEndpoint(
                    'smtp',
                    'smtp.private.example',
                    587,
                    $smtpTransport,
                    'standard.smtp.587.starttls',
                ),
                '10.20.30.41',
            ),
            imapUsername: 'imap-user-canary',
            imapSecret: 'imap-secret-canary',
            smtpUsername: 'smtp-user-canary',
            smtpSecret: 'smtp-secret-canary',
            imapAuthType: 'password',
            smtpAuthType: 'password',
        );
    }

    private function capturedVarDump(mixed $value): string
    {
        ob_start();
        var_dump($value);

        return (string) ob_get_clean();
    }

    private function assertSecurityReason(callable $callback, string $reasonCode): void
    {
        try {
            $callback();
            $this->fail('Expected Email provider security policy to deny the value.');
        } catch (EmailProviderSecurityException $exception) {
            $this->assertSame($reasonCode, $exception->reasonCode);
            $this->assertNull($exception->getPrevious());
        }
    }
}
