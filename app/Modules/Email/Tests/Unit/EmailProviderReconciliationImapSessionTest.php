<?php

namespace App\Modules\Email\Tests\Unit;

use App\Modules\Email\DTOs\EmailProviderReconciliationPeekedMessage;
use App\Modules\Email\Services\EmailProviderReconciliationFingerprint;
use App\Modules\Email\Services\EmailProviderReconciliationImapSession;
use App\Modules\Email\Services\EmailProviderReconciliationMessagePayload;
use App\Modules\Email\Services\EmailProviderReconciliationPolicy;
use App\Modules\Email\Services\EmailProviderReconciliationReadException;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Connection\Protocols\ProtocolInterface;
use Webklex\PHPIMAP\Connection\Protocols\Response;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;

class EmailProviderReconciliationImapSessionTest extends TestCase
{
    #[Test]
    public function complete_folder_inventory_requires_selectable_inbox_and_preserves_numeric_paths(): void
    {
        $protocol = $this->readOnlyProtocol();
        $protocol->shouldReceive('folders')
            ->once()->with('', '*')
            ->andReturn($this->response([
                'INBOX' => ['delimiter' => '/', 'flags' => ['\\Inbox']],
                123 => ['delimiter' => '/', 'flags' => []],
            ]));

        $folders = $this->imapSession($this->client($protocol, null))->discoverFolders();

        $this->assertSame(['INBOX', '123'], array_map(
            fn ($folder): string => $folder->path,
            $folders,
        ));

        foreach ([[], ['Archive' => ['delimiter' => '/', 'flags' => []]]] as $inventory) {
            $incompleteProtocol = $this->readOnlyProtocol();
            $incompleteProtocol->shouldReceive('folders')
                ->once()->with('', '*')
                ->andReturn($this->response($inventory));

            try {
                $this->imapSession($this->client($incompleteProtocol, null))->discoverFolders();
                $this->fail('A LIST result without selectable INBOX must fail closed.');
            } catch (EmailProviderReconciliationReadException $exception) {
                $this->assertSame('provider_folder_inventory_incomplete', $exception->safeCode);
            }
        }
    }

    #[Test]
    public function total_folder_inventory_cap_counts_nonselectable_provider_entries(): void
    {
        $inventory = ['INBOX' => ['delimiter' => '/', 'flags' => ['\\Inbox']]];
        foreach (range(1, EmailProviderReconciliationPolicy::HARD_MAX_FOLDERS) as $index) {
            $inventory['Disabled/'.$index] = ['delimiter' => '/', 'flags' => ['\\Noselect']];
        }
        $protocol = $this->readOnlyProtocol();
        $protocol->shouldReceive('folders')
            ->once()->with('', '*')
            ->andReturn($this->response($inventory));

        try {
            $this->imapSession($this->client($protocol, null))->discoverFolders();
            $this->fail('Nonselectable folders must count toward the absolute LIST cap.');
        } catch (EmailProviderReconciliationReadException $exception) {
            $this->assertSame('provider_folder_inventory_cap_exceeded', $exception->safeCode);
        }
    }

    #[Test]
    public function exact_message_uses_examine_and_direct_peek_literals_with_zero_flag_write(): void
    {
        $header = implode("\r\n", [
            'Message-ID: <safe-peek@example.test>',
            'Subject: Safe PEEK',
            'From: sender@example.test',
            'To: receiver@example.test',
            'Date: Sun, 16 Aug 2026 10:00:00 +0000',
        ]);
        $body = 'Read without changing Seen.';
        $raw = $header."\r\n\r\n".$body;
        $protocol = $this->readOnlyProtocol();
        $protocol->shouldReceive('examineFolder')
            ->once()->with('INBOX')->ordered()
            ->andReturn($this->response(['uidvalidity' => 777]));
        $protocol->shouldReceive('fetch')
            ->once()->with(
                ['UID', 'FLAGS', 'RFC822.SIZE'],
                [55],
                null,
                IMAP::ST_UID,
            )->ordered()
            ->andReturn($this->response([
                55 => [
                    'UID' => 55,
                    'FLAGS' => ['\\Flagged', 'Customer'],
                    'RFC822.SIZE' => strlen($raw),
                ],
            ]));
        $protocol->shouldReceive('fetch')
            ->once()->with(['UID', $this->fullPeekItem(strlen($raw))], [55], null, IMAP::ST_UID)->ordered()
            ->andReturn($this->response([
                55 => [
                    'UID' => 55,
                    'BODY[]<0>' => $raw,
                ],
            ]));
        $protocol->shouldReceive('folderStatus')
            ->once()->with('INBOX', ['UIDVALIDITY'])->ordered()
            ->andReturn($this->response(['uidvalidity' => 777]));
        $client = $this->client($protocol, 'INBOX');

        $peeked = $this->imapSession($client)->messageByUidPeek(9, 4, 'INBOX', 777, 55);

        $this->assertNotNull($peeked);
        $this->assertSame(55, (int) $peeked->message()->getUid());
        $this->assertSame($body, $peeked->message()->getTextBody());
        $this->assertSame('Safe PEEK', $peeked->payload()['subject']);
        $this->assertSame(['\\Flagged', 'Customer'], $peeked->payload()['flags']);
        $this->assertTrue($peeked->payload()['provider_flagged']);
        $this->assertFalse($peeked->payload()['provider_seen']);
        $this->assertFalse($peeked->payload()['is_oversize']);
    }

