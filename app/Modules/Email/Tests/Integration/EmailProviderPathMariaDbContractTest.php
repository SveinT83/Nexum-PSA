<?php

namespace App\Modules\Email\Tests\Integration;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Opt-in contract against an isolated actual MariaDB database.
 */
class EmailProviderPathMariaDbContractTest extends TestCase
{
    /** @var array<string, array<string, array{nullable:bool,legacy_chars:int,default_inbox:bool}>> */
    private const COLUMNS = [
        'email_folders' => [
            'path' => ['nullable' => false, 'legacy_chars' => 512, 'default_inbox' => false],
            'parent_path' => ['nullable' => true, 'legacy_chars' => 512, 'default_inbox' => false],
        ],
        'email_mailbox_placements' => [
            'folder_path' => ['nullable' => false, 'legacy_chars' => 512, 'default_inbox' => false],
        ],
        'email_messages' => [
            'mailbox' => ['nullable' => false, 'legacy_chars' => 512, 'default_inbox' => true],
        ],
        'email_provider_reconciliation_folders' => [
            'folder_path' => ['nullable' => false, 'legacy_chars' => 512, 'default_inbox' => false],
            'parent_path' => ['nullable' => true, 'legacy_chars' => 512, 'default_inbox' => false],
        ],
        'email_remote_operations' => [
            'source_folder_path' => ['nullable' => true, 'legacy_chars' => 512, 'default_inbox' => false],
            'target_folder_path' => ['nullable' => true, 'legacy_chars' => 512, 'default_inbox' => false],
        ],
        'email_provider_inventory_folders' => [
            'folder_path' => ['nullable' => false, 'legacy_chars' => 512, 'default_inbox' => false],
        ],
        'email_provider_placement_findings' => [
            'source_folder_path' => ['nullable' => false, 'legacy_chars' => 512, 'default_inbox' => false],
            'target_folder_path' => ['nullable' => true, 'legacy_chars' => 512, 'default_inbox' => false],
        ],
        'email_historical_import_items' => [
            'folder_path' => ['nullable' => false, 'legacy_chars' => 512, 'default_inbox' => false],
        ],
        'email_composer_drafts' => [
            'provider_draft_folder_path' => ['nullable' => true, 'legacy_chars' => 255, 'default_inbox' => false],
        ],
    ];

    /** @var array<string, array{table:string,unique:bool,columns:list<string>}> */
    private const INDEXES = [
        'email_folders_account_path_unique' => [
            'table' => 'email_folders',
            'unique' => true,
            'columns' => ['account_id', 'path'],
        ],
        'email_placements_account_folder_index' => [
            'table' => 'email_mailbox_placements',
            'unique' => false,
            'columns' => ['account_id', 'folder_path'],
        ],
        'em_msg_uid_ns_uq' => [
            'table' => 'email_messages',
            'unique' => true,
            'columns' => ['account_id', 'mailbox', 'imap_uid_validity', 'imap_uid'],
        ],
        'em_recon_folder_run_path_uq' => [
            'table' => 'email_provider_reconciliation_folders',
            'unique' => true,
            'columns' => ['email_provider_reconciliation_run_id', 'folder_path'],
        ],
        'email_provider_inv_folder_path_unique' => [
            'table' => 'email_provider_inventory_folders',
            'unique' => true,
            'columns' => ['email_provider_inventory_run_id', 'folder_path'],
        ],
        'email_drafts_provider_uid_idx' => [
            'table' => 'email_composer_drafts',
            'unique' => false,
            'columns' => ['email_account_id', 'provider_draft_folder_path', 'provider_draft_uid'],
        ],
    ];

    private ?PDO $server = null;

    private ?string $databaseName = null;

    private string $originalDefaultConnection;

