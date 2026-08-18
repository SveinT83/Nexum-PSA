<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Actions\RecordEmailMessageOpened;
use App\Modules\Email\Actions\SetEmailUnreadForMe;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAccountUserGrant;
use App\Modules\Email\Models\EmailAccountUserReadBaseline;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailMessageUserState;
use App\Modules\Email\Services\EmailUnreadAccessEpochService;
use App\Modules\Email\Services\EmailUnreadForMeResolver;
use App\Modules\Email\Services\EmailUnreadSchemaState;
use App\Modules\Email\Services\MailboxAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EmailUnreadRollingSchemaCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private int $nextUid = 97000;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('email.inbox_view', 'web');
    }

    #[Test]
    public function legacy_schema_preserves_php_sql_and_personal_writer_semantics(): void
    {
        $this->forceSchemaMode(EmailUnreadSchemaState::MODE_LEGACY);
        $viewer = $this->viewer();
        $account = $this->account();
        $this->grant($account, $viewer);
        $folder = $this->folder($account);
        $message = $this->message($account, $folder);
        $resolver = app(EmailUnreadForMeResolver::class);

        $this->assertTrue($resolver->resolve($message, $viewer));
        [$sql] = $resolver->sqlExpression(
            $viewer,
            'email_messages.id',
            'email_messages.account_id',
        );
        $this->assertStringNotContainsString('email_account_user_read_baselines', $sql);
        $this->assertStringNotContainsString('access_epoch', $sql);

        $projected = $resolver->selectUnreadForMe(
            EmailMessage::query()->whereKey($message->id),
            $viewer,
        )->sole();
        $this->assertSame(1, (int) $projected->getAttribute('unread_for_me'));
        $this->assertSame(
            [$message->id],
            $resolver->scopeUnreadMessages(
                EmailMessage::query()->whereKey($message->id),
                $viewer,
            )->pluck('id')->all(),
        );

        $this->assertTrue(app(MailboxAccess::class)->canAccessAccount(
            $viewer,
            $account,
            MailboxAccess::VIEW,
        ));
        $this->assertFalse($this->baselineExists($account, $viewer));

        $opened = app(RecordEmailMessageOpened::class)->handle(
            $viewer,
            $message,
            $message->placements()->sole(),
        );
        $this->assertTrue($opened->is_unread);
        $this->assertSame(1, $opened->opened_count);

        $read = app(SetEmailUnreadForMe::class)->handle($viewer, $message, false);
        $this->assertFalse($read->is_unread);
        $reopened = app(RecordEmailMessageOpened::class)->handle(
            $viewer,
            $message,
            $message->placements()->sole(),
        );
        $this->assertFalse($reopened->is_unread);
        $this->assertSame(2, $reopened->opened_count);
        $this->assertFalse($resolver->resolve($message, $viewer));
        $this->assertFalse($this->baselineExists($account, $viewer));
        $this->assertNull(app(EmailUnreadAccessEpochService::class)->reconcileAfterMutation(
            $account,
            $viewer,
            false,
            EmailUnreadAccessEpochService::SOURCE_DIRECT_GRANT,
        ));
    }

    #[Test]
    public function partial_schema_fails_closed_before_personal_state_writes(): void
    {
        $this->forceSchemaMode(EmailUnreadSchemaState::MODE_UNAVAILABLE);
        $viewer = $this->viewer();
        $account = $this->account();
        $this->grant($account, $viewer);
        $folder = $this->folder($account);
        $message = $this->message($account, $folder);
        $resolver = app(EmailUnreadForMeResolver::class);

        $this->assertNull($resolver->resolve($message, $viewer));
        $this->assertSame(
            ['NULL', []],
            $resolver->sqlExpression(
                $viewer,
                'email_messages.id',
                'email_messages.account_id',
            ),
        );
        $this->assertSame([], $resolver->scopeUnreadMessages(
            EmailMessage::query()->whereKey($message->id),
            $viewer,
        )->pluck('id')->all());

        foreach ([
            fn () => app(SetEmailUnreadForMe::class)->handle($viewer, $message, false),
            fn () => app(RecordEmailMessageOpened::class)->handle(
                $viewer,
                $message,
                $message->placements()->sole(),
            ),
        ] as $write) {
            try {
                $write();
                $this->fail('An incomplete unread schema accepted a personal-state write.');
            } catch (AuthorizationException $exception) {
                $this->assertSame('Personal unread is temporarily unavailable.', $exception->getMessage());
            }
        }

        try {
            app(EmailUnreadAccessEpochService::class)->reconcileAfterMutation(
                $account,
                $viewer,
                false,
                EmailUnreadAccessEpochService::SOURCE_DIRECT_GRANT,
            );
            $this->fail('An incomplete unread schema accepted an epoch transition.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'The Email unread schema transition is incomplete.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(0, EmailMessageUserState::query()->count());
        $this->assertFalse($this->baselineExists($account, $viewer));
    }

    #[Test]
    public function legacy_writers_reauthorize_the_locked_current_actor(): void
    {
        $this->forceSchemaMode(EmailUnreadSchemaState::MODE_LEGACY);
        $account = $this->account();
        $folder = $this->folder($account);
        $disabled = $this->viewer();
        $systemActor = $this->viewer();
        $this->grant($account, $disabled);
        $this->grant($account, $systemActor);
        $disabledMessage = $this->message($account, $folder);
        $systemMessage = $this->message($account, $folder);

        DB::table((new User)->getTable())
            ->where('id', $disabled->id)
            ->update(['status' => User::STATUS_DISABLED]);
        DB::table((new User)->getTable())
            ->where('id', $systemActor->id)
            ->update(['is_system_actor' => true]);

        foreach ([
            fn () => app(SetEmailUnreadForMe::class)->handle($disabled, $disabledMessage, false),
            fn () => app(RecordEmailMessageOpened::class)->handle(
                $systemActor,
                $systemMessage,
                $systemMessage->placements()->sole(),
            ),
        ] as $write) {
            try {
                $write();
                $this->fail('A stale actor snapshot wrote personal unread state.');
            } catch (AuthorizationException) {
                // Expected: the account -> current user lock wins over the stale model.
            }
        }

        $this->assertSame(0, EmailMessageUserState::query()->count());
    }

    #[Test]
    public function completed_test_schema_resolves_the_epoch_contract(): void
    {
        $schemaState = app(EmailUnreadSchemaState::class);

        $this->assertSame($schemaState, app(EmailUnreadSchemaState::class));
        $this->assertSame(
            EmailUnreadSchemaState::MODE_EPOCHS,
            $schemaState->mode(),
        );
    }

    private function forceSchemaMode(string $mode): void
    {
        $this->app->instance(
            EmailUnreadSchemaState::class,
            new class($mode) extends EmailUnreadSchemaState
            {
                public function __construct(private readonly string $forcedMode) {}

                public function mode(): string
                {
                    return $this->forcedMode;
                }
            },
        );
    }

    private function viewer(): User
    {
        $viewer = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $viewer->givePermissionTo('email.inbox_view');

        return $viewer;
    }

    private function account(): EmailAccount
    {
        $suffix = ++$this->nextUid;

        return EmailAccount::query()->create([
            'address' => "rolling-schema-{$suffix}@example.test",
            'description' => 'Unread rolling-schema test account',
            'from_name' => 'Unread Rolling Schema',
            'account_kind' => EmailAccount::KIND_SHARED,
            'owner_id' => null,
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
