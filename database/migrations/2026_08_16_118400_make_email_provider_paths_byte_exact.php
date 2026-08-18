<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const BINARY_BYTES = 2048;

    private const LEGACY_COLLATION = 'utf8mb4_unicode_ci';

    /**
     * Authoritative and operational provider paths. Nullable/default facts are
     * repeated in raw DDL so a repair run cannot silently drift the contract.
     *
     * @var array<string, array<string, array{nullable:bool,legacy_chars:int,default_inbox:bool}>>
     */
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

    /** @var array<string, array<string, array{unique:bool,columns:list<string>}>> */
    private const INDEXES = [
        'email_folders' => [
            'email_folders_account_path_unique' => [
                'unique' => true,
                'columns' => ['account_id', 'path'],
            ],
        ],
        'email_mailbox_placements' => [
            'email_placements_account_folder_index' => [
                'unique' => false,
                'columns' => ['account_id', 'folder_path'],
            ],
        ],
        'email_messages' => [
            'em_msg_uid_ns_uq' => [
                'unique' => true,
                'columns' => ['account_id', 'mailbox', 'imap_uid_validity', 'imap_uid'],
            ],
        ],
        'email_provider_reconciliation_folders' => [
            'em_recon_folder_run_path_uq' => [
                'unique' => true,
                'columns' => ['email_provider_reconciliation_run_id', 'folder_path'],
            ],
        ],
        'email_provider_inventory_folders' => [
            'email_provider_inv_folder_path_unique' => [
                'unique' => true,
                'columns' => ['email_provider_inventory_run_id', 'folder_path'],
            ],
        ],
        'email_composer_drafts' => [
            'email_drafts_provider_uid_idx' => [
                'unique' => false,
                'columns' => ['email_account_id', 'provider_draft_folder_path', 'provider_draft_uid'],
            ],
        ],
    ];

    /** @var list<array{table:string,scope:list<string>,path:string}> */
    private const LEGACY_UNIQUE_IDENTITIES = [
        [
            'table' => 'email_folders',
            'scope' => ['account_id'],
            'path' => 'path',
        ],
        [
            'table' => 'email_messages',
            'scope' => ['account_id', 'imap_uid_validity', 'imap_uid'],
            'path' => 'mailbox',
        ],
        [
            'table' => 'email_provider_reconciliation_folders',
            'scope' => ['email_provider_reconciliation_run_id'],
            'path' => 'folder_path',
        ],
        [
            'table' => 'email_provider_inventory_folders',
            'scope' => ['email_provider_inventory_run_id'],
            'path' => 'folder_path',
        ],
    ];

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        $this->assertSupportedDriver($driver);
        $this->assertRootInboxNormalizationSafe($driver);
        $this->normalizeRootInboxValues($driver);

        if ($driver === 'sqlite') {
            return;
        }

        Schema::table('email_messages', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->index('account_id', 'tmp_acct_fk_idx');
        });

        Schema::table('email_provider_inventory_folders', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->index('email_provider_inventory_run_id', 'tmp_inv_run_fk_idx');
        });

        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $this->dropIndexes($table);
            $this->alterColumns($table, $columns, true);
            $this->createIndexes($table);
        }

        Schema::table('email_provider_inventory_folders', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->dropIndex('tmp_inv_run_fk_idx');
        });

        Schema::table('email_messages', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->dropIndex('tmp_acct_fk_idx');
        });
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        $this->assertSupportedDriver($driver);
        $this->assertLegacyLengthsAndEncoding($driver);
        $this->assertNoLegacyIdentityCollisions($driver);

        if ($driver === 'sqlite') {
            return;
        }

        Schema::table('email_messages', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->index('account_id', 'tmp_acct_fk_idx');
        });

        Schema::table('email_provider_inventory_folders', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->index('email_provider_inventory_run_id', 'tmp_inv_run_fk_idx');
        });

        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $this->dropIndexes($table);
            $this->alterColumns($table, $columns, false);
            $this->createIndexes($table);
        }

        Schema::table('email_provider_inventory_folders', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->dropIndex('tmp_inv_run_fk_idx');
        });

        Schema::table('email_messages', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->dropIndex('tmp_acct_fk_idx');
        });
    }

    private function assertSupportedDriver(string $driver): void
    {
        if (! in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            throw new RuntimeException('Byte-exact Email provider paths require MySQL, MariaDB, or SQLite.');
        }
    }

    private function normalizeRootInboxValues(string $driver): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach (array_keys($columns) as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                $quotedTable = $this->quoteIdentifier($table);
                $quotedColumn = $this->quoteIdentifier($column);
                if ($driver === 'sqlite') {
                    DB::statement(
                        "update {$quotedTable} set {$quotedColumn} = 'INBOX'"
                        ." where length(cast({$quotedColumn} as blob)) = 5"
                        ." and lower({$quotedColumn}) = 'inbox'",
                    );
                } else {
                    DB::statement(
                        "update {$quotedTable} set {$quotedColumn} = 0x494E424F58"
                        ." where octet_length({$quotedColumn}) = 5"
                        ." and lower(convert({$quotedColumn} using utf8mb4)) = 'inbox'",
                    );
                }
            }
        }
    }

    private function assertRootInboxNormalizationSafe(string $driver): void
    {
        foreach (self::LEGACY_UNIQUE_IDENTITIES as $identity) {
            if (! Schema::hasTable($identity['table'])
                || ! Schema::hasColumn($identity['table'], $identity['path'])) {
                continue;
            }

            $path = $this->quoteIdentifier($identity['path']);
            $scope = array_map($this->quoteIdentifier(...), $identity['scope']);
            $rootExpression = $driver === 'sqlite'
                ? "case when length(cast({$path} as blob)) = 5 and lower({$path}) = 'inbox'"
                    ." then 'INBOX' else {$path} end"
                : "case when octet_length({$path}) = 5"
                    ." and lower(convert({$path} using utf8mb4)) = 'inbox'"
                    ." then 0x494E424F58 else {$path} end";
            $group = implode(', ', [...$scope, $rootExpression]);
            $table = $this->quoteIdentifier($identity['table']);
            $collision = DB::selectOne(
                "select 1 as collision from {$table} group by {$group} having count(*) > 1 limit 1",
            );
            if ($collision !== null) {
                throw new RuntimeException(
                    'Root INBOX aliases must be reconciled before byte-exact provider paths are applied.',
                );
            }
        }
    }

    private function assertLegacyLengthsAndEncoding(string $driver): void
    {
        if ($driver === 'sqlite') {
            return;
        }

        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column => $definition) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                $tableName = $this->quoteIdentifier($table);
                $columnName = $this->quoteIdentifier($column);
                $legacyCharacters = (int) $definition['legacy_chars'];
                // Query failures are intentionally not collapsed into a data
                // error: schema/SQL faults must remain distinguishable from a
                // row that the set-based predicate proves cannot round-trip.
                $invalid = DB::selectOne(
                    "select 1 as invalid from {$tableName}"
                    ." where {$columnName} is not null and ("
                    ."octet_length({$columnName}) > ?"
                    ." or locate(0x00, {$columnName}) > 0"
                    ." or char_length(convert({$columnName} using utf8mb4)) > ?"
                    ." or hex({$columnName}) <> hex("
                    ."convert({$columnName} using utf8mb4))"
                    .') limit 1',
                    [self::BINARY_BYTES, $legacyCharacters],
                );

                if ($invalid !== null) {
                    throw new RuntimeException(
                        'Provider paths exceed the legacy UTF-8 schema or contain invalid bytes.',
                    );
                }
            }
        }
    }

    private function assertNoLegacyIdentityCollisions(string $driver): void
    {
        if ($driver === 'sqlite') {
            return;
        }

        foreach (self::LEGACY_UNIQUE_IDENTITIES as $identity) {
            if (! Schema::hasTable($identity['table'])
                || ! Schema::hasColumn($identity['table'], $identity['path'])) {
                continue;
            }

            $scope = array_map($this->quoteIdentifier(...), $identity['scope']);
            $path = $this->quoteIdentifier($identity['path']);
            $legacyPath = "convert({$path} using utf8mb4) collate ".self::LEGACY_COLLATION;
            $group = implode(', ', [...$scope, $legacyPath]);
            $table = $this->quoteIdentifier($identity['table']);
            $collision = DB::selectOne(
                "select 1 as collision from {$table} group by {$group} having count(*) > 1 limit 1",
            );
            if ($collision !== null) {
                throw new RuntimeException(
                    'Byte-distinct provider identities would collide under the legacy collation.',
                );
            }
        }
    }

    /** @param array<string, array{nullable:bool,legacy_chars:int,default_inbox:bool}> $columns */
    private function alterColumns(string $table, array $columns, bool $binary): void
    {
        $clauses = [];
        foreach ($columns as $column => $definition) {
            if (! Schema::hasColumn($table, $column)) {
                continue;
            }

            $type = $binary
                ? 'varbinary('.self::BINARY_BYTES.')'
                : 'varchar('.((int) $definition['legacy_chars']).')'
                    .' character set utf8mb4 collate '.self::LEGACY_COLLATION;
            $nullability = $definition['nullable'] ? 'null' : 'not null';
            $default = $definition['default_inbox']
                ? ($binary ? ' default 0x494E424F58' : " default 'INBOX'")
                : ($definition['nullable'] ? ' default null' : '');
            $clauses[] = 'modify column '.$this->quoteIdentifier($column)
                ." {$type} {$nullability}{$default}";
        }

        if ($clauses !== []) {
            DB::statement(
                'alter table '.$this->quoteIdentifier($table).' '.implode(', ', $clauses),
            );
        }
    }

    private function dropIndexes(string $table): void
    {
        foreach (self::INDEXES[$table] ?? [] as $name => $_definition) {
            if (Schema::hasIndex($table, $name)) {
                DB::statement(
                    'alter table '.$this->quoteIdentifier($table)
                    .' drop index '.$this->quoteIdentifier($name),
                );
            }
        }
    }

    private function createIndexes(string $table): void
    {
        foreach (self::INDEXES[$table] ?? [] as $name => $definition) {
            if (Schema::hasIndex($table, $name)) {
                continue;
            }

            $columns = implode(', ', array_map($this->quoteIdentifier(...), $definition['columns']));
            DB::statement(
                'alter table '.$this->quoteIdentifier($table)
                .' add '.($definition['unique'] ? 'unique ' : '')
                .'index '.$this->quoteIdentifier($name)." ({$columns})",
            );
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (preg_match('/^[a-z0-9_]+$/', $identifier) !== 1) {
            throw new RuntimeException('Unsafe provider-path schema identifier.');
        }

        return '`'.$identifier.'`';
    }
};