    #[Test]
    public function uid_namespace_uid_and_partial_literals_fail_closed_before_local_storage(): void
    {
        $namespaceProtocol = $this->readOnlyProtocol();
        $namespaceProtocol->shouldReceive('examineFolder')
            ->once()->with('INBOX')
            ->andReturn($this->response(['uidvalidity' => 778]));
        $namespaceClient = $this->client($namespaceProtocol, 'INBOX');
        try {
            $this->imapSession($namespaceClient)->messageByUidPeek(9, 4, 'INBOX', 777, 55);
            $this->fail('A changed UID namespace must fail before metadata fetch.');
        } catch (EmailProviderReconciliationReadException $exception) {
            $this->assertSame('provider_uid_namespace_stale', $exception->safeCode);
        }

        $uidProtocol = $this->readOnlyProtocol();
        $uidProtocol->shouldReceive('examineFolder')
            ->once()->with('INBOX')
            ->andReturn($this->response(['uidvalidity' => 777]));
        $uidProtocol->shouldReceive('fetch')
            ->once()->with(['UID', 'FLAGS', 'RFC822.SIZE'], [55], null, IMAP::ST_UID)
            ->andReturn($this->response([
                56 => ['UID' => 56, 'FLAGS' => [], 'RFC822.SIZE' => 50],
            ]));
        $uidClient = $this->client($uidProtocol, 'INBOX');
        try {
            $this->imapSession($uidClient)->messageByUidPeek(9, 4, 'INBOX', 777, 55);
            $this->fail('A mismatched provider UID must fail closed.');
        } catch (EmailProviderReconciliationReadException $exception) {
            $this->assertSame('provider_message_metadata_invalid', $exception->safeCode);
        }

        $partialProtocol = $this->readOnlyProtocol();
        $partialProtocol->shouldReceive('examineFolder')
            ->once()->with('INBOX')
            ->andReturn($this->response(['uidvalidity' => 777]));
        $partialProtocol->shouldReceive('fetch')
            ->once()->with(['UID', 'FLAGS', 'RFC822.SIZE'], [55], null, IMAP::ST_UID)
            ->andReturn($this->response([
                55 => ['UID' => 55, 'FLAGS' => [], 'RFC822.SIZE' => 50],
            ]));
        $partialProtocol->shouldReceive('fetch')
            ->once()->with(['UID', $this->fullPeekItem(50)], [55], null, IMAP::ST_UID)
            ->andReturn($this->response([55 => ['UID' => 55]]));
        $partialClient = $this->client($partialProtocol, 'INBOX');
        try {
            $this->imapSession($partialClient)->messageByUidPeek(9, 4, 'INBOX', 777, 55);
            $this->fail('A missing complete raw literal must fail before local storage.');
        } catch (EmailProviderReconciliationReadException $exception) {
            $this->assertSame('provider_message_body_incomplete', $exception->safeCode);
        }
    }

    #[Test]
    public function metadata_fetch_is_discarded_when_uidvalidity_changes_before_return(): void
    {
        $protocol = $this->readOnlyProtocol();
        $protocol->shouldReceive('getCapabilities')->once()->ordered()
            ->andReturn($this->response(['IMAP4rev1']));
        $protocol->shouldReceive('examineFolder')->once()->with('INBOX')->ordered()
            ->andReturn($this->response(['uidvalidity' => 777]));
        $protocol->shouldReceive('search')->once()
            ->with(['UID 55:55'], IMAP::ST_UID)->ordered()
            ->andReturn($this->response([55]));
        $protocol->shouldReceive('fetch')->once()
            ->with(['UID', 'FLAGS'], [55], null, IMAP::ST_UID)->ordered()
            ->andReturn($this->response([
                55 => ['UID' => 55, 'FLAGS' => ['\\Seen']],
            ]));
        $protocol->shouldReceive('folderStatus')->once()
            ->with('INBOX', ['UIDVALIDITY'])->ordered()
            ->andReturn($this->response(['uidvalidity' => 778]));

        try {
            $this->imapSession($this->client($protocol, 'INBOX'))
                ->metadataPage('INBOX', 777, 54, 55, 1);
            $this->fail('Metadata from a superseded UID namespace must never be returned.');
        } catch (EmailProviderReconciliationReadException $exception) {
            $this->assertSame('provider_uid_namespace_stale', $exception->safeCode);
        }
    }

