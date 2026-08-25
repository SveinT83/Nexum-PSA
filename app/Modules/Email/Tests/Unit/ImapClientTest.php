<?php

namespace App\Modules\Email\Tests\Unit;

use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Services\ImapClient;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;
use Webklex\PHPIMAP\Query\WhereQuery;
use Webklex\PHPIMAP\Support\MessageCollection;

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

    #[Test]
    public function fetch_by_uid_uses_one_exact_bounded_peek_query_without_a_folder_fallback(): void
    {
        $providerMessage = new \stdClass;
        $messages = MessageCollection::make([$providerMessage]);
        $query = $this->createMock(WhereQuery::class);
        $query->expects($this->once())->method('whereUid')->with(4567)->willReturnSelf();
        $query->expects($this->once())->method('setSequence')->with(IMAP::ST_UID)->willReturnSelf();
        $query->expects($this->once())->method('leaveUnread')->willReturnSelf();
        $query->expects($this->once())->method('limit')->with(1)->willReturnSelf();
        $query->expects($this->once())->method('get')->willReturn($messages);

        $folder = $this->createMock(Folder::class);
        $folder->expects($this->once())->method('query')->willReturn($query);
        $folder->expects($this->never())->method('messages');

        $provider = new class($folder) extends Client
        {
            public array $folderRequests = [];

            public function __construct(private readonly Folder $folder) {}

            public function __destruct() {}

            public function getFolderByPath($folder_path, bool $utf7 = false, bool $soft_fail = false): ?Folder
            {
                $this->folderRequests[] = $folder_path;

                return $this->folder;
            }
        };

        $client = new class(new EmailAccount) extends ImapClient
        {
            public array $existenceChecks = [];

            public function messageExistsByUid(int $uid, string $folderPath = 'INBOX'): bool
            {
                $this->existenceChecks[] = [$uid, $folderPath];

                return true;
            }
        };
        (new ReflectionProperty(ImapClient::class, 'client'))->setValue($client, $provider);

        $this->assertSame($providerMessage, $client->fetchByUid(4567, 'Archive/2026'));
        $this->assertSame([[4567, 'Archive/2026']], $client->existenceChecks);
        $this->assertSame(['Archive/2026'], $provider->folderRequests);

        $peekContract = (new \ReflectionClass(WhereQuery::class))->newInstanceWithoutConstructor();
        $peekContract->leaveUnread();
        $this->assertSame(IMAP::FT_PEEK, $peekContract->getFetchOptions());
    }

    #[Test]
    public function fetch_by_uid_returns_null_when_the_exact_uid_query_confirms_no_message(): void
    {
        $client = new class(new EmailAccount) extends ImapClient
        {
            public array $existenceChecks = [];

            public function messageExistsByUid(int $uid, string $folderPath = 'INBOX'): bool
            {
                $this->existenceChecks[] = [$uid, $folderPath];

                return false;
            }
        };

        $this->assertNull($client->fetchByUid(7654));
        $this->assertSame([[7654, 'INBOX']], $client->existenceChecks);
    }

    #[Test]
    public function folder_discovery_uses_the_exact_child_object_when_flat_path_lookup_cannot_resolve_it(): void
    {
        $folder = static function (string $path, int $uidValidity, int $uidNext): object {
            return new class($path, $uidValidity, $uidNext)
            {
                public array $uidRanges = [];

                public array $limits = [];

                public int $gets = 0;

                public function __construct(
                    private readonly string $path,
                    private readonly int $uidValidity,
                    private readonly int $uidNext,
                ) {}

                public function getPath(): string
                {
                    return $this->path;
                }

                public function getName(): string
                {
                    return basename($this->path);
                }

                public function getDelimiter(): string
                {
                    return '/';
                }

                public function getAttributes(): array
                {
                    return [];
                }

                public function children(): array
                {
                    return [];
                }

                public function status(): array
                {
                    return [
                        'uidvalidity' => $this->uidValidity,
                        'uidnext' => $this->uidNext,
                        'messages' => 3,
                        'unseen' => 1,
                    ];
                }

                public function messages(): object
                {
                    return new class($this)
                    {
                        public function __construct(private readonly object $folder) {}

                        public function whereUid(string $range): self
                        {
                            $this->folder->uidRanges[] = $range;

                            return $this;
                        }

                        public function setFetchOrderAsc(): self
                        {
                            return $this;
                        }

                        public function limit(int $limit): self
                        {
                            $this->folder->limits[] = $limit;

                            return $this;
                        }

                        public function get(): array
                        {
                            $this->folder->gets++;

                            return [];
                        }
                    };
                }
            };
        };
        $inventory = [
            $folder('INBOX', 101, 12),
            $folder('Parent/Child', 202, 8),
        ];
        $provider = new class extends Client
        {
            public int $flatLookups = 0;

            public function __construct() {}

            public function __destruct() {}

            public function getFolderByPath($folder_path, bool $utf7 = false, bool $soft_fail = false): ?Folder
            {
                $this->flatLookups++;

                return null;
            }
        };
        $client = new class(new EmailAccount, $inventory) extends ImapClient
        {
            public function __construct(EmailAccount $account, private readonly array $inventory)
            {
                parent::__construct($account);
            }

            protected function providerFolderInventory(): iterable
            {
                return $this->inventory;
            }
        };
        (new ReflectionProperty(ImapClient::class, 'client'))->setValue($client, $provider);

        $folders = collect($client->folders())->keyBy('path');

        $this->assertSame(0, $provider->flatLookups);
        $this->assertSame(101, $folders->get('INBOX')['uid_validity']);
        $this->assertSame(12, $folders->get('INBOX')['uid_next']);
        $this->assertSame(202, $folders->get('Parent/Child')['uid_validity']);
        $this->assertSame(8, $folders->get('Parent/Child')['uid_next']);
        $this->assertNull($folders->get('Parent/Child')['sync_error_code']);

        $this->assertSame([], $client->fetchAfterUidInFolder('Parent/Child', 8, 5));
        $this->assertSame(0, $provider->flatLookups);
        $this->assertSame(['9:*'], $inventory[1]->uidRanges);
        $this->assertSame([5], $inventory[1]->limits);
        $this->assertSame(1, $inventory[1]->gets);
    }

    #[Test]
    public function historical_uid_search_chunks_the_numeric_range_and_stops_at_the_cap_plus_one_sentinel(): void
    {
        $ranges = [];
        $searches = 0;
        $query = $this->createMock(WhereQuery::class);
        $query->method('whereUid')->willReturnCallback(function (string $range) use (&$ranges, $query): WhereQuery {
            $ranges[] = $range;

            return $query;
        });
        $query->method('whereSince')->willReturnSelf();
        $query->method('whereBefore')->willReturnSelf();
        $query->method('setSequence')->with(IMAP::ST_UID)->willReturnSelf();
        $query->method('search')->willReturnCallback(function () use (&$searches) {
            $searches++;

            return collect($searches === 1 ? [999] : [1500]);
        });

        $folder = $this->createMock(Folder::class);
        $folder->expects($this->exactly(2))->method('query')->willReturn($query);
        $provider = new class($folder) extends Client
        {
            public function __construct(private readonly Folder $folder) {}

            public function __destruct() {}

            public function getFolderByPath($folder_path, bool $utf7 = false, bool $soft_fail = false): ?Folder
            {
                return $this->folder;
            }
        };
        $client = new ImapClient(new EmailAccount);
        (new ReflectionProperty(ImapClient::class, 'client'))->setValue($client, $provider);

        $uids = $client->searchHistoricalUidsInFolder(
            'Archive',
            CarbonImmutable::parse('2026-08-01', 'UTC'),
            CarbonImmutable::parse('2026-08-02', 'UTC'),
            1,
            50000,
            2,
        );

        $this->assertSame([999, 1500], $uids);
        $this->assertSame(['1:1000', '1001:2000'], $ranges);
        $this->assertSame(2, $searches);
    }

    #[Test]
    public function historical_uid_search_rejects_an_unbounded_numeric_range_before_provider_search(): void
    {
        $client = new ImapClient(new EmailAccount);

        $this->expectException(\InvalidArgumentException::class);
        $client->searchHistoricalUidsInFolder(
            'Archive',
            CarbonImmutable::parse('2026-08-01', 'UTC'),
            CarbonImmutable::parse('2026-08-02', 'UTC'),
            1,
            ImapClient::HISTORICAL_UID_MAX_SCAN_SPAN + 1,
            2,
        );
    }
}
