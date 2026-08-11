<?php

namespace App\Modules\Email\Tests\Unit;

use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Services\ImapClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Webklex\PHPIMAP\Message;

class ImapClientTest extends TestCase
{
    #[Test]
    public function payload_preserves_raw_repeated_and_folded_headers(): void
    {
        $raw = implode("\r\n", [
            'Received: from edge.example.test by mx.example.test with ESMTP id first',
            'Received: from sender.example.test by edge.example.test with ESMTP id second',
            'Authentication-Results: mx.example.test; spf=pass',
            "\tsmtp.mailfrom=sender@example.test; dkim=pass header.d=example.test",
            'Authentication-Results: backup.example.test; dmarc=pass header.from=example.test',
            'Auto-Submitted: auto-generated',
            'From: Sender <sender@example.test>',
            'To: Support <support@example.test>',
            'Subject: Header regression',
            'Message-ID: <header-regression@example.test>',
            'Date: Wed, 5 Aug 2026 09:00:00 +0200',
            'Content-Type: text/plain; charset=UTF-8',
            '',
            'Body',
        ]);
        $message = Message::fromString($raw);
        $message->size = strlen($raw);

        $method = new ReflectionMethod(ImapClient::class, 'payloadsFromMessages');
        $payloads = $method->invoke(new ImapClient(new EmailAccount), [$message]);
        $headers = $payloads[0]['headers'];

        $this->assertSame([
            'from edge.example.test by mx.example.test with ESMTP id first',
            'from sender.example.test by edge.example.test with ESMTP id second',
        ], $headers['received']);
        $this->assertSame([
            'mx.example.test; spf=pass smtp.mailfrom=sender@example.test; dkim=pass header.d=example.test',
            'backup.example.test; dmarc=pass header.from=example.test',
        ], $headers['authentication-results']);
        $this->assertSame(['auto-generated'], $headers['auto-submitted']);
        $this->assertIsString(json_encode($headers, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function payload_returns_an_empty_header_map_when_the_message_has_no_header(): void
    {
        $client = new ImapClient(new EmailAccount);
        $method = new ReflectionMethod(ImapClient::class, 'normalizeHeaders');

        $this->assertSame([], $method->invoke($client, null));
    }
}
