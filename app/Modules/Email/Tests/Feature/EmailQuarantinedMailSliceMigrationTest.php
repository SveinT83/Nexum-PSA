<?php

namespace App\Modules\Email\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmailQuarantinedMailSliceMigrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function incomplete_draft_lock_and_acknowledgement_schemas_remain_inert(): void
    {
        $this->assertFalse(config('email_live.collaboration_enabled'));
        $this->assertFalse(config('email_live.conversation_acknowledgement_enabled'));
        $this->assertFalse(Schema::hasTable('email_mail_draft_locks'));
        $this->assertFalse(Schema::hasTable('email_mail_user_conversation_acknowledgements'));

        foreach ([
            '2026_08_19_140000_create_email_mail_draft_locks_table.php',
            '2026_08_19_150000_create_email_mail_user_conversation_acknowledgements_table.php',
        ] as $migrationFile) {
            $migration = require database_path('migrations/'.$migrationFile);
            $migration->up();
            $migration->down();
        }

        $this->assertFalse(Schema::hasTable('email_mail_draft_locks'));
        $this->assertFalse(Schema::hasTable('email_mail_user_conversation_acknowledgements'));
    }
}