    #[Test]
    public function complete_peek_is_discarded_when_uidvalidity_changes_before_payload_projection(): void
    {
        $raw = "Message-ID: <reused-uid@example.test>\r\n"
            ."Subject: Reused UID\r\n"
            ."From: sender@example.test\r\n\r\n"
            .'provider body canary';
        $protocol = $this->readOnlyProtocol();
        $protocol->shouldReceive('examineFolder')->once()->with('INBOX')->ordered()
            ->andReturn($this->response(['uidvalidity' => 777]));
        $protocol->shouldReceive('fetch')->once()
            ->with(['UID', 'FLAGS', 'RFC822.SIZE'], [55], null, IMAP::ST_UID)->ordered()
            ->andReturn($this->response([
                55 => ['UID' => 55, 'FLAGS' => [], 'RFC822.SIZE' => strlen($raw)],
            ]));
        $protocol->shouldReceive('fetch')->once()
            ->with(['UID', $this->fullPeekItem(strlen($raw))], [55], null, IMAP::ST_UID)
            ->ordered()
            ->andReturn($this->response([
                55 => ['UID' => 55, 'BODY[]<0>' => $raw],
            ]));
        $protocol->shouldReceive('folderStatus')->once()
            ->with('INBOX', ['UIDVALIDITY'])->ordered()
            ->andReturn($this->response(['uidvalidity' => 778]));

        try {
            $this->imapSession($this->client($protocol, 'INBOX'))
                ->messageByUidPeek(9, 4, 'INBOX', 777, 55);
            $this->fail('A PEEK from a superseded UID namespace must never become a payload.');
        } catch (EmailProviderReconciliationReadException $exception) {
            $this->assertSame('provider_uid_namespace_stale', $exception->safeCode);
        }
    }

    #[Test]
    public function malicious_flag_sets_fail_before_any_literal_or_payload_projection(): void
    {
        $cases = [
            array_map(fn (int $index): string => 'custom-'.$index, range(1, 129)),
            [str_repeat('x', 256)],
            ['Customer', 'customer'],
        ];

        foreach ($cases as $flags) {
            $protocol = $this->readOnlyProtocol();
            $protocol->shouldReceive('examineFolder')
                ->once()->with('INBOX')
                ->andReturn($this->response(['uidvalidity' => 777]));
            $protocol->shouldReceive('fetch')
                ->once()->with(['UID', 'FLAGS', 'RFC822.SIZE'], [55], null, IMAP::ST_UID)
                ->andReturn($this->response([
                    55 => ['UID' => 55, 'FLAGS' => $flags, 'RFC822.SIZE' => 50],
                ]));

            try {
                $this->imapSession($this->client($protocol, 'INBOX'))
                    ->messageByUidPeek(9, 4, 'INBOX', 777, 55);
                $this->fail('An unbounded or duplicate provider flag set must fail closed.');
            } catch (EmailProviderReconciliationReadException $exception) {
                $this->assertSame('provider_message_flags_invalid', $exception->safeCode);
            }
        }
    }

    #[Test]
    public function system_flags_require_a_leading_backslash_and_same_named_keywords_remain_custom(): void
    {
        $cases = [
            [
                'flags' => ['Seen'],
                'seen' => false,
                'custom' => ['seen'],
            ],
            [
                'flags' => ['\\Seen'],
                'seen' => true,
                'custom' => [],
            ],
            [
                'flags' => ['Seen', '\\Seen'],
                'seen' => true,
                'custom' => ['seen'],
            ],
        ];

        foreach ($cases as $case) {
            $protocol = $this->readOnlyProtocol();
            $protocol->shouldReceive('getCapabilities')->once()
                ->andReturn($this->response(['IMAP4rev1']));
            $protocol->shouldReceive('examineFolder')->once()->with('INBOX')
                ->andReturn($this->response(['uidvalidity' => 777]));
            $protocol->shouldReceive('search')->once()
                ->with(['UID 1:1'], IMAP::ST_UID)
                ->andReturn($this->response([1]));
            $protocol->shouldReceive('fetch')->once()
                ->with(['UID', 'FLAGS'], [1], null, IMAP::ST_UID)
                ->andReturn($this->response([
                    1 => ['UID' => 1, 'FLAGS' => $case['flags']],
                ]));
            $protocol->shouldReceive('folderStatus')->once()
                ->with('INBOX', ['UIDVALIDITY'])
                ->andReturn($this->response(['uidvalidity' => 777]));

            $page = $this->imapSession($this->client($protocol, 'INBOX'))
                ->metadataPage('INBOX', 777, 0, 1, 1);

            $this->assertSame($case['seen'], $page->messages[0]->seen);
            $this->assertSame($case['custom'], $page->messages[0]->customFlags);

            $message = Message::fromString("Subject: Flag identity\r\n\r\nBody");
            $message->setUid(1)->setFolderPath('INBOX');
            $payload = app(EmailProviderReconciliationMessagePayload::class)->make(
                $message,
                1,
                1,
                'INBOX',
                777,
                35,
                false,
                $case['flags'],
            );
            $this->assertSame($case['seen'], $payload['provider_seen']);
            $this->assertSame($case['flags'], $payload['flags']);
        }
    }

