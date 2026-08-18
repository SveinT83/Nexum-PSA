<?php

namespace App\Modules\Email\Tests\Unit;

use App\Modules\Email\DTOs\EmailProviderReconciliationFolderDescriptor;
use App\Modules\Email\Services\EmailProviderReconciliationMessagePayload;
use App\Modules\Email\Support\EmailProviderPath;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mime\Email;
use Tests\TestCase;
use Webklex\PHPIMAP\Message;

class EmailProviderPathTest extends TestCase
{
    #[Test]
    #[DataProvider('validPaths')]
    public function provider_paths_preserve_byte_identity_except_for_the_root_inbox_rule(
        string $input,
        string $expected,
    ): void {
        $this->assertSame($expected, EmailProviderPath::normalize($input));
    }

    /** @return iterable<string, array{string,string}> */
    public static function validPaths(): iterable
    {
        yield 'canonical inbox' => ['INBOX', 'INBOX'];
        yield 'mixed root inbox' => ['Inbox', 'INBOX'];
        yield 'lower root inbox' => ['inbox', 'INBOX'];
        yield 'inbox descendant remains exact' => ['inbox/Child', 'inbox/Child'];
        yield 'canonical inbox descendant remains exact' => ['INBOX/Child', 'INBOX/Child'];
        yield 'case identity remains exact' => ['Foo', 'Foo'];
        yield 'trailing space remains exact' => ['Foo ', 'Foo '];
        yield 'accent remains exact' => ['Résumé', 'Résumé'];
        yield 'decomposed accent remains exact' => ["Re\u{0301}sume\u{0301}", "Re\u{0301}sume\u{0301}"];
        yield 'maximum utf8 bytes' => [str_repeat('😀', 512), str_repeat('😀', 512)];
    }

    #[Test]
    #[DataProvider('invalidPaths')]
    public function invalid_or_oversize_provider_paths_are_rejected(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);

        EmailProviderPath::normalize($path);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPaths(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace only' => ['   '];
        yield 'nul' => ["Folder\0Child"];
        yield 'invalid utf8' => ["Folder\xC3\x28"];
        yield 'more than 512 characters and 2048 bytes' => [str_repeat('😀', 512).'a'];
    }

    #[Test]
    public function reconciliation_ingress_normalizes_only_root_path_facts(): void
    {
        $descriptor = new EmailProviderReconciliationFolderDescriptor(
            path: 'inbox/Child',
            name: 'Child',
            delimiter: '/',
            parentPath: 'Inbox',
            remoteId: 'inbox/Child',
        );
        $this->assertSame('inbox/Child', $descriptor->path);
        $this->assertSame('INBOX', $descriptor->parentPath);
        $this->assertSame('inbox/Child', $descriptor->remoteId);

        $mime = (new Email)
            ->from('sender@example.test')
            ->to('recipient@example.test')
            ->subject('Path ingress')
            ->text('Body');
        $message = Message::fromString($mime->toString());
        $message->setUid(7)->setFolderPath('Inbox');
        $payload = (new EmailProviderReconciliationMessagePayload)->make(
            $message,
            1,
            1,
            'Inbox',
            77,
            strlen($mime->toString()),
            false,
        );

        $this->assertSame('INBOX', $payload['mailbox']);
    }
}
