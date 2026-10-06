<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Actions\SetEmailUnreadForMe;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAccountUserGrant;
use App\Modules\Email\Models\EmailAccountUserReadBaseline;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Services\EmailUnreadAccessEpochService;
use App\Modules\Email\Services\EmailUnreadSchemaState;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EmailUnreadAccessSchemaRepairTest extends TestCase
{
    use RefreshDatabase;

    private int $nextUid = 97000;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('email.inbox_view', 'web');
    }

    #[Test]
    public function it_repairs_recorded_partial_schema_without_changing_personal_state(): void
    {
        $viewer = $this->viewer();
        $account = $this->account();
        $this->grant($account, $viewer);
        $message = $this->message($account, $this->folder($account));
        app(EmailUnreadAccessEpochService::class)->reconcileAfterMutation(
            $account, $viewer, false, EmailUnreadAccessEpochService::SOURCE_DIRECT_GRANT,
        );
        app(SetEmailUnreadForMe::class)->handle($viewer, $message, false);
        $before = DB::table('email_message_user_states')->orderBy('id')->get()->toJson();
        $this->degradeSchema();
        $this->assertSame(EmailUnreadSchemaState::MODE_UNAVAILABLE, (new EmailUnreadSchemaState)->mode());
        $this->assertTrue(DB::table('migrations')->where('migration',
            '2026_08_16_104000_add_email_unread_access_baselines')->exists());
        $this->repair()->up();
        $this->assertSame(EmailUnreadSchemaState::MODE_EPOCHS, (new EmailUnreadSchemaState)->mode());
        $this->assertSame($before, DB::table('email_message_user_states')->orderBy('id')->get()->toJson());
        $this->assertDatabaseHas('email_account_user_read_baselines', [
            'email_account_id' => $account->id, 'user_id' => $viewer->id,
            'access_epoch' => 1, 'baseline_message_id' => 0, 'ordinary_view_entitled' => true,
        ]);
        $baselines = DB::table('email_account_user_read_baselines')->get()->toJson();
        $this->repair()->up();
        $this->repair()->down();
        $this->assertSame($baselines, DB::table('email_account_user_read_baselines')->get()->toJson());
        $this->assertSame($before, DB::table('email_message_user_states')->orderBy('id')->get()->toJson());
        $this->assertFalse(Schema::hasIndex('email_message_user_states', 'email_message_user_states_unique'));
        // The original save failure occurs here when onboarding a new personal owner.
        $personal = $this->account($viewer);
        $this->app->forgetInstance(EmailUnreadSchemaState::class);
        $this->app->forgetInstance(EmailUnreadAccessEpochService::class);
        $baseline = app(EmailUnreadAccessEpochService::class)->reconcileAfterMutation(
            $personal, $viewer, false, EmailUnreadAccessEpochService::SOURCE_DIRECT_GRANT,
        );
        $this->assertNotNull($baseline);
        $this->assertTrue($baseline->ordinary_view_entitled);
    }

    #[Test]
    public function it_preserves_existing_advanced_baselines_and_rejects_lost_history(): void
    {
        $viewer = $this->viewer();
        $account = $this->account();
        $this->grant($account, $viewer);
        $message = $this->message($account, $this->folder($account));
        $baseline = app(EmailUnreadAccessEpochService::class)->reconcileAfterMutation(
            $account, $viewer, false, EmailUnreadAccessEpochService::SOURCE_DIRECT_GRANT,
        );
        $baseline->update(['access_epoch' => 2, 'baseline_message_id' => $message->id]);
        $before = DB::table('email_account_user_read_baselines')->get()->toJson();
        $this->repair()->up();
        $this->assertSame($before, DB::table('email_account_user_read_baselines')->get()->toJson());
        DB::table('email_message_user_states')->insert([
            'email_message_id' => $message->id, 'user_id' => $viewer->id,
            'access_epoch' => 2, 'is_unread' => false, 'opened_count' => 0,
        ]);
        $this->degradeSchema();
        try {
            $this->repair()->up();
            $this->fail('Lost advanced baselines require backup recovery.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('advanced access epochs exist', $error->getMessage());
        }
        $this->assertFalse(Schema::hasTable('email_account_user_read_baselines'));
        $this->assertTrue(Schema::hasIndex('email_message_user_states', 'email_message_user_states_unique'));
    }

    #[Test]
    public function it_backfills_personal_owners_but_blocks_direct_personal_grants(): void
    {
        $owner = $this->viewer();
        $other = $this->viewer();
        $account = $this->account($owner);
        $this->grant($account, $other);
        $this->degradeSchema();
        $this->repair()->up();
        $this->assertDatabaseHas('email_account_user_read_baselines', [
            'email_account_id' => $account->id, 'user_id' => $owner->id, 'ordinary_view_entitled' => true,
        ]);
        $this->assertDatabaseHas('email_account_user_read_baselines', [
            'email_account_id' => $account->id, 'user_id' => $other->id, 'ordinary_view_entitled' => false,
        ]);
    }

    private function degradeSchema(): void
    {
        Schema::drop('email_account_user_read_baselines');
        Schema::table('email_message_user_states', function (Blueprint $table): void {
            $table->dropUnique('em_msg_state_message_user_epoch_uq');
            $table->dropIndex('em_msg_state_user_epoch_unread_ix');
            $table->unique(['email_message_id', 'user_id'], 'email_message_user_states_unique');
        });
    }

    private function repair(): \Illuminate\Database\Migrations\Migration
    {
        return require database_path('migrations/2026_09_17_110000_repair_email_unread_access_schema.php');
    }

    private function viewer(): User
    {
        $viewer = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $viewer->givePermissionTo('email.inbox_view');

        return $viewer;
    }

    private function account(?User $owner = null): EmailAccount
    {
        $suffix = ++$this->nextUid;

        return EmailAccount::query()->create([
            'address' => "rolling-schema-{$suffix}@example.test",
            'description' => 'Unread rolling-schema test account',
            'from_name' => 'Unread Rolling Schema',
            'account_kind' => $owner ? EmailAccount::KIND_PERSONAL : EmailAccount::KIND_SHARED,
            'owner_id' => $owner?->id,
            'is_active' => true,
            'is_global_default' => false,
            'defaults_for' => [],
            'ticket_ingress_enabled' => false,
            'delete_policy' => 'local_only',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => "rolling-schema-{$suffix}@example.test",
            'imap_secret' => 'rolling-schema-test-secret',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => "rolling-schema-{$suffix}@example.test",
            'smtp_secret' => 'rolling-schema-test-secret',
            'smtp_auth_type' => 'password',
        ]);
    }

    private function grant(EmailAccount $account, User $viewer): EmailAccountUserGrant
    {
        return EmailAccountUserGrant::query()->create([
            'email_account_id' => $account->id,
            'user_id' => $viewer->id,
            'can_view' => true,
            'can_organize' => false,
            'can_send' => false,
            'granted_by' => $viewer->id,
            'granted_at' => now(),
        ]);
    }

    private function folder(EmailAccount $account): EmailFolder
    {
        return EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 971,
            'sync_status' => EmailFolder::SYNC_SYNCED,
        ]);
    }

    private function message(EmailAccount $account, EmailFolder $folder): EmailMessage
    {
        $uid = ++$this->nextUid;
        $message = EmailMessage::query()->create([
            'account_id' => $account->id,
            'mailbox' => $folder->path,
            'imap_uid_validity' => $folder->uid_validity,
            'imap_uid' => $uid,
            'message_id' => "<rolling-unread-{$uid}@example.test>",
            'subject' => 'Unread rolling-schema fixture',
            'received_at' => now(),
        ]);

        EmailMailboxPlacement::query()->create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'provider' => 'imap',
            'folder_path' => $folder->path,
            'imap_uid_validity' => $folder->uid_validity,
            'imap_uid' => $uid,
            'provider_seen' => false,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
        ]);

        return $message->fresh();
    }

    private function baselineExists(EmailAccount $account, User $viewer): bool
    {
        return EmailAccountUserReadBaseline::query()
            ->where('email_account_id', $account->id)
            ->where('user_id', $viewer->id)
            ->exists();
    }
}