    #[Test]
    public function list_attributes_require_a_leading_backslash_to_change_folder_semantics(): void
    {
        $protocol = $this->readOnlyProtocol();
        $protocol->shouldReceive('folders')->once()->with('', '*')
            ->andReturn($this->response([
                'INBOX' => ['delimiter' => '/', 'flags' => ['Inbox']],
                'Custom' => ['delimiter' => '/', 'flags' => ['Noselect', 'Archive']],
                'Disabled' => ['delimiter' => '/', 'flags' => ['\\Noselect', '\\Archive']],
            ]));

        $folders = collect(
            $this->imapSession($this->client($protocol, null))->discoverFolders(),
        )->keyBy(fn ($folder): string => $folder->path);

        $this->assertTrue($folders['INBOX']->selectable);
        $this->assertNull($folders['INBOX']->specialUse);
        $this->assertTrue($folders['Custom']->selectable);
        $this->assertNull($folders['Custom']->specialUse);
        $this->assertFalse($folders['Disabled']->selectable);
        $this->assertSame('Archive', $folders['Disabled']->specialUse);
    }

    #[Test]
    public function cap_plus_one_header_fetch_rejects_overlong_provider_literals_before_text_or_store(): void
    {
        $cases = [
            [
                EmailProviderReconciliationPolicy::HARD_MESSAGE_BYTES + 1,
                EmailProviderReconciliationPolicy::HARD_HEADER_BYTES + 1,
            ],
            [
                EmailProviderReconciliationPolicy::HARD_MESSAGE_BYTES + 1,
                EmailProviderReconciliationPolicy::HARD_HEADER_BYTES + 2,
            ],
        ];
        foreach ($cases as [$reportedSize, $literalBytes]) {
            $protocol = $this->readOnlyProtocol();
            $protocol->shouldReceive('examineFolder')
                ->once()->with('INBOX')
                ->andReturn($this->response(['uidvalidity' => 777]));
            $protocol->shouldReceive('fetch')
                ->once()->with(['UID', 'FLAGS', 'RFC822.SIZE'], [55], null, IMAP::ST_UID)
                ->andReturn($this->response([
                    55 => ['UID' => 55, 'FLAGS' => [], 'RFC822.SIZE' => $reportedSize],
                ]));
            $protocol->shouldReceive('fetch')
                ->once()->with(['UID', $this->headerPeekItem()], [55], null, IMAP::ST_UID)
                ->andReturn($this->response([
                    55 => [
                        'UID' => 55,
                        // A malicious server returned more than the requested
                        // cap-plus-one partial literal.
                        'BODY[HEADER]<0>' => str_repeat('x', $literalBytes),
                    ],
                ]));

            try {
                $this->imapSession($this->client($protocol, 'INBOX'))
                    ->messageByUidPeek(9, 4, 'INBOX', 777, 55);
                $this->fail('An overlong provider header literal must fail before parsing or TEXT.');
            } catch (EmailProviderReconciliationReadException $exception) {
                $this->assertSame(
                    'provider_message_header_byte_cap_exceeded',
                    $exception->safeCode,
                );
            }
        }
    }

    #[Test]
    public function oversize_message_projects_exact_header_and_never_requests_text(): void
    {
        $protocol = $this->readOnlyProtocol();
        $protocol->shouldReceive('examineFolder')
            ->once()->with('Archive')
            ->andReturn($this->response(['uidvalidity' => 701]));
        $protocol->shouldReceive('fetch')
            ->once()->with(['UID', 'FLAGS', 'RFC822.SIZE'], [8], null, IMAP::ST_UID)
            ->andReturn($this->response([
                8 => ['UID' => 8, 'FLAGS' => ['\\Seen'], 'RFC822.SIZE' => 101],
            ]));
        $protocol->shouldReceive('fetch')
            ->once()->with(['UID', $this->headerPeekItem()], [8], null, IMAP::ST_UID)
            ->andReturn($this->response([
                8 => [
                    'UID' => 8,
                    'BODY[HEADER]' => implode("\r\n", [
                        'Message-ID: <oversize@example.test>',
                        'Subject: Oversize metadata survives',
                        'From: sender@example.test',
                    ]),
                ],
            ]));
        $protocol->shouldReceive('folderStatus')
            ->once()->with('Archive', ['UIDVALIDITY'])
            ->andReturn($this->response(['uidvalidity' => 701]));
        $client = $this->client($protocol, 'Archive');

        $peeked = $this->imapSession($client, 100)->messageByUidPeek(3, 2, 'Archive', 701, 8);

        $this->assertNotNull($peeked);
        $this->assertTrue($peeked->payload()['is_oversize']);
        $this->assertSame(101, $peeked->payload()['size_bytes']);
        $this->assertSame('Oversize metadata survives', $peeked->payload()['subject']);
        $this->assertSame('', $peeked->message()->getTextBody());
    }

