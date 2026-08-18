<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Actions\ApplyEmailUnreadHandover;
use App\Modules\Email\Actions\PreviewEmailUnreadHandover;
use App\Modules\Email\Actions\ProjectHistoricalEmailReadBaseline;
use App\Modules\Email\Actions\RecordEmailMessageOpened;
use App\Modules\Email\Actions\SetEmailUnreadForMe;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAccountUserGrant;
use App\Modules\Email\Models\EmailAccountUserReadBaseline;
use App\Modules\Email\Models\EmailBreakGlassAccess;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxDelegation;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailMessageUserState;
use App\Modules\Email\Models\EmailUnreadHandoverRun;
use App\Modules\Email\Services\EmailUnreadAccessEpochService;
use App\Modules\Email\Services\EmailUnreadForMeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EmailUnreadBaselineHandoverTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $target;

    private User $otherViewer;

    private int $nextUid = 81000;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('email.inbox_view', 'web');
        Permission::findOrCreate('email.account_manage', 'web');

        $this->manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->manager->givePermissionTo('email.account_manage');
        $this->target = $this->viewer();
        $this->otherViewer = $this->viewer();
    }

    #[Test]
    public function migration_backfill_preserves_epoch_one_and_blocks_legacy_personal_direct_grants(): void
    {
        $shared = $this->account('backfill-shared@example.test');
        $personalOwner = $this->viewer();
        $personal = $this->account('backfill-personal@example.test', [
            'account_kind' => EmailAccount::KIND_PERSONAL,
            'owner_id' => $personalOwner->id,
        ]);
        $sharedMessage = $this->message($shared, null, now()->subDay());
        $this->grant($shared, $this->target);
        $this->grant($personal, $this->otherViewer);
        EmailMessageUserState::query()->create([
            'email_message_id' => $sharedMessage->id,
            'user_id' => $this->target->id,
            'is_unread' => false,
        ]);
        EmailAccountUserReadBaseline::query()->delete();

        $migration = require database_path(
            'migrations/2026_08_16_104000_add_email_unread_access_baselines.php',
        );
        $backfill = new ReflectionMethod($migration, 'backfillExistingOrdinaryViewEntitlements');
        $backfill->invoke($migration);

        $this->assertDatabaseHas('email_account_user_read_baselines', [
            'email_account_id' => $shared->id,
            'user_id' => $this->target->id,
            'access_epoch' => 1,
            'baseline_message_id' => 0,
            'ordinary_view_entitled' => true,
            'source' => 'legacy_migration',
        ]);
        $this->assertDatabaseHas('email_account_user_read_baselines', [
            'email_account_id' => $personal->id,
            'user_id' => $personalOwner->id,
            'ordinary_view_entitled' => true,
        ]);
        $this->assertDatabaseHas('email_account_user_read_baselines', [
            'email_account_id' => $personal->id,
            'user_id' => $this->otherViewer->id,
            'ordinary_view_entitled' => false,
            'source' => 'legacy_personal_direct_grant_blocked',
        ]);
        $this->assertSame(1, EmailMessageUserState::query()->sole()->access_epoch);

        $migrationSource = file_get_contents(database_path(
            'migrations/2026_08_16_104000_add_email_unread_access_baselines.php',
        ));
        $handoverMigrationSource = file_get_contents(database_path(
            'migrations/2026_08_16_105000_create_email_unread_handover_runs_and_items.php',
        ));
        $this->assertStringNotContainsString('->timestamp(', $migrationSource);
        $this->assertStringNotContainsString('->timestamp(', $handoverMigrationSource);
        $this->assertSame('datetime', Schema::getColumnType(
            'email_account_user_read_baselines',
            'recorded_at',
        ));
    }

    #[Test]
    public function php_and_sql_resolution_share_baseline_epoch_and_ignore_provider_seen(): void
    {
        $account = $this->account('resolver@example.test');
        $folder = $this->folder($account, 'INBOX');
        $old = $this->message($account, $folder, now()->subHour(), providerSeen: false);
        $baseline = $this->establishSharedGrant($account, $this->target);
        $new = $this->message($account, $folder, now(), providerSeen: true);
        $resolver = app(EmailUnreadForMeResolver::class);

        $this->assertSame($old->id, $baseline->baseline_message_id);
        $this->assertFalse($resolver->resolve($old, $this->target));
        $this->assertTrue($resolver->resolve($new, $this->target));

        $projected = $resolver->selectUnreadForMe(
            EmailMessage::query()->where('account_id', $account->id),
            $this->target,
        )->orderBy('id')->get();
        $this->assertSame([0, 1], $projected
            ->map(fn (EmailMessage $message): int => (int) $message->getAttribute('unread_for_me'))
            ->all());
        $this->assertSame(
            [$new->id],
            $resolver->scopeUnreadMessages(
                EmailMessage::query()->where('account_id', $account->id),
                $this->target,
            )->pluck('id')->all(),
        );

        app(SetEmailUnreadForMe::class)->handle($this->target, $new, false);
        $opened = app(RecordEmailMessageOpened::class)->handle(
            $this->target,
            $new,
            $new->placements()->first(),
        );

        $this->assertFalse($resolver->resolve($new, $this->target));
        $this->assertFalse($opened->is_unread, 'Opening must preserve the explicit personal state.');
        $this->assertSame(1, $opened->opened_count);
        $this->assertTrue($new->placements()->first()->provider_seen);
        $this->assertFalse($old->placements()->first()->provider_seen);
    }

    #[Test]
    public function edit_disable_overlap_and_regrant_keep_deterministic_epoch_boundaries(): void
    {
        $account = $this->account('epoch@example.test');
        $folder = $this->folder($account, 'INBOX');
        $old = $this->message($account, $folder, now()->subHour());
        $epochs = app(EmailUnreadAccessEpochService::class);
        $resolver = app(EmailUnreadForMeResolver::class);
        $grant = $this->grant($account, $this->target);
        $baseline = $epochs->reconcileAfterMutation(
            $account,
            $this->target,
            false,
            EmailUnreadAccessEpochService::SOURCE_DIRECT_GRANT,
            'grant:'.$grant->id,
            $this->manager,
        );
        $new = $this->message($account, $folder, now());
        app(SetEmailUnreadForMe::class)->handle($this->target, $new, false);

        $wasEntitled = $epochs->captureEntitlement($account, $this->target);
        $grant->forceFill(['can_organize' => true])->save();
        $edited = $epochs->reconcileAfterMutation(
            $account,
            $this->target,
            $wasEntitled,
            EmailUnreadAccessEpochService::SOURCE_DIRECT_GRANT,
            'grant:'.$grant->id,
            $this->manager,
        );
        $this->assertSame($baseline->access_epoch, $edited->access_epoch);
        $this->assertSame($baseline->baseline_message_id, $edited->baseline_message_id);

        EmailBreakGlassAccess::query()->create([
            'email_account_id' => $account->id,
            'actor_id' => $this->target->id,
            'can_view_content' => true,
            'reason' => 'Overlap must not move ordinary unread baseline.',
            'starts_at' => now()->subMinute(),
            'expires_at' => now()->addMinutes(30),
        ]);
        $this->assertSame($edited->access_epoch, $epochs->ensureCurrentEntitlement(
            $account,
            $this->target,
        )->access_epoch);

        $this->target->forceFill(['status' => User::STATUS_DISABLED])->save();
        $this->assertNull($resolver->resolve($new, $this->target->fresh()));
        $this->target->forceFill(['status' => User::STATUS_ACTIVE])->save();
        $this->target->refresh();
        $this->assertFalse($resolver->resolve($new, $this->target));
        $this->assertSame(1, $edited->access_epoch);

        $wasEntitled = $epochs->captureEntitlement($account, $this->target);
        $grant->delete();
        $revoked = $epochs->reconcileAfterMutation(
            $account,
            $this->target,
            $wasEntitled,
            EmailUnreadAccessEpochService::SOURCE_DIRECT_GRANT,
            null,
            $this->manager,
        );
        $this->assertFalse($revoked->ordinary_view_entitled);
        $duringGap = $this->message($account, $folder, now()->addMinute());

        $wasEntitled = $epochs->captureEntitlement($account, $this->target);
        $newGrant = $this->grant($account, $this->target);
        $regranted = $epochs->reconcileAfterMutation(
            $account,
            $this->target,
            $wasEntitled,
            EmailUnreadAccessEpochService::SOURCE_DIRECT_GRANT,
            'grant:'.$newGrant->id,
            $this->manager,
        );
        $this->assertSame(2, $regranted->access_epoch);
        $this->assertSame($duringGap->id, $regranted->baseline_message_id);
        $this->assertFalse($resolver->resolve($old, $this->target));
        $this->assertFalse($resolver->resolve($new, $this->target));
        $afterRegrant = $this->message($account, $folder, now()->addMinutes(2));
        $this->assertTrue($resolver->resolve($afterRegrant, $this->target));

        app(SetEmailUnreadForMe::class)->handle($this->target, $new, true);
        $this->assertSame(2, EmailMessageUserState::query()
            ->where('email_message_id', $new->id)
            ->where('user_id', $this->target->id)
            ->count());
    }

    #[Test]
    public function overlapping_delegations_preserve_epoch_but_an_unobserved_natural_gap_increments_it(): void
    {
        CarbonImmutable::setTestNow('2026-08-16 10:00:00 UTC');

        try {
            $owner = $this->viewer();
            $personal = $this->account('delegation-epochs@example.test', [
                'account_kind' => EmailAccount::KIND_PERSONAL,
                'owner_id' => $owner->id,
            ]);
            $folder = $this->folder($personal, 'INBOX');
            $old = $this->message($personal, $folder, now()->subHour());
            $epochs = app(EmailUnreadAccessEpochService::class);
            $first = EmailMailboxDelegation::query()->create([
                'email_account_id' => $personal->id,
                'owner_id' => $owner->id,
                'delegate_id' => $this->target->id,
                'can_view' => true,
                'reason' => 'First bounded ordinary delegation interval.',
                'starts_at' => now()->subHour(),
                'expires_at' => now()->addMinutes(10),
                'created_by' => $owner->id,
            ]);
            $baseline = $epochs->reconcileAfterMutation(
                $personal,
                $this->target,
                false,
                EmailUnreadAccessEpochService::SOURCE_DELEGATION,
                'delegation:'.$first->id,
                $owner,
            );
            $wasEntitled = $epochs->captureEntitlement($personal, $this->target);
            $overlap = EmailMailboxDelegation::query()->create([
                'email_account_id' => $personal->id,
                'owner_id' => $owner->id,
                'delegate_id' => $this->target->id,
                'can_view' => true,
                'reason' => 'Overlapping interval must bridge the first expiry.',
                'starts_at' => now()->addMinutes(5),
                'expires_at' => now()->addMinutes(20),
                'created_by' => $owner->id,
            ]);
            $afterOverlap = $epochs->reconcileAfterMutation(
                $personal,
                $this->target,
                $wasEntitled,
                EmailUnreadAccessEpochService::SOURCE_DELEGATION,
                'delegation:'.$overlap->id,
                $owner,
            );

            $this->assertSame(1, $baseline->access_epoch);
            $this->assertSame($old->id, $baseline->baseline_message_id);
            $this->assertSame($baseline->access_epoch, $afterOverlap->access_epoch);

            CarbonImmutable::setTestNow('2026-08-16 10:12:00 UTC');
            $this->assertSame(1, $epochs->ensureCurrentEntitlement(
                $personal,
                $this->target,
            )->access_epoch);
            $wasEntitled = $epochs->captureEntitlement($personal, $this->target);
            $later = EmailMailboxDelegation::query()->create([
                'email_account_id' => $personal->id,
                'owner_id' => $owner->id,
                'delegate_id' => $this->target->id,
                'can_view' => true,
                'reason' => 'Later interval begins after an unattended gap.',
                'starts_at' => CarbonImmutable::parse('2026-08-16 10:30:00 UTC'),
                'expires_at' => CarbonImmutable::parse('2026-08-16 11:00:00 UTC'),
                'created_by' => $owner->id,
            ]);
            $this->assertSame(1, $epochs->reconcileAfterMutation(
                $personal,
                $this->target,
                $wasEntitled,
                EmailUnreadAccessEpochService::SOURCE_DELEGATION,
                'delegation:'.$later->id,
                $owner,
            )->access_epoch);

            CarbonImmutable::setTestNow('2026-08-16 10:25:00 UTC');
            $duringGap = $this->message($personal, $folder, now());
            CarbonImmutable::setTestNow('2026-08-16 10:30:30 UTC');
            $afterScheduledStart = $this->message($personal, $folder, now());
            CarbonImmutable::setTestNow('2026-08-16 10:31:00 UTC');
            $newEpoch = $epochs->ensureCurrentEntitlement($personal, $this->target);

            $this->assertSame(2, $newEpoch->access_epoch);
            $this->assertSame($duringGap->id, $newEpoch->baseline_message_id);
            $this->assertTrue(app(EmailUnreadForMeResolver::class)->resolve(
                $afterScheduledStart,
                $this->target,
            ));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    #[Test]
    public function personal_owner_and_delegation_create_baselines_but_direct_grant_and_break_glass_do_not(): void
    {
        $owner = $this->viewer();
        $personal = $this->account('personal-unread@example.test', [
            'account_kind' => EmailAccount::KIND_PERSONAL,
            'owner_id' => $owner->id,
        ]);
        $folder = $this->folder($personal, 'INBOX');
        $old = $this->message($personal, $folder, now()->subHour());
        $epochs = app(EmailUnreadAccessEpochService::class);
        $ownerBaseline = $epochs->reconcileAfterMutation(
            $personal,
            $owner,
            false,
            EmailUnreadAccessEpochService::SOURCE_PERSONAL_OWNER,
            'owner:'.$owner->id,
            $owner,
        );
        $this->assertSame($old->id, $ownerBaseline->baseline_message_id);

        $delegation = EmailMailboxDelegation::query()->create([
            'email_account_id' => $personal->id,
            'owner_id' => $owner->id,
            'delegate_id' => $this->target->id,
            'can_view' => true,
            'reason' => 'Cover this mailbox during approved leave.',
            'starts_at' => now()->subMinute(),
            'expires_at' => now()->addDay(),
            'created_by' => $owner->id,
        ]);
        $delegateBaseline = $epochs->reconcileAfterMutation(
            $personal,
            $this->target,
            false,
            EmailUnreadAccessEpochService::SOURCE_DELEGATION,
            'delegation:'.$delegation->id,
            $owner,
        );
        $this->assertSame($old->id, $delegateBaseline->baseline_message_id);

        $rogueViewer = $this->viewer();
        $this->grant($personal, $rogueViewer);
        EmailAccountUserReadBaseline::query()->create([
            'email_account_id' => $personal->id,
            'user_id' => $rogueViewer->id,
            'access_epoch' => 1,
            'baseline_message_id' => 0,
            'ordinary_view_entitled' => true,
            'source' => 'legacy_personal_direct_grant_blocked',
            'recorded_at' => now(),
        ]);
        EmailBreakGlassAccess::query()->create([
            'email_account_id' => $personal->id,
            'actor_id' => $rogueViewer->id,
            'can_view_content' => true,
            'reason' => 'Emergency content access without personal unread authority.',
            'starts_at' => now()->subMinute(),
            'expires_at' => now()->addMinutes(30),
        ]);
        $resolver = app(EmailUnreadForMeResolver::class);

        $this->assertNull($resolver->resolve($old, $rogueViewer));
        $this->assertNull($resolver->selectUnreadForMe(
            EmailMessage::query()->whereKey($old->id),
            $rogueViewer,
        )->first()->getAttribute('unread_for_me'));

        try {
            app(SetEmailUnreadForMe::class)->handle($rogueViewer, $old, true);
            $this->fail('A break-glass/direct-grant-only personal viewer changed personal unread.');
        } catch (AuthorizationException) {
            $this->assertDatabaseMissing('email_message_user_states', [
                'email_message_id' => $old->id,
                'user_id' => $rogueViewer->id,
            ]);
        }
    }

    #[Test]
    public function historical_import_projection_is_insert_only_read_for_every_current_epoch_viewer(): void
    {
        $account = $this->account('historical-projection@example.test');
        $folder = $this->folder($account, 'INBOX');
        $this->establishSharedGrant($account, $this->target);
        $this->establishSharedGrant($account, $this->otherViewer);
        $history = $this->message($account, $folder, now()->subYear(), providerSeen: true);
        EmailMessageUserState::query()->create([
            'email_message_id' => $history->id,
            'user_id' => $this->target->id,
            'access_epoch' => 1,
            'is_unread' => true,
        ]);
        $this->otherViewer->forceFill(['status' => User::STATUS_DISABLED])->save();

        $inserted = app(ProjectHistoricalEmailReadBaseline::class)->handle($account, $history);
        $this->assertSame(1, $inserted);
        $this->assertDatabaseHas('email_message_user_states', [
            'email_message_id' => $history->id,
            'user_id' => $this->target->id,
            'access_epoch' => 1,
            'is_unread' => true,
        ]);
        $this->assertDatabaseHas('email_message_user_states', [
            'email_message_id' => $history->id,
            'user_id' => $this->otherViewer->id,
            'access_epoch' => 1,
            'is_unread' => false,
        ]);
        $this->assertSame(User::STATUS_DISABLED, $this->otherViewer->fresh()->status);
        $this->assertSame(0, app(ProjectHistoricalEmailReadBaseline::class)->handle($account, $history));
        $this->assertTrue($history->placements()->first()->provider_seen);
    }

    #[Test]
    public function bounded_handover_applies_exact_snapshot_to_one_user_and_is_idempotent(): void
    {
        $account = $this->account('handover@example.test');
        $inbox = $this->folder($account, 'INBOX');
        $archive = $this->folder($account, 'Archive');
        $first = $this->message($account, $inbox, now()->subDays(4), providerSeen: true);
        $second = $this->message($account, $archive, now()->subDays(3), providerSeen: false);
        $third = $this->message($account, $inbox, now()->subDays(2), providerSeen: true);
        $this->establishSharedGrant($account, $this->target);
        $implicitUnread = $this->message($account, $archive, now()->subDay(), providerSeen: false);
        $preview = app(PreviewEmailUnreadHandover::class);
        $dateFrom = now()->subDays(10)->startOfSecond();
        $dateTo = now()->addDay()->startOfSecond();
        $run = $preview->handle(
            $this->manager,
            $account,
            $this->target,
            [$archive->id, $inbox->id],
            $dateFrom,
            $dateTo,
            'Hand over the two newest messages from the agreed backlog.',
            2,
            'bounded-handover',
        );
        $sameRun = $preview->handle(
            $this->manager,
            $account,
            $this->target,
            [$inbox->id, $archive->id],
            $dateFrom,
            $dateTo,
            'Hand over the two newest messages from the agreed backlog.',
            2,
            'bounded-handover',
        );
        $later = $this->message($account, $inbox, now(), providerSeen: false);

        $this->assertSame($run->id, $sameRun->id);

        try {
            $preview->handle(
                $this->manager,
                $account,
                $this->target,
                [$archive->id, $inbox->id],
                $dateFrom,
                $dateTo,
                'A reused key may not silently select another handover scope.',
                2,
                'bounded-handover',
            );
            $this->fail('A reused idempotency key accepted a conflicting exact scope.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('another scope', $exception->getMessage());
        }

        $this->assertSame(2, $run->selected_count);
        $this->assertSame(
            [$implicitUnread->id, $third->id],
            $run->items->pluck('email_message_id')->all(),
        );
        $this->assertFalse($this->manager->can('email.inbox_view'));
        $this->assertNull(app(EmailUnreadForMeResolver::class)->resolve($third, $this->manager));
        foreach (['subject', 'body_text', 'snippet', 'from_email', 'filename'] as $forbiddenColumn) {
            $this->assertFalse(Schema::hasColumn('email_unread_handover_items', $forbiddenColumn));
        }

        $providerSeenBefore = EmailMailboxPlacement::query()
            ->whereIn('email_message_id', [
                $first->id,
                $second->id,
                $third->id,
                $implicitUnread->id,
                $later->id,
            ])
            ->pluck('provider_seen', 'email_message_id')
            ->map(fn (mixed $seen): bool => (bool) $seen)
            ->all();
        $applied = app(ApplyEmailUnreadHandover::class)->handle($this->manager, $run);
        $markedAt = EmailMessageUserState::query()
            ->where('email_message_id', $third->id)
            ->where('user_id', $this->target->id)
            ->value('marked_unread_at');
        $secondApply = app(ApplyEmailUnreadHandover::class)->handle($this->manager, $run);

        $this->assertSame(EmailUnreadHandoverRun::STATUS_APPLIED, $applied->status);
        $this->assertSame($applied->id, $secondApply->id);
        $this->assertSame(1, $applied->applied_count);
        $this->assertSame(1, $applied->already_unread_count);
        $this->assertDatabaseHas('email_message_user_states', [
            'email_message_id' => $third->id,
            'user_id' => $this->target->id,
            'access_epoch' => 1,
            'is_unread' => true,
        ]);
        $this->assertDatabaseHas('email_message_user_states', [
            'email_message_id' => $implicitUnread->id,
            'user_id' => $this->target->id,
            'access_epoch' => 1,
            'is_unread' => true,
        ]);
        $this->assertDatabaseMissing('email_message_user_states', [
            'email_message_id' => $later->id,
            'user_id' => $this->target->id,
        ]);
        $this->assertDatabaseMissing('email_message_user_states', [
            'email_message_id' => $third->id,
            'user_id' => $this->otherViewer->id,
        ]);
        $this->assertTrue($markedAt->equalTo(EmailMessageUserState::query()
            ->where('email_message_id', $third->id)
            ->where('user_id', $this->target->id)
            ->value('marked_unread_at')));
        $this->assertSame($providerSeenBefore, EmailMailboxPlacement::query()
            ->whereIn('email_message_id', [
                $first->id,
                $second->id,
                $third->id,
                $implicitUnread->id,
                $later->id,
            ])
            ->pluck('provider_seen', 'email_message_id')
            ->map(fn (mixed $seen): bool => (bool) $seen)
            ->all());

        $this->expectException(InvalidArgumentException::class);
        $preview->handle(
            $this->manager,
            $account,
            $this->target,
            [$inbox->id],
            $dateFrom,
            $dateTo,
            'This cap must fail.',
            501,
        );
    }

    #[Test]
    public function handover_rejects_disabled_folders_and_stales_missing_or_unsynced_placements(): void
    {
        $account = $this->account('invalid-handover-scope@example.test');
        $inbox = $this->folder($account, 'INBOX');
        $message = $this->message($account, $inbox, now()->subDay());
        $this->establishSharedGrant($account, $this->target);
        $preview = app(PreviewEmailUnreadHandover::class);

        foreach (['is_selectable', 'sync_enabled'] as $disabledAttribute) {
            $inbox->forceFill([$disabledAttribute => false])->save();

            try {
                $preview->handle(
                    $this->manager,
                    $account,
                    $this->target,
                    [$inbox->id],
                    now()->subWeek(),
                    now()->addDay(),
                    'A disabled folder cannot define a handover scope.',
                );
                $this->fail("Preview accepted a folder with {$disabledAttribute}=false.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('selected folder', $exception->getMessage());
            } finally {
                $inbox->forceFill([$disabledAttribute => true])->save();
            }
        }

        $missingRun = $preview->handle(
            $this->manager,
            $account,
            $this->target,
            [$inbox->id],
            now()->subWeek(),
            now()->addDay(),
            'A provider-missing placement invalidates the exact snapshot.',
        );
        $placement = $message->placements()->firstOrFail();
        $placement->forceFill(['provider_missing_at' => now()])->save();
        $missingResult = app(ApplyEmailUnreadHandover::class)->handle($this->manager, $missingRun);

        $this->assertSame(EmailUnreadHandoverRun::STATUS_STALE, $missingResult->status);
        $this->assertSame('snapshot_records_changed', $missingResult->error_code);
        $this->assertDatabaseMissing('email_message_user_states', [
            'email_message_id' => $message->id,
            'user_id' => $this->target->id,
        ]);

        $placement->forceFill(['provider_missing_at' => null])->save();
        $unsyncedRun = $preview->handle(
            $this->manager,
            $account,
            $this->target,
            [$inbox->id],
            now()->subWeek(),
            now()->addDay(),
            'An unsynced placement invalidates the exact snapshot.',
        );
        $placement->forceFill(['sync_status' => EmailMailboxPlacement::SYNC_ERROR])->save();
        $unsyncedResult = app(ApplyEmailUnreadHandover::class)->handle($this->manager, $unsyncedRun);

        $this->assertSame(EmailUnreadHandoverRun::STATUS_STALE, $unsyncedResult->status);
        $this->assertSame('snapshot_records_changed', $unsyncedResult->error_code);
        $this->assertDatabaseMissing('email_message_user_states', [
            'email_message_id' => $message->id,
            'user_id' => $this->target->id,
        ]);
    }

    #[Test]
    public function metadata_only_handover_surface_supports_shared_manager_and_personal_owner(): void
    {
        $account = $this->account('handover-surface@example.test');
        $inbox = $this->folder($account, 'INBOX');
        $message = $this->message($account, $inbox, now()->subDay(), providerSeen: true);
        $this->establishSharedGrant($account, $this->target);

        $this->actingAs($this->manager)
            ->get(route('tech.mail.unread-handover.index', $account))
            ->assertOk()
            ->assertSee($account->address)
            ->assertSee($this->target->name)
            ->assertSee($inbox->path)
            ->assertDontSee($message->subject);

        $reason = 'Make this exact support backlog visible to the assigned technician.';
        $this->actingAs($this->manager)
            ->post(route('tech.mail.unread-handover.preview', $account), [
                'target_user_id' => $this->target->id,
                'folder_ids' => [$inbox->id],
                'date_from' => now()->subWeek()->format('Y-m-d H:i:s'),
                'date_to' => now()->addDay()->format('Y-m-d H:i:s'),
                'maximum' => 100,
                'reason' => $reason,
                'idempotency_key' => 'surface-preview',
            ])
            ->assertRedirect(route('tech.mail.unread-handover.index', $account));
        $run = EmailUnreadHandoverRun::query()->sole();

        $this->actingAs($this->manager)
            ->get(route('tech.mail.unread-handover.index', $account))
            ->assertOk()
            ->assertSee($reason)
            ->assertSee('1 selected')
            ->assertDontSee($message->subject);
        $this->actingAs($this->manager)
            ->post(route('tech.mail.unread-handover.apply', [$account, $run]))
            ->assertRedirect(route('tech.mail.unread-handover.index', $account));

        $this->assertDatabaseHas('email_message_user_states', [
            'email_message_id' => $message->id,
            'user_id' => $this->target->id,
            'access_epoch' => 1,
            'is_unread' => true,
        ]);
        $this->assertTrue($message->placements()->first()->provider_seen);

        $handoverMigration = require database_path(
            'migrations/2026_08_16_105000_create_email_unread_handover_runs_and_items.php',
        );
        $handoverRollbackGuard = new ReflectionMethod(
            $handoverMigration,
            'assertNoDurableHandoverAuditWouldBeDeleted',
        );

        try {
            $handoverRollbackGuard->invoke($handoverMigration);
            $this->fail('Rollback guard accepted deletion of durable handover audit rows.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Cannot roll back Email unread handover audit', $exception->getMessage());
            $this->assertSame(1, EmailUnreadHandoverRun::query()->count());
        }

        $systemActor = $this->viewer();
        $systemActor->forceFill(['is_system_actor' => true])->save();
        $this->grant($account, $systemActor);
        $this->actingAs($this->manager)
            ->post(route('tech.mail.unread-handover.preview', $account), [
                'target_user_id' => $systemActor->id,
                'folder_ids' => [$inbox->id],
                'date_from' => now()->subWeek()->format('Y-m-d H:i:s'),
                'date_to' => now()->addDay()->format('Y-m-d H:i:s'),
                'maximum' => 100,
                'reason' => 'A system actor must never receive personal unread state.',
                'idempotency_key' => 'system-actor-target',
            ])
            ->assertForbidden();
        $this->assertSame(1, EmailUnreadHandoverRun::query()->count());

        $owner = $this->viewer();
        $personal = $this->account('owner-handover-surface@example.test', [
            'account_kind' => EmailAccount::KIND_PERSONAL,
            'owner_id' => $owner->id,
        ]);
        app(EmailUnreadAccessEpochService::class)->reconcileAfterMutation(
            $personal,
            $owner,
            false,
            EmailUnreadAccessEpochService::SOURCE_PERSONAL_OWNER,
            'owner:'.$owner->id,
            $owner,
        );

        $this->actingAs($owner)
            ->get(route('tech.mail.unread-handover.index', $personal))
            ->assertOk()
            ->assertSee($owner->name);
        $this->actingAs($this->target)
            ->get(route('tech.mail.unread-handover.index', $personal))
            ->assertNotFound();
    }

    #[Test]
    public function expired_preview_is_durably_transitioned_when_the_handover_surface_is_revisited(): void
    {
        CarbonImmutable::setTestNow('2026-08-16 12:00:00 UTC');

        try {
            $account = $this->account('expired-handover@example.test');
            $inbox = $this->folder($account, 'INBOX');
            $this->message($account, $inbox, now()->subDay());
            $this->establishSharedGrant($account, $this->target);
            $run = app(PreviewEmailUnreadHandover::class)->handle(
                $this->manager,
                $account,
                $this->target,
                [$inbox->id],
                now()->subWeek(),
                now()->addDay(),
                'This preview is intentionally allowed to expire.',
                100,
                'expires-on-surface',
            );
            CarbonImmutable::setTestNow($run->preview_expires_at->addSecond());

            $this->actingAs($this->manager)
                ->get(route('tech.mail.unread-handover.index', $account))
                ->assertOk()
                ->assertSee(EmailUnreadHandoverRun::STATUS_EXPIRED)
                ->assertSee('preview_expired');

            $this->assertDatabaseHas('email_unread_handover_runs', [
                'id' => $run->id,
                'status' => EmailUnreadHandoverRun::STATUS_EXPIRED,
                'error_code' => 'preview_expired',
            ]);
            $this->assertDatabaseHas('email_unread_handover_items', [
                'email_unread_handover_run_id' => $run->id,
                'status' => 'stale',
                'error_code' => 'preview_expired',
            ]);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    #[Test]
    public function apply_reloads_the_previewing_actor_before_reauthorizing(): void
    {
        $account = $this->account('actor-reauthorization@example.test');
        $inbox = $this->folder($account, 'INBOX');
        $message = $this->message($account, $inbox, now()->subHour());
        $this->establishSharedGrant($account, $this->target);
        $run = app(PreviewEmailUnreadHandover::class)->handle(
            $this->manager,
            $account,
            $this->target,
            [$inbox->id],
            now()->subDay(),
            now()->addDay(),
            'The previewing manager will be disabled before confirmation.',
            100,
            'actor-reauthorization',
        );
        $staleActor = User::query()->findOrFail($this->manager->id);
        User::query()->whereKey($this->manager->id)->update(['status' => User::STATUS_DISABLED]);

        try {
            app(ApplyEmailUnreadHandover::class)->handle($staleActor, $run);
            $this->fail('Apply trusted the previewing actor model after its authority was revoked.');
        } catch (AuthorizationException) {
            $this->assertDatabaseHas('email_unread_handover_runs', [
                'id' => $run->id,
                'status' => EmailUnreadHandoverRun::STATUS_PREVIEWED,
            ]);
            $this->assertDatabaseMissing('email_message_user_states', [
                'email_message_id' => $message->id,
                'user_id' => $this->target->id,
            ]);
        }
    }

    #[Test]
    public function changed_epoch_or_moved_placement_stales_snapshot_before_any_state_mutation(): void
    {
        $account = $this->account('stale-handover@example.test');
        $inbox = $this->folder($account, 'INBOX');
        $archive = $this->folder($account, 'Archive');
        $message = $this->message($account, $inbox, now()->subDay());
        $grant = $this->grant($account, $this->target);
        $epochs = app(EmailUnreadAccessEpochService::class);
        $epochs->reconcileAfterMutation(
            $account,
            $this->target,
            false,
            EmailUnreadAccessEpochService::SOURCE_DIRECT_GRANT,
            'grant:'.$grant->id,
            $this->manager,
        );
        $preview = app(PreviewEmailUnreadHandover::class);
        $run = $preview->handle(
            $this->manager,
            $account,
            $this->target,
            [$inbox->id],
            now()->subWeek(),
            now()->addDay(),
            'This snapshot will be invalidated by a moved placement.',
        );
        $message->placements()->first()->forceFill([
            'email_folder_id' => $archive->id,
            'folder_path' => $archive->path,
        ])->save();
        $staleMoved = app(ApplyEmailUnreadHandover::class)->handle($this->manager, $run);

        $this->assertSame(EmailUnreadHandoverRun::STATUS_STALE, $staleMoved->status);
        $this->assertSame('snapshot_records_changed', $staleMoved->error_code);
        $this->assertDatabaseMissing('email_message_user_states', [
            'email_message_id' => $message->id,
            'user_id' => $this->target->id,
        ]);

        $message->placements()->first()->forceFill([
            'email_folder_id' => $inbox->id,
            'folder_path' => $inbox->path,
        ])->save();
        $epochRun = $preview->handle(
            $this->manager,
            $account,
            $this->target,
            [$inbox->id],
            now()->subWeek(),
            now()->addDay(),
            'This snapshot will be invalidated by a new access epoch.',
        );
        $wasEntitled = $epochs->captureEntitlement($account, $this->target);
        $grant->delete();
        $epochs->reconcileAfterMutation(
            $account,
            $this->target,
            $wasEntitled,
            EmailUnreadAccessEpochService::SOURCE_DIRECT_GRANT,
            null,
            $this->manager,
        );
        $newGrant = $this->grant($account, $this->target);
        $epochs->reconcileAfterMutation(
            $account,
            $this->target,
            false,
            EmailUnreadAccessEpochService::SOURCE_DIRECT_GRANT,
            'grant:'.$newGrant->id,
            $this->manager,
        );
        $staleEpoch = app(ApplyEmailUnreadHandover::class)->handle($this->manager, $epochRun);

        $this->assertSame(EmailUnreadHandoverRun::STATUS_STALE, $staleEpoch->status);
        $this->assertSame('authorization_or_epoch_changed', $staleEpoch->error_code);
        $this->assertDatabaseMissing('email_message_user_states', [
            'email_message_id' => $message->id,
            'user_id' => $this->target->id,
            'access_epoch' => 2,
        ]);
    }

    #[Test]
    public function rollback_guard_fails_closed_instead_of_deleting_older_epoch_state(): void
    {
        $account = $this->account('rollback-guard@example.test');
        $message = $this->message($account, null, now());
        EmailMessageUserState::query()->create([
            'email_message_id' => $message->id,
            'user_id' => $this->target->id,
            'access_epoch' => 2,
            'is_unread' => true,
        ]);
        $migration = require database_path(
            'migrations/2026_08_16_104000_add_email_unread_access_baselines.php',
        );
        $guard = new ReflectionMethod($migration, 'assertLegacyUniqueKeyCanBeRestoredWithoutDataLoss');

        try {
            $guard->invoke($migration);
            $this->fail('Rollback guard accepted state that the legacy unique key cannot represent.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Cannot roll back Email unread epochs', $exception->getMessage());
            $this->assertSame(1, EmailMessageUserState::query()->count());
        }

        EmailMessageUserState::query()->delete();
        EmailAccountUserReadBaseline::query()->create([
            'email_account_id' => $account->id,
            'user_id' => $this->target->id,
            'access_epoch' => 1,
            'baseline_message_id' => $message->id,
            'ordinary_view_entitled' => true,
            'source' => EmailUnreadAccessEpochService::SOURCE_DIRECT_GRANT,
            'recorded_at' => now(),
            'entitlement_changed_at' => now(),
        ]);

        try {
            $guard->invoke($migration);
            $this->fail('Rollback guard accepted a non-zero grant baseline.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Cannot roll back Email unread epochs', $exception->getMessage());
            $this->assertSame($message->id, EmailAccountUserReadBaseline::query()->sole()->baseline_message_id);
        }
    }

    private function viewer(): User
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->givePermissionTo('email.inbox_view');

        return $user;
    }

    /** @param  array<string, mixed>  $overrides */
    private function account(string $address, array $overrides = []): EmailAccount
    {
        return EmailAccount::query()->create(array_merge([
            'address' => $address,
            'description' => 'Unread baseline test account',
            'from_name' => 'Unread Baseline Test',
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
            'imap_username' => $address,
            'imap_secret' => 'unread-baseline-test-secret',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => $address,
            'smtp_secret' => 'unread-baseline-test-secret',
            'smtp_auth_type' => 'password',
        ], $overrides));
    }

    private function folder(EmailAccount $account, string $path): EmailFolder
    {
        return EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => $path,
            'name' => $path,
            'role' => $path === 'INBOX' ? EmailFolder::ROLE_INBOX : EmailFolder::ROLE_ARCHIVE,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 991,
            'sync_status' => EmailFolder::SYNC_SYNCED,
        ]);
    }

    private function grant(EmailAccount $account, User $user): EmailAccountUserGrant
    {
        return EmailAccountUserGrant::query()->create([
            'email_account_id' => $account->id,
            'user_id' => $user->id,
            'can_view' => true,
            'can_organize' => false,
            'can_send' => false,
            'granted_by' => $this->manager->id,
            'granted_at' => now(),
        ]);
    }

    private function establishSharedGrant(
        EmailAccount $account,
        User $user,
    ): EmailAccountUserReadBaseline {
        $epochs = app(EmailUnreadAccessEpochService::class);
        $wasEntitled = $epochs->captureEntitlement($account, $user);
        $grant = $this->grant($account, $user);

        return $epochs->reconcileAfterMutation(
            $account,
            $user,
            $wasEntitled,
            EmailUnreadAccessEpochService::SOURCE_DIRECT_GRANT,
            'grant:'.$grant->id,
            $this->manager,
        );
    }

    private function message(
        EmailAccount $account,
        ?EmailFolder $folder,
        mixed $receivedAt,
        bool $providerSeen = false,
    ): EmailMessage {
        $uid = ++$this->nextUid;
        $message = EmailMessage::query()->create([
            'account_id' => $account->id,
            'mailbox' => $folder?->path ?? 'INBOX',
            'imap_uid_validity' => $folder?->uid_validity ?? 0,
            'imap_uid' => $uid,
            'message_id' => "<unread-{$uid}@example.test>",
            'subject' => 'Unread baseline fixture '.$uid,
            'received_at' => $receivedAt,
        ]);

        if ($folder) {
            EmailMailboxPlacement::query()->create([
                'email_message_id' => $message->id,
                'account_id' => $account->id,
                'email_folder_id' => $folder->id,
                'provider' => 'imap',
                'folder_path' => $folder->path,
                'imap_uid_validity' => $folder->uid_validity,
                'imap_uid' => $uid,
                'provider_seen' => $providerSeen,
                'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
                'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            ]);
        }

        return $message->fresh();
    }
}
