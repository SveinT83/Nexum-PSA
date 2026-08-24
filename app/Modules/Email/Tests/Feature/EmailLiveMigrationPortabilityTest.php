<?php

namespace App\Modules\Email\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmailLiveMigrationPortabilityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function sqlite_publication_triggers_use_portable_null_safe_owner_comparison(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('This regression contract inspects SQLite trigger SQL.');
        }

        $triggerSql = [];

        foreach (['insert', 'update'] as $operation) {
            $triggerName = "em_live_publication_contract_{$operation}";
            $sql = DB::table('sqlite_master')
                ->where('type', 'trigger')
                ->where('name', $triggerName)
                ->value('sql');

            $this->assertNotNull($sql, "Missing SQLite trigger {$triggerName}.");
            $sql = (string) $sql;
            $triggerSql[$operation] = $sql;

            $this->assertStringNotContainsString('<=>', $sql);
        }

        $this->assertStringContainsString(
            'account_authority.owner_user_id = NEW.frozen_owner_user_id',
            $triggerSql['insert'],
        );
        $this->assertStringContainsString(
            'account_authority.owner_user_id is null and NEW.frozen_owner_user_id is null',
            $triggerSql['insert'],
        );
    }
}