    #[Test]
    public function complete_raw_peek_rejects_a_short_provider_literal_before_parse_or_store(): void
    {
        $bodyCap = 220;
        $header = implode("\r\n", [
            'Message-ID: <lying-size@example.test>',
            'Subject: Bounded body',
            'From: sender@example.test',
        ]);
        $raw = $header."\r\n\r\n".'short body';
        $reportedSize = strlen($raw) + 10;
        $protocol = $this->readOnlyProtocol();
        $protocol->shouldReceive('examineFolder')->once()->with('INBOX')
            ->andReturn($this->response(['uidvalidity' => 777]));
        $protocol->shouldReceive('fetch')->once()
            ->with(['UID', 'FLAGS', 'RFC822.SIZE'], [55], null, IMAP::ST_UID)
            ->andReturn($this->response([
                55 => ['UID' => 55, 'FLAGS' => [], 'RFC822.SIZE' => $reportedSize],
            ]));
        $protocol->shouldReceive('fetch')->once()
            ->with(['UID', $this->fullPeekItem($reportedSize)], [55], null, IMAP::ST_UID)
            ->andReturn($this->response([
                55 => ['UID' => 55, 'BODY[]<0>' => $raw],
            ]));

        try {
            $this->imapSession($this->client($protocol, 'INBOX'), $bodyCap)
                ->messageByUidPeek(9, 4, 'INBOX', 777, 55);
            $this->fail('A short complete raw literal must fail before parsing or storage.');
        } catch (EmailProviderReconciliationReadException $exception) {
            $this->assertSame('provider_message_literal_length_mismatch', $exception->safeCode);
        }
    }

    #[Test]
    public function complete_raw_peek_accepts_the_exact_reported_message_boundary(): void
    {
        $header = implode("\r\n", [
            'Message-ID: <exact-body-boundary@example.test>',
            'Subject: Exact boundary',
            'From: sender@example.test',
        ]);
        $body = '0123456789';
        $raw = $header."\r\n\r\n".$body;
        $bodyCap = strlen($raw);
        $protocol = $this->readOnlyProtocol();
        $protocol->shouldReceive('examineFolder')->once()->with('INBOX')
            ->andReturn($this->response(['uidvalidity' => 777]));
        $protocol->shouldReceive('fetch')->once()
            ->with(['UID', 'FLAGS', 'RFC822.SIZE'], [55], null, IMAP::ST_UID)
            ->andReturn($this->response([
                55 => ['UID' => 55, 'FLAGS' => [], 'RFC822.SIZE' => $bodyCap],
            ]));
        $protocol->shouldReceive('fetch')->once()
            ->with(['UID', $this->fullPeekItem($bodyCap)], [55], null, IMAP::ST_UID)
            ->andReturn($this->response([
                55 => ['UID' => 55, 'BODY.PEEK[]<0>' => $raw],
            ]));
        $protocol->shouldReceive('folderStatus')->once()->with('INBOX', ['UIDVALIDITY'])
            ->andReturn($this->response(['uidvalidity' => 777]));

        $peeked = $this->imapSession($this->client($protocol, 'INBOX'), $bodyCap)
            ->messageByUidPeek(9, 4, 'INBOX', 777, 55);

        $this->assertNotNull($peeked);
        $this->assertSame($body, $peeked->message()->getTextBody());
    }

    #[Test]
    public function metadata_search_is_hard_span_bounded_and_fetches_only_the_result_limit(): void
    {
        $protocol = $this->readOnlyProtocol();
        $protocol->shouldReceive('getCapabilities')
            ->once()->ordered()
            ->andReturn($this->response(['IMAP4rev1', 'CONDSTORE']));
        $protocol->shouldReceive('examineFolder')
            ->once()->with('INBOX')->ordered()
            ->andReturn($this->response([
                'uidvalidity' => 900,
                'highestmodseq' => 49,
            ]));
        $protocol->shouldReceive('search')
            ->once()->with(['UID 1000001:1010000'], IMAP::ST_UID)->ordered()
            ->andReturn($this->response([1_000_005, 1_009_999, 1_010_000]));
        $protocol->shouldReceive('fetch')
            ->once()->with(
                ['UID', 'FLAGS', 'MODSEQ'],
                [1_000_005, 1_009_999],
                null,
                IMAP::ST_UID,
            )->ordered()
            ->andReturn($this->response([
                1_000_005 => [
                    'UID' => 1_000_005,
                    'FLAGS' => ['\\Seen'],
                    'MODSEQ' => [50],
                ],
                1_009_999 => [
                    'UID' => 1_009_999,
                    'FLAGS' => ['Custom'],
                    'MODSEQ' => [51],
                ],
            ]));
        $protocol->shouldReceive('folderStatus')
            ->once()->with('INBOX', ['UIDVALIDITY'])->ordered()
            ->andReturn($this->response(['uidvalidity' => 900]));
        $client = $this->client($protocol, 'INBOX');

        $page = $this->imapSession($client)->metadataPage(
            'INBOX',
            900,
            1_000_000,
            1_020_000,
            2,
        );

        $this->assertFalse($page->terminal);
        $this->assertSame(1_009_999, $page->completeThroughUid);
        $this->assertSame([1_000_005, 1_009_999], array_map(
            fn ($message): int => $message->uid,
            $page->messages,
        ));
        $this->assertSame(50, $page->messages[0]->modseq);
        $this->assertSame(['custom'], $page->messages[1]->customFlags);
    }

