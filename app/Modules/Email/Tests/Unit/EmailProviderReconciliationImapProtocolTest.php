<?php

namespace App\Modules\Email\Tests\Unit;

use App\Modules\Email\Services\EmailProviderReconciliationImapProtocol;
use App\Modules\Email\Services\EmailProviderReconciliationPolicy;
use App\Modules\Email\Services\EmailProviderReconciliationReadException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Connection\Protocols\Response;

class EmailProviderReconciliationImapProtocolTest extends TestCase
{
    #[Test]
    public function oversized_declared_peek_literal_is_rejected_before_literal_bytes_are_consumed(): void
    {
        $declared = EmailProviderReconciliationPolicy::HARD_HEADER_BYTES + 2;
        $framing = "* 1 FETCH (UID 7 BODY[HEADER]<0> {{$declared}}\r\n";
        $stream = $this->stream($framing.str_repeat('provider-canary', 100));
        $protocol = $this->protocol($stream);
        $response = Response::make(1, [sprintf(
            "TAG1 UID FETCH 7:7 (UID BODY.PEEK[HEADER]<0.%d>)\r\n",
            EmailProviderReconciliationPolicy::HARD_HEADER_BYTES + 1,
        )]);

        try {
            $protocol->nextLine($response);
            $this->fail('A provider literal larger than the requested hard cap must fail closed.');
        } catch (EmailProviderReconciliationReadException $exception) {
            $this->assertSame('provider_response_byte_cap_exceeded', $exception->safeCode);
        }

        $this->assertSame(strlen($framing), ftell($stream));
        $this->assertSame([], $response->getResponse());
        $protocol->stream = false;
        fclose($stream);
    }

    #[Test]
    public function oversized_unterminated_provider_line_is_rejected_at_the_control_line_cap(): void
    {
        $stream = $this->stream(str_repeat(
            'x',
            EmailProviderReconciliationImapProtocol::MAX_CONTROL_LINE_BYTES + 32,
        ));
        $protocol = $this->protocol($stream);
        $response = Response::make(1, ["TAG1 IDLE\r\n"]);

        try {
            $protocol->nextLine($response);
            $this->fail('An unterminated provider line must not grow without a hard bound.');
        } catch (EmailProviderReconciliationReadException $exception) {
            $this->assertSame('provider_response_byte_cap_exceeded', $exception->safeCode);
        }

        $this->assertSame(
            EmailProviderReconciliationImapProtocol::MAX_CONTROL_LINE_BYTES + 1,
            ftell($stream),
        );
        $this->assertSame([], $response->getResponse());
        $protocol->stream = false;
        fclose($stream);
    }

    /** @return resource */
    private function stream(string $contents)
    {
        $stream = fopen('php://temp', 'r+');
        $this->assertIsResource($stream);
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    /** @param resource $stream */
    private function protocol($stream): EmailProviderReconciliationImapProtocol
    {
        $protocol = new EmailProviderReconciliationImapProtocol(Config::make());
        $protocol->stream = $stream;

        return $protocol;
    }
}
