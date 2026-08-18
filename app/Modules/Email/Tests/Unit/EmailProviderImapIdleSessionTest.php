<?php

namespace App\Modules\Email\Tests\Unit;

use App\Modules\Email\Services\EmailProviderImapIdleSession;
use App\Modules\Email\Services\EmailProviderReconciliationReadException;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Connection\Protocols\ProtocolInterface;
use Webklex\PHPIMAP\Connection\Protocols\Response;

class EmailProviderImapIdleSessionTest extends TestCase
{
    #[Test]
    public function idle_hint_uses_examine_and_always_sends_done_without_provider_write(): void
    {
        $protocol = $this->readOnlyProtocol();
        $protocol->shouldReceive('getCapabilities')->once()->ordered()
            ->andReturn($this->response(['IMAP4rev1', 'IDLE']));
        $protocol->shouldReceive('examineFolder')->once()->with('INBOX')->ordered()
            ->andReturn($this->response(['uidvalidity' => 77]));
        $protocol->shouldReceive('idle')->once()->ordered();
        $protocol->shouldReceive('nextLine')->once()->with(Mockery::type(Response::class))->ordered()
            ->andReturn("* 4 EXISTS\r\n");
        $protocol->shouldReceive('done')->once()->ordered()->andReturn(true);

        $this->assertTrue(
            (new EmailProviderImapIdleSession($this->client($protocol)))->waitForOpaqueHint(),
        );
    }

    #[Test]
    public function idle_read_failure_still_sends_done_and_never_becomes_a_hint(): void
    {
        $protocol = $this->readOnlyProtocol();
        $protocol->shouldReceive('getCapabilities')->once()->ordered()
            ->andReturn($this->response(['IDLE']));
        $protocol->shouldReceive('examineFolder')->once()->with('INBOX')->ordered()
            ->andReturn($this->response(['uidvalidity' => 78]));
        $protocol->shouldReceive('idle')->once()->ordered();
        $protocol->shouldReceive('nextLine')->once()->ordered()
            ->andThrow(new EmailProviderReconciliationReadException(
                'provider_reconciliation_deadline_exceeded',
            ));
        $protocol->shouldReceive('done')->once()->ordered()->andReturn(true);

        try {
            (new EmailProviderImapIdleSession($this->client($protocol)))->waitForOpaqueHint();
            $this->fail('A timed-out opaque read cannot become a provider hint.');
        } catch (EmailProviderReconciliationReadException $exception) {
            $this->assertSame('provider_reconciliation_deadline_exceeded', $exception->safeCode);
        }
    }

    #[Test]
    public function oversized_idle_line_is_sanitized_and_still_sends_done(): void
    {
        $protocol = $this->readOnlyProtocol();
        $protocol->shouldReceive('getCapabilities')->once()->ordered()
            ->andReturn($this->response(['IDLE']));
        $protocol->shouldReceive('examineFolder')->once()->with('INBOX')->ordered()
            ->andReturn($this->response(['uidvalidity' => 79]));
        $protocol->shouldReceive('idle')->once()->ordered();
        $protocol->shouldReceive('nextLine')->once()->ordered()
            ->andThrow(new EmailProviderReconciliationReadException(
                'provider_response_byte_cap_exceeded',
            ));
        $protocol->shouldReceive('done')->once()->ordered()->andReturn(true);

        try {
            (new EmailProviderImapIdleSession($this->client($protocol)))->waitForOpaqueHint();
            $this->fail('An oversized opaque response cannot become a reconciliation hint.');
        } catch (EmailProviderReconciliationReadException $exception) {
            $this->assertSame('provider_response_byte_cap_exceeded', $exception->safeCode);
            $this->assertStringNotContainsString('provider-canary', (string) $exception);
        }
    }

    #[Test]
    public function idle_requires_capability_and_a_complete_read_only_inbox_selection(): void
    {
        $unsupported = $this->readOnlyProtocol();
        $unsupported->shouldReceive('getCapabilities')->once()
            ->andReturn($this->response(['IMAP4rev1']));
        try {
            (new EmailProviderImapIdleSession($this->client($unsupported)))->waitForOpaqueHint();
            $this->fail('A provider without IDLE support must fail closed.');
        } catch (EmailProviderReconciliationReadException $exception) {
            $this->assertSame('provider_idle_unsupported', $exception->safeCode);
        }

        $incomplete = $this->readOnlyProtocol();
        $incomplete->shouldReceive('getCapabilities')->once()
            ->andReturn($this->response(['IDLE']));
        $incomplete->shouldReceive('examineFolder')->once()->with('INBOX')
            ->andReturn($this->response([]));
        try {
            (new EmailProviderImapIdleSession($this->client($incomplete)))->waitForOpaqueHint();
            $this->fail('An incomplete EXAMINE response must not open IDLE.');
        } catch (EmailProviderReconciliationReadException $exception) {
            $this->assertSame('provider_idle_examine_invalid', $exception->safeCode);
        }
    }

    private function readOnlyProtocol(): ProtocolInterface
    {
        $protocol = Mockery::mock(ProtocolInterface::class);
        $protocol->shouldNotReceive('selectFolder');
        $protocol->shouldNotReceive('store');
        $protocol->shouldNotReceive('copyMessage');
        $protocol->shouldNotReceive('copyManyMessages');
        $protocol->shouldNotReceive('moveMessage');
        $protocol->shouldNotReceive('moveManyMessages');
        $protocol->shouldNotReceive('expunge');
        $protocol->shouldNotReceive('appendMessage');
        $protocol->shouldNotReceive('createFolder');
        $protocol->shouldNotReceive('renameFolder');
        $protocol->shouldNotReceive('deleteFolder');

        return $protocol;
    }

    private function client(ProtocolInterface $protocol): Client
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('getConnection')->andReturn($protocol);
        $client->shouldNotReceive('openFolder');

        return $client;
    }

    private function response(mixed $result): Response
    {
        return (new Response(1))->setCanBeEmpty(true)->setResult($result);
    }
}