    #[Test]
    public function mailbox_local_nomodseq_overrides_the_global_condstore_capability(): void
    {
        $stateProtocol = $this->readOnlyProtocol();
        $stateProtocol->shouldReceive('getCapabilities')->once()->ordered()
            ->andReturn($this->response(['IMAP4rev1', 'CONDSTORE']));
        $stateProtocol->shouldReceive('folderStatus')->once()->ordered()
            ->with('INBOX', ['MESSAGES', 'UIDNEXT', 'UIDVALIDITY', 'HIGHESTMODSEQ'])
            ->andReturn($this->response([
                'messages' => 1,
                'uidnext' => 2,
                'uidvalidity' => 902,
                'highestmodseq' => 0,
            ]));

        $state = $this->imapSession($this->client($stateProtocol, null))
            ->folderState('INBOX');

        $this->assertFalse($state->supportsModseq);
        $this->assertNull($state->highestModseq);

        $metadataProtocol = $this->readOnlyProtocol();
        $metadataProtocol->shouldReceive('getCapabilities')->once()->ordered()
            ->andReturn($this->response(['IMAP4rev1', 'CONDSTORE']));
        $metadataProtocol->shouldReceive('examineFolder')->once()->with('INBOX')->ordered()
            ->andReturn($this->response(
                ['uidvalidity' => 902],
                ['* OK [NOMODSEQ] No persistent mod-sequences'],
            ));
        $metadataProtocol->shouldReceive('search')->once()->ordered()
            ->with(['UID 1:1'], IMAP::ST_UID)
            ->andReturn($this->response([1]));
        $metadataProtocol->shouldReceive('fetch')->once()->ordered()
            ->with(['UID', 'FLAGS'], [1], null, IMAP::ST_UID)
            ->andReturn($this->response([
                1 => ['UID' => 1, 'FLAGS' => ['\\Seen']],
            ]));
        $metadataProtocol->shouldReceive('folderStatus')->once()->ordered()
            ->with('INBOX', ['UIDVALIDITY'])
            ->andReturn($this->response(['uidvalidity' => 902]));

        $page = $this->imapSession($this->client($metadataProtocol, 'INBOX'))
            ->metadataPage('INBOX', 902, 0, 1, 1);

        $this->assertNull($page->messages[0]->modseq);
        $this->assertTrue($page->messages[0]->seen);
    }

    #[Test]
    public function empty_sparse_gap_resumes_to_later_results_and_only_exact_boundary_is_terminal(): void
    {
        $protocol = $this->readOnlyProtocol();
        $protocol->shouldReceive('getCapabilities')->once()->ordered()
            ->andReturn($this->response(['IMAP4rev1']));
        $protocol->shouldReceive('examineFolder')->once()->with('INBOX')->ordered()
            ->andReturn($this->response(['uidvalidity' => 901]));
        $protocol->shouldReceive('search')
            ->once()->with(['UID 1000001:1010000'], IMAP::ST_UID)->ordered()
            ->andReturn($this->response([]));
        $protocol->shouldReceive('folderStatus')->once()
            ->with('INBOX', ['UIDVALIDITY'])->ordered()
            ->andReturn($this->response(['uidvalidity' => 901]));

        $protocol->shouldReceive('getCapabilities')->once()->ordered()
            ->andReturn($this->response(['IMAP4rev1']));
        $protocol->shouldReceive('examineFolder')->once()->with('INBOX')->ordered()
            ->andReturn($this->response(['uidvalidity' => 901]));
        $protocol->shouldReceive('search')
            ->once()->with(['UID 1010001:1020000'], IMAP::ST_UID)->ordered()
            ->andReturn($this->response([1_015_000]));
        $protocol->shouldReceive('fetch')
            ->once()->with(['UID', 'FLAGS'], [1_015_000], null, IMAP::ST_UID)->ordered()
            ->andReturn($this->response([
                1_015_000 => ['UID' => 1_015_000, 'FLAGS' => ['\\Answered']],
            ]));
        $protocol->shouldReceive('folderStatus')->once()
            ->with('INBOX', ['UIDVALIDITY'])->ordered()
            ->andReturn($this->response(['uidvalidity' => 901]));

        $protocol->shouldReceive('getCapabilities')->once()->ordered()
            ->andReturn($this->response(['IMAP4rev1']));
        $protocol->shouldReceive('examineFolder')->once()->with('INBOX')->ordered()
            ->andReturn($this->response(['uidvalidity' => 901]));
        $session = $this->imapSession($this->client($protocol, 'INBOX', 3));

        $gap = $session->metadataPage('INBOX', 901, 1_000_000, 1_020_000, 250);
        $result = $session->metadataPage('INBOX', 901, 1_010_000, 1_020_000, 250);
        $terminal = $session->metadataPage('INBOX', 901, 1_020_000, 1_020_000, 250);

        $this->assertFalse($gap->terminal);
        $this->assertSame(1_010_000, $gap->completeThroughUid);
        $this->assertSame([], $gap->messages);
        $this->assertFalse($result->terminal);
        $this->assertSame(1_020_000, $result->completeThroughUid);
        $this->assertSame(1_015_000, $result->messages[0]->uid);
        $this->assertTrue($result->messages[0]->answered);
        $this->assertTrue($terminal->terminal);
        $this->assertSame(1_020_000, $terminal->completeThroughUid);
        $this->assertSame([], $terminal->messages);
    }

