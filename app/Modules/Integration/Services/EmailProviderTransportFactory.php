<?php

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Support\EmailProviderRuntimeCredentials;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Webklex\IMAP\Facades\Client as ImapClientFacade;
use Webklex\PHPIMAP\Client;

class EmailProviderTransportFactory
{
    public function makeImap(#[\SensitiveParameter] EmailProviderRuntimeCredentials $runtime, ?int $timeoutSeconds = null): Client
    {
        $endpoint = $runtime->imapEndpoint();
        $definition = $endpoint->endpoint();

        return ImapClientFacade::make([
            'host' => $this->socketHost($endpoint->pinnedAddress()),
            'port' => $definition->port(),
            'encryption' => $definition->transport() === 'implicit_tls' ? 'ssl' : 'starttls',
            'validate_cert' => true,
            'username' => $runtime->imapUsername(),
            'password' => $runtime->imapSecret(),
            'authentication' => null,
            'protocol' => 'imap',
            'timeout' => $this->socketTimeout($timeoutSeconds),
            // Webklex expects the inner SSL context array; Symfony expects the
            // outer stream-wrapper key used below.
            'ssl_options' => $this->tlsOptions($definition->host())['ssl'],
        ]);
    }

    public function makeSmtp(#[\SensitiveParameter] EmailProviderRuntimeCredentials $runtime, ?int $timeoutSeconds = null): EsmtpTransport
    {
        $endpoint = $runtime->smtpEndpoint();
        $definition = $endpoint->endpoint();
        $implicitTls = $definition->transport() === 'implicit_tls';
        $transport = new EsmtpTransport($this->socketHost($endpoint->pinnedAddress()), $definition->port(), $implicitTls);
        $stream = $transport->getStream();

        if ($stream instanceof SocketStream) {
            $stream->setStreamOptions($this->tlsOptions($definition->host()));
            $stream->setTimeout($this->socketTimeout($timeoutSeconds));
        }

        $transport->setAutoTls(true);
        $transport->setRequireTls(true);
        $transport->setUsername($runtime->smtpUsername());
        $transport->setPassword($runtime->smtpSecret());

        return $transport;
    }

    private function socketTimeout(?int $timeoutSeconds): int
    {
        return max(1, min(
            60,
            $timeoutSeconds ?? (int) config('email_provider_security.connection_timeout_seconds', 20),
        ));
    }

    private function socketHost(#[\SensitiveParameter] string $address): string
    {
        return str_contains($address, ':') ? '['.$address.']' : $address;
    }

    /** @return array{ssl: array<string, mixed>} */
    public function tlsOptions(#[\SensitiveParameter] string $peerName): array
    {
        $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;

        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        }

        return [
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'peer_name' => $peerName,
                'SNI_enabled' => true,
                'SNI_server_name' => $peerName,
                'crypto_method' => $cryptoMethod,
                'security_level' => 2,
            ],
        ];
    }
}