    #[Test]
    public function actual_mariadb_preserves_every_byte_exact_provider_path_contract(): void
    {
        if (getenv('TDPSA_EMAIL_PROVIDER_PATH_MARIADB_CONTRACT') !== '1') {
            $this->markTestSkipped(
                'Set TDPSA_EMAIL_PROVIDER_PATH_MARIADB_CONTRACT=1 to run the isolated MariaDB path contract.',
            );
        }

        try {
            $this->connectIsolatedDatabase();
            $this->createLegacyTables();
            $this->seedRootInboxAliases();
            $migration = $this->migration();

            $migration->up();

            $this->assertBinarySchema();
            $this->assertIndexes();
            $this->assertEveryRootInboxNormalized();
            $this->insertAndAssertByteDistinctIdentities();
            $this->assertValidUtf8PreflightQueriesAreExecutable();

            $this->assertDownRefusesWithoutSchemaMutation(
                $migration,
                'Byte-distinct provider identities would collide under the legacy collation.',
            );

            $this->deleteByteDistinctUniqueIdentities();
            DB::statement(
                'insert into `email_remote_operations` (`source_folder_path`) values (0xff)',
            );
            $this->assertDownRefusesWithoutSchemaMutation(
                $migration,
                'Provider paths exceed the legacy UTF-8 schema or contain invalid bytes.',
            );
            DB::table('email_remote_operations')
                ->whereRaw('hex(`source_folder_path`) = ?', ['FF'])
                ->delete();

            DB::table('email_composer_drafts')->insert([
                'email_account_id' => 88,
                'provider_draft_folder_path' => str_repeat('x', 256),
                'provider_draft_uid' => 88,
            ]);
            $this->assertDownRefusesWithoutSchemaMutation(
                $migration,
                'Provider paths exceed the legacy UTF-8 schema or contain invalid bytes.',
            );
            DB::table('email_composer_drafts')->where('email_account_id', 88)->delete();

            $migration->down();
            $this->assertLegacySchema();
            $this->assertIndexes();
            DB::table('email_messages')->insert([
                'account_id' => 99,
                'imap_uid_validity' => 99,
                'imap_uid' => 99,
            ]);
            $this->assertSame('INBOX', DB::table('email_messages')
                ->where('account_id', 99)
                ->value('mailbox'));

            $migration->up();
            DB::statement('alter table `email_folders` drop index `email_folders_account_path_unique`');
            DB::statement(
                'alter table `email_remote_operations` modify column `source_folder_path`'
                .' varchar(512) character set utf8mb4 collate utf8mb4_unicode_ci null default null',
            );
            $this->assertFalse(Schema::hasIndex('email_folders', 'email_folders_account_path_unique'));
            $this->assertSame('varchar', $this->column('email_remote_operations', 'source_folder_path')->DATA_TYPE);

            $migration->up();
            $this->assertBinarySchema();
            $this->assertIndexes();
        } finally {
            $this->disconnectAndDropIsolatedDatabase();
        }
    }