    #[Test]
    public function metadata_result_limit_cannot_exceed_the_hard_provider_batch(): void
    {
        $client = Mockery::mock(Client::class);

        try {
            $this->imapSession($client)->metadataPage(
                'INBOX',
                1,
                0,
                1,
                EmailProviderReconciliationPolicy::HARD_UID_BATCH_SIZE + 1,
            );
            $this->fail('An oversized result batch must fail before any provider call.');
        } catch (EmailProviderReconciliationReadException $exception) {
            $this->assertSame('provider_metadata_scope_invalid', $exception->safeCode);
        }
    }

    #[Test]
    public function effective_body_cap_honors_lower_positive_setting_and_clamps_higher_setting(): void
    {
        $policy = new EmailProviderReconciliationPolicy;

        $this->assertSame(5 * 1024 * 1024, $policy->bodyByteCap(5));
        $this->assertSame(
            EmailProviderReconciliationPolicy::HARD_MESSAGE_BYTES,
            $policy->bodyByteCap(50),
        );
        $this->assertSame(
            EmailProviderReconciliationPolicy::HARD_MESSAGE_BYTES,
            $policy->bodyByteCap(0),
        );
    }

    #[Test]
    public function detached_payload_clamps_provider_header_fields_to_message_schema_boundaries(): void
    {
        $messageId = '<'.str_repeat('m', 260).'@example.test>';
        $inReplyTo = '<'.str_repeat('r', 260).'@example.test>';
        $subject = str_repeat('ø', 513);
        $fromName = str_repeat('N', 256);
        $fromEmail = str_repeat('a', 250).'@example.test';
        $message = Message::fromString(implode("\r\n", [
            'Message-ID: '.$messageId,
            'In-Reply-To: '.$inReplyTo,
            'Subject: '.$subject,
            'From: '.$fromName.' <'.$fromEmail.'>',
            'To: receiver@example.test',
            '',
            'Bounded payload.',
        ]));
        $message->setUid(91)->setFolderPath('INBOX');

        $payload = app(EmailProviderReconciliationMessagePayload::class)->make(
            $message,
            1,
            1,
            'INBOX',
            1,
            1_024,
            false,
        );

        $this->assertSame(255, mb_strlen((string) $payload['message_id']));
        $this->assertSame(255, mb_strlen((string) $payload['in_reply_to']));
        $this->assertSame(512, mb_strlen((string) $payload['subject']));
        $this->assertSame(255, mb_strlen((string) $payload['from_name']));
        $this->assertSame(255, mb_strlen((string) $payload['from_email']));
        $this->assertSame(str_repeat('ø', 512), $payload['subject']);
    }