    private function createLegacyTables(): void
    {
        Schema::create('email_folders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->string('path', 512);
            $table->string('parent_path', 512)->nullable();
            $table->unique(['account_id', 'path'], 'email_folders_account_path_unique');
        });
        Schema::create('email_mailbox_placements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->string('folder_path', 512);
            $table->index(['account_id', 'folder_path'], 'email_placements_account_folder_index');
        });
        Schema::create('email_messages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->string('mailbox', 512)->default('INBOX');
            $table->unsignedBigInteger('imap_uid_validity');
            $table->unsignedBigInteger('imap_uid');
            $table->unique(
                ['account_id', 'mailbox', 'imap_uid_validity', 'imap_uid'],
                'em_msg_uid_ns_uq',
            );
        });
        Schema::create('email_provider_reconciliation_folders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('email_provider_reconciliation_run_id');
            $table->string('folder_path', 512);
            $table->string('parent_path', 512)->nullable();
            $table->unique(
                ['email_provider_reconciliation_run_id', 'folder_path'],
                'em_recon_folder_run_path_uq',
            );
        });
        Schema::create('email_remote_operations', function (Blueprint $table): void {
            $table->id();
            $table->string('source_folder_path', 512)->nullable();
            $table->string('target_folder_path', 512)->nullable();
        });
        Schema::create('email_provider_inventory_folders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('email_provider_inventory_run_id');
            $table->string('folder_path', 512);
            $table->unique(
                ['email_provider_inventory_run_id', 'folder_path'],
                'email_provider_inv_folder_path_unique',
            );
        });
        Schema::create('email_provider_placement_findings', function (Blueprint $table): void {
            $table->id();
            $table->string('source_folder_path', 512);
            $table->string('target_folder_path', 512)->nullable();
        });
        Schema::create('email_historical_import_items', function (Blueprint $table): void {
            $table->id();
            $table->string('folder_path', 512);
        });
        Schema::create('email_composer_drafts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('email_account_id');
            $table->string('provider_draft_folder_path')->nullable();
            $table->unsignedBigInteger('provider_draft_uid')->nullable();
            $table->index(
                ['email_account_id', 'provider_draft_folder_path', 'provider_draft_uid'],
                'email_drafts_provider_uid_idx',
            );
        });
    }

    private function seedRootInboxAliases(): void
    {
        DB::table('email_folders')->insert([
            'account_id' => 1,
            'path' => 'Inbox',
            'parent_path' => 'inbox',
        ]);
        DB::table('email_mailbox_placements')->insert([
            'account_id' => 1,
            'folder_path' => 'iNbOx',
        ]);
        DB::table('email_messages')->insert([
            'account_id' => 1,
            'mailbox' => 'inbox',
            'imap_uid_validity' => 1,
            'imap_uid' => 1,
        ]);
        DB::table('email_provider_reconciliation_folders')->insert([
            'email_provider_reconciliation_run_id' => 1,
            'folder_path' => 'INbox',
            'parent_path' => 'Inbox',
        ]);
        DB::table('email_remote_operations')->insert([
            'source_folder_path' => 'inBox',
            'target_folder_path' => 'INboX',
        ]);
        DB::table('email_provider_inventory_folders')->insert([
            'email_provider_inventory_run_id' => 1,
            'folder_path' => 'Inbox',
        ]);
        DB::table('email_provider_placement_findings')->insert([
            'source_folder_path' => 'inbox',
            'target_folder_path' => 'Inbox',
        ]);
        DB::table('email_historical_import_items')->insert([
            'folder_path' => 'INbox',
        ]);
        DB::table('email_composer_drafts')->insert([
            'email_account_id' => 1,
            'provider_draft_folder_path' => 'inbox',
            'provider_draft_uid' => 1,
        ]);
    }

    private function assertEveryRootInboxNormalized(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach (array_keys($columns) as $column) {
                $this->assertSame(
                    'INBOX',
                    DB::table($table)->where('id', 1)->value($column),
                    "{$table}.{$column} was not normalized.",
                );
            }
        }
    }

    private function insertAndAssertByteDistinctIdentities(): void
    {
        $paths = ['Foo', 'foo', 'Foo ', 'Resume', 'Résumé'];
        foreach ($paths as $offset => $path) {
            DB::table('email_folders')->insert([
                'account_id' => 7,
                'path' => $path,
            ]);
            DB::table('email_mailbox_placements')->insert([
                'account_id' => 7,
                'folder_path' => $path,
            ]);
            DB::table('email_messages')->insert([
                'account_id' => 7,
                'mailbox' => $path,
                'imap_uid_validity' => 77,
                'imap_uid' => 777,
            ]);
            DB::table('email_provider_reconciliation_folders')->insert([
                'email_provider_reconciliation_run_id' => 7,
                'folder_path' => $path,
            ]);
            DB::table('email_provider_inventory_folders')->insert([
                'email_provider_inventory_run_id' => 7,
                'folder_path' => $path,
            ]);
            DB::table('email_provider_placement_findings')->insert([
                'source_folder_path' => $path,
                'target_folder_path' => $paths[4 - $offset],
            ]);
            DB::table('email_composer_drafts')->insert([
                'email_account_id' => 7,
                'provider_draft_folder_path' => $path,
                'provider_draft_uid' => 777,
            ]);
        }

        foreach ($paths as $path) {
            $this->assertSame(1, DB::table('email_folders')
                ->where('account_id', 7)->where('path', $path)->count());
            $this->assertSame(1, DB::table('email_messages')
                ->where('account_id', 7)
                ->where('mailbox', $path)
                ->where('imap_uid_validity', 77)
                ->where('imap_uid', 777)
                ->count());
            $this->assertSame(1, DB::table('email_provider_reconciliation_folders')
                ->where('email_provider_reconciliation_run_id', 7)
                ->where('folder_path', $path)
                ->count());
            $this->assertSame(1, DB::table('email_provider_inventory_folders')
                ->where('email_provider_inventory_run_id', 7)
                ->where('folder_path', $path)
                ->count());
            $this->assertSame(1, DB::table('email_composer_drafts')
                ->where('email_account_id', 7)
                ->where('provider_draft_folder_path', $path)
                ->where('provider_draft_uid', 777)
                ->count());
            $this->assertSame(1, DB::table('email_provider_placement_findings')
                ->where('source_folder_path', $path)
                ->count());
        }
    }

    private function deleteByteDistinctUniqueIdentities(): void
    {
        DB::table('email_folders')->where('account_id', 7)->delete();
        DB::table('email_messages')->where('account_id', 7)->delete();
        DB::table('email_provider_reconciliation_folders')
            ->where('email_provider_reconciliation_run_id', 7)->delete();
        DB::table('email_provider_inventory_folders')
            ->where('email_provider_inventory_run_id', 7)->delete();
    }

    private function assertValidUtf8PreflightQueriesAreExecutable(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column => $definition) {
                $quotedTable = $this->quoteIdentifier($table);
                $quotedColumn = $this->quoteIdentifier($column);
                $invalid = DB::selectOne(
                    "select 1 as invalid from {$quotedTable}"
                    ." where {$quotedColumn} is not null and ("
                    ."octet_length({$quotedColumn}) > ?"
                    ." or locate(0x00, {$quotedColumn}) > 0"
                    ." or char_length(convert({$quotedColumn} using utf8mb4)) > ?"
                    ." or hex({$quotedColumn}) <> hex("
                    ."convert({$quotedColumn} using utf8mb4))"
                    .') limit 1',
                    [2048, $definition['legacy_chars']],
                );

                $this->assertNull(
                    $invalid,
                    "Valid UTF-8 fixture failed preflight for {$table}.{$column}.",
                );
            }
        }
    }

    private function assertDownRefusesWithoutSchemaMutation(
        Migration $migration,
        string $expectedMessage,
    ): void {
        $beforeColumns = $this->columnFacts();
        $beforeIndexes = $this->indexFacts();

        try {
            $migration->down();
            $this->fail('The provider-path rollback accepted lossy data.');
        } catch (RuntimeException $exception) {
            $this->assertSame($expectedMessage, $exception->getMessage());
        }

        $this->assertSame($beforeColumns, $this->columnFacts());
        $this->assertSame($beforeIndexes, $this->indexFacts());
    }

    private function assertBinarySchema(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column => $definition) {
                $facts = $this->column($table, $column);
                $this->assertSame('varbinary', strtolower((string) $facts->DATA_TYPE));
                $this->assertSame(2048, (int) $facts->CHARACTER_MAXIMUM_LENGTH);
                $this->assertNull($facts->COLLATION_NAME);
                $this->assertSame($definition['nullable'] ? 'YES' : 'NO', $facts->IS_NULLABLE);
                $definition['default_inbox']
                    ? $this->assertStringContainsString('INBOX', (string) $facts->COLUMN_DEFAULT)
                    : $this->assertNullDefaultMetadata($facts->COLUMN_DEFAULT);
            }
        }
    }

    private function assertLegacySchema(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column => $definition) {
                $facts = $this->column($table, $column);
                $this->assertSame('varchar', strtolower((string) $facts->DATA_TYPE));
                $this->assertSame($definition['legacy_chars'], (int) $facts->CHARACTER_MAXIMUM_LENGTH);
                $this->assertSame('utf8mb4_unicode_ci', strtolower((string) $facts->COLLATION_NAME));
                $this->assertSame($definition['nullable'] ? 'YES' : 'NO', $facts->IS_NULLABLE);
                $definition['default_inbox']
                    ? $this->assertStringContainsString('INBOX', (string) $facts->COLUMN_DEFAULT)
                    : $this->assertNullDefaultMetadata($facts->COLUMN_DEFAULT);
            }
        }
    }

    private function assertNullDefaultMetadata(mixed $default): void
    {
        // MariaDB can expose DEFAULT NULL through PDO as either null or the
        // literal metadata token NULL. Both attest the same SQL default.
        $this->assertTrue(
            $default === null || $default === 'NULL',
            'Expected no non-null column default.',
        );
    }

    private function assertIndexes(): void
    {
        foreach (self::INDEXES as $name => $definition) {
            $rows = DB::select(
                'select COLUMN_NAME, NON_UNIQUE from information_schema.STATISTICS'
                .' where TABLE_SCHEMA = ? and TABLE_NAME = ? and INDEX_NAME = ? order by SEQ_IN_INDEX',
                [$this->databaseName, $definition['table'], $name],
            );
            $this->assertSame($definition['columns'], array_map(
                static fn (object $row): string => (string) $row->COLUMN_NAME,
                $rows,
            ));
            $this->assertNotEmpty($rows);
            $this->assertSame($definition['unique'] ? 0 : 1, (int) $rows[0]->NON_UNIQUE);
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function columnFacts(): array
    {
        $facts = [];
        foreach (self::COLUMNS as $table => $columns) {
            foreach (array_keys($columns) as $column) {
                $facts[$table.'.'.$column] = (array) $this->column($table, $column);
            }
        }

        return $facts;
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function indexFacts(): array
    {
        $facts = [];
        foreach (self::INDEXES as $name => $definition) {
            $facts[$name] = array_map(
                static fn (object $row): array => (array) $row,
                DB::select(
                    'select INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SEQ_IN_INDEX'
                    .' from information_schema.STATISTICS'
                    .' where TABLE_SCHEMA = ? and TABLE_NAME = ? and INDEX_NAME = ? order by SEQ_IN_INDEX',
                    [$this->databaseName, $definition['table'], $name],
                ),
            );
        }

        return $facts;
    }

    private function column(string $table, string $column): object
    {
        return DB::selectOne(
            'select DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE, COLUMN_DEFAULT, COLLATION_NAME'
            .' from information_schema.COLUMNS'
            .' where TABLE_SCHEMA = ? and TABLE_NAME = ? and COLUMN_NAME = ?',
            [$this->databaseName, $table, $column],
        ) ?? throw new RuntimeException("Missing contract column {$table}.{$column}.");
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (preg_match('/^[a-z0-9_]+$/', $identifier) !== 1) {
            throw new RuntimeException('Unsafe provider-path contract identifier.');
        }

        return '`'.$identifier.'`';
    }

    private function migration(): Migration
    {
        return require database_path(
            'migrations/2026_08_16_118400_make_email_provider_paths_byte_exact.php',
        );
    }

    private function connectIsolatedDatabase(): void
    {
        $mysql = (array) config('database.connections.mysql');
        $host = (string) ($mysql['host'] ?? '127.0.0.1');
        $port = (int) ($mysql['port'] ?? 3306);
        $username = (string) ($mysql['username'] ?? '');
        $password = (string) ($mysql['password'] ?? '');
        $socket = trim((string) getenv('TDPSA_EMAIL_PROVIDER_MARIADB_SOCKET'));
        $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
        if ($socket !== '') {
            $dsn = "mysql:unix_socket={$socket};charset=utf8mb4";
            $username = 'root';
            $password = '';
            $mysql['host'] = 'localhost';
            $mysql['port'] = null;
            $mysql['unix_socket'] = $socket;
            $mysql['username'] = $username;
            $mysql['password'] = $password;
            $mysql['options'] = [];
        }

        $this->databaseName = 'tdpsa_path_contract_'.strtolower(Str::random(12));
        if (preg_match('/^tdpsa_path_contract_[a-z0-9]{12}$/', $this->databaseName) !== 1) {
            throw new RuntimeException('The isolated MariaDB path contract name failed validation.');
        }

        $this->server = new PDO(
            $dsn,
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $this->server->exec(
            'CREATE DATABASE `'.$this->databaseName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        );

        $this->originalDefaultConnection = (string) config('database.default');
        config()->set('database.connections.provider_path_mariadb_contract', [
            ...$mysql,
            'driver' => 'mariadb',
            'database' => $this->databaseName,
        ]);
        config()->set('database.default', 'provider_path_mariadb_contract');
        DB::purge('provider_path_mariadb_contract');
        DB::connection('provider_path_mariadb_contract')->getPdo();
        $this->assertSame('mariadb', DB::getDriverName());
    }

    private function disconnectAndDropIsolatedDatabase(): void
    {
        if (isset($this->originalDefaultConnection)) {
            DB::disconnect('provider_path_mariadb_contract');
            config()->set('database.default', $this->originalDefaultConnection);
            DB::purge('provider_path_mariadb_contract');
        }

        if ($this->server && $this->databaseName) {
            if (preg_match('/^tdpsa_path_contract_[a-z0-9]{12}$/', $this->databaseName) !== 1) {
                throw new RuntimeException('Refusing to drop an unvalidated MariaDB path contract database.');
            }
            $this->server->exec('DROP DATABASE IF EXISTS `'.$this->databaseName.'`');
        }

        $this->server = null;
        $this->databaseName = null;
    }
}