    #[Test]
    public function malformed_envelope_parser_and_fingerprint_traces_redact_all_provider_canaries(): void
    {
        $previousIgnoreArgs = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        $canaries = [
            'trace-folder-canary',
            'trace-address-canary@example.test',
            'trace-subject-canary',
            'trace-body-canary',
            'trace-endpoint-canary.example',
            'trace-user-canary@example.test',
        ];
        $diagnostics = [];

        try {
            $message = Message::fromString(implode("\r\n", [
                'Message-ID: <trace-envelope@example.test>',
                'Subject: '.$canaries[2],
                'From: '.$canaries[1],
                '',
                $canaries[3],
            ]));
            $message->setUid(1)->setFolderPath($canaries[0]);
            try {
                new EmailProviderReconciliationPeekedMessage([
                    'imap_uid' => 2,
                    'folder_path' => $canaries[0],
                    'from_email' => $canaries[1],
                    'subject' => $canaries[2],
                    'body_text' => $canaries[3],
                ], $message);
                $this->fail('A mismatched envelope must fail.');
            } catch (\InvalidArgumentException $exception) {
                $diagnostics[] = (string) $exception;
            }

            $protocol = $this->readOnlyProtocol();
            $header = implode("\r\n", [
                'Message-ID: <trace-parser@example.test>',
                'Subject: '.$canaries[2],
                'From: '.$canaries[1],
                'Date: not-a-date-'.$canaries[4],
            ]);
            $raw = $header."\r\n\r\n".$canaries[3];
            $protocol->shouldReceive('examineFolder')
                ->once()->with($canaries[0])
                ->andReturn($this->response(['uidvalidity' => 500]));
            $protocol->shouldReceive('fetch')
                ->once()->with(['UID', 'FLAGS', 'RFC822.SIZE'], [7], null, IMAP::ST_UID)
                ->andReturn($this->response([
                    7 => ['UID' => 7, 'FLAGS' => [], 'RFC822.SIZE' => strlen($raw)],
                ]));
            $protocol->shouldReceive('fetch')
                ->once()->with(['UID', $this->fullPeekItem(strlen($raw))], [7], null, IMAP::ST_UID)
                ->andReturn($this->response([
                    7 => [
                        'UID' => 7,
                        'BODY[]' => $raw,
                    ],
                ]));
            $protocol->shouldReceive('folderStatus')
                ->once()->with($canaries[0], ['UIDVALIDITY'])
                ->andReturn($this->response(['uidvalidity' => 500]));
            $client = $this->client($protocol, $canaries[0]);
            try {
                $this->imapSession($client)->messageByUidPeek(1, 1, $canaries[0], 500, 7);
                $this->fail('The malformed provider date must fail parsing.');
            } catch (EmailProviderReconciliationReadException $exception) {
                $this->assertSame('provider_message_parse_failed', $exception->safeCode);
                $diagnostics[] = (string) $exception;
            }

            try {
                app(EmailProviderReconciliationFingerprint::class)->make([
                    'folder_path' => $canaries[0],
                    'endpoint' => $canaries[4],
                    'username' => $canaries[5],
                    'invalid_utf8' => "\xB1\x31",
                ]);
                $this->fail('Invalid UTF-8 fingerprint facts must fail.');
            } catch (\JsonException $exception) {
                $diagnostics[] = (string) $exception;
            }
        } finally {
            if ($previousIgnoreArgs !== false) {
                ini_set('zend.exception_ignore_args', (string) $previousIgnoreArgs);
            }
        }

        $encoded = implode('|', $diagnostics);
        foreach ($canaries as $canary) {
            $this->assertStringNotContainsString($canary, $encoded);
        }
    }

    private function readOnlyProtocol(): ProtocolInterface
    {
        $protocol = Mockery::mock(ProtocolInterface::class);
        $protocol->shouldNotReceive('selectFolder');
        $protocol->shouldNotReceive('store');
        $protocol->shouldNotReceive('content');
        $protocol->shouldNotReceive('headers');
        $protocol->shouldNotReceive('flags');
        $protocol->shouldNotReceive('copyMessage');
        $protocol->shouldNotReceive('moveMessage');
        $protocol->shouldNotReceive('expunge');

        return $protocol;
    }

    private function client(
        ProtocolInterface $protocol,
        ?string $folderPath,
        int $folderSelections = 1,
    ): Client {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('getConnection')->andReturn($protocol);
        if ($folderPath === null) {
            $client->shouldNotReceive('setActiveFolder');
        } else {
            $client->shouldReceive('setActiveFolder')->times($folderSelections)->with($folderPath);
        }
        $client->shouldReceive('getConfig')->zeroOrMoreTimes()->andReturn(Config::make());
        $client->shouldNotReceive('openFolder');
        $client->shouldNotReceive('expunge');

        return $client;
    }

    private function imapSession(
        Client $client,
        int $bodyByteCap = EmailProviderReconciliationPolicy::HARD_MESSAGE_BYTES,
    ): EmailProviderReconciliationImapSession {
        return new EmailProviderReconciliationImapSession(
            $client,
            app(EmailProviderReconciliationMessagePayload::class),
            $bodyByteCap,
        );
    }

    /** @param array<int, mixed> $raw */
    private function response(mixed $result, array $raw = []): Response
    {
        return (new Response(1))
            ->setCanBeEmpty(true)
            ->setResponse($raw)
            ->setResult($result);
    }

    private function headerPeekItem(): string
    {
        return sprintf(
            'BODY.PEEK[HEADER]<0.%d>',
            EmailProviderReconciliationPolicy::HARD_HEADER_BYTES + 1,
        );
    }

    private function fullPeekItem(int $reportedSize): string
    {
        return sprintf('BODY.PEEK[]<0.%d>', $reportedSize + 1);
    }
}
