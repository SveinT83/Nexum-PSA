<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Actions\InspectEmailCanonicalCorrelationCandidate;
use App\Modules\Email\Actions\ReviewEmailCanonicalCorrelationCandidate;
use App\Modules\Email\Actions\StartEmailCanonicalCorrelationRun;
use App\Modules\Email\Jobs\ProcessEmailCanonicalCorrelationRun;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAttachment;
use App\Modules\Email\Models\EmailCanonicalCorrelationCandidate;
use App\Modules\Email\Models\EmailCanonicalCorrelationRun;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Services\EmailCanonicalCorrelationEvidence;
use App\Modules\Email\Services\EmailLiveAuthorityCoordinator;
use App\Modules\Email\Services\EmailCanonicalCorrelationRunner;
use App\Modules\Email\Services\EmailCanonicalCorrelationScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EmailCanonicalCorrelationShadowTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private int $nextUid = 1000;


    private function transferAccountOwnership(EmailAccount $account, User $newOwner): EmailAccount
    {
        return DB::transaction(function () use ($account, $newOwner): EmailAccount {
            $locked = EmailAccount::query()->lockForUpdate()->findOrFail($account->id);
            app(EmailLiveAuthorityCoordinator::class)->prepareAccountMutation(
                account: $locked,
                affectedUserIds: [(int) $locked->owner_id, (int) $newOwner->id],
                nextOwnerId: (int) $newOwner->id,
                ownerChanged: true,
            );
            $locked->forceFill(['owner_id' => $newOwner->id])->save();

            return $locked->refresh();
        }, 3);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        foreach (['email.inbox_view', 'email.mailbox_sync_manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->operator = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->operator->givePermissionTo(['email.inbox_view', 'email.mailbox_sync_manage']);
    }

    #[Test]
    public function evidence_is_conservative_versioned_and_order_independent_without_persisting_content(): void
    {
        $account = $this->account($this->operator, 'correlation@example.test');
        $left = $this->message($account, '<same@example.test>', 'private left subject');
        $right = $this->message($account, 'same@example.test', 'different ignored subject');
        $this->attachment($left, 'one.pdf', 'one');
        $this->attachment($left, 'two.pdf', 'two');
        $this->attachment($right, 'two.pdf', 'two');
        $this->attachment($right, 'one.pdf', 'one');
        $left->update(['attachments_count' => 2]);
        $right->update(['attachments_count' => 2]);

        $evidence = app(EmailCanonicalCorrelationEvidence::class);
        $comparison = $evidence->compare(
            $evidence->forMessage($left->fresh()),
            $evidence->forMessage($right->fresh()),
        );

        $this->assertSame(EmailCanonicalCorrelationEvidence::ALGORITHM_VERSION, 'v1');
        $this->assertSame(EmailCanonicalCorrelationCandidate::CLASS_STRONG, $comparison['candidate_class']);
        $this->assertContains('attachment_hash_match', $comparison['reason_codes']);
        $this->assertSame(64, strlen($comparison['pair_fingerprint']));
        $this->assertStringNotContainsString('private', json_encode($comparison, JSON_THROW_ON_ERROR));

        $right->forceFill(['headers_json' => ['date' => ['Sun, 16 Aug 2026 10:00:00 +0000']]])->save();
        $missing = $evidence->compare(
            $evidence->forMessage($left->fresh()),
            $evidence->forMessage($right->fresh()),
        );
        $this->assertSame(EmailCanonicalCorrelationCandidate::CLASS_AMBIGUOUS, $missing['candidate_class']);
        $this->assertContains('recipients_incomplete', $missing['reason_codes']);

        $right->forceFill([
            'headers_json' => ['bcc' => [], 'date' => ['Sun, 16 Aug 2026 10:00:00 +0000']],
            'checksum_sha1' => sha1('different-content'),
        ])->save();
        $different = $evidence->compare(
            $evidence->forMessage($left->fresh()),
            $evidence->forMessage($right->fresh()),
        );
        $this->assertSame(EmailCanonicalCorrelationCandidate::CLASS_DIFFERENT, $different['candidate_class']);
        $this->assertContains('content_hash_conflict', $different['reason_codes']);
    }

    #[Test]
    public function malformed_or_missing_message_id_never_becomes_strong_but_exact_other_evidence_can_be_possible(): void
    {
        $account = $this->account($this->operator, 'missing-id@example.test');
        $left = $this->message($account, 'not-a-valid-message-id', 'one');
        $right = $this->message($account, null, 'two');
        $evidence = app(EmailCanonicalCorrelationEvidence::class);

        $comparison = $evidence->compare(
            $evidence->forMessage($left),
            $evidence->forMessage($right),
        );

        $this->assertSame(EmailCanonicalCorrelationCandidate::CLASS_POSSIBLE, $comparison['candidate_class']);
        $this->assertContains('message_id_missing_or_malformed', $comparison['reason_codes']);
    }

    #[Test]
    public function bcc_and_raw_source_evidence_never_silently_collapses_distinct_delivery_variants(): void
    {
        $account = $this->account($this->operator, 'evidence-boundary@example.test');
        $left = $this->message($account, '<evidence@example.test>', 'left');
        $right = $this->message($account, '<evidence@example.test>', 'right');
        $left->update(['headers_json' => ['bcc' => ['Visible <visible@example.test>, hidden-a@example.test']]]);
        $right->update(['headers_json' => ['bcc' => ['Visible <visible@example.test>, hidden-b@example.test']]]);
        $evidence = app(EmailCanonicalCorrelationEvidence::class);

        $bccConflict = $evidence->compare(
            $evidence->forMessage($left->fresh()),
            $evidence->forMessage($right->fresh()),
        );
        $this->assertSame(EmailCanonicalCorrelationCandidate::CLASS_DIFFERENT, $bccConflict['candidate_class']);
        $this->assertContains('recipients_conflict', $bccConflict['reason_codes']);

        $right->update(['headers_json' => ['bcc' => ['hidden recipient without an address']]]);
        $bccIncomplete = $evidence->compare(
            $evidence->forMessage($left->fresh()),
            $evidence->forMessage($right->fresh()),
        );
        $this->assertSame(EmailCanonicalCorrelationCandidate::CLASS_AMBIGUOUS, $bccIncomplete['candidate_class']);
        $this->assertContains('recipients_incomplete', $bccIncomplete['reason_codes']);

        $right->update(['headers_json' => $left->headers_json]);
        Storage::disk('local')->put($right->raw_path, "Message-ID: <evidence@example.test>\r\n\r\ndifferent raw bytes");
        $rawConflict = $evidence->compare(
            $evidence->forMessage($left->fresh()),
            $evidence->forMessage($right->fresh()),
        );
        $this->assertSame(EmailCanonicalCorrelationCandidate::CLASS_DIFFERENT, $rawConflict['candidate_class']);
        $this->assertContains('raw_source_hash_conflict', $rawConflict['reason_codes']);
    }

    #[Test]
    public function normalized_message_id_discovery_works_without_a_stored_checksum(): void
    {
        Queue::fake();
        $account = $this->account($this->operator, 'normalized-discovery@example.test');
        $left = $this->message($account, '<Normalized@Example.Test>', 'left');
        $right = $this->message($account, 'normalized@example.test', 'right');
        $left->update(['checksum_sha1' => null]);
        $right->update(['checksum_sha1' => null]);

        $run = app(StartEmailCanonicalCorrelationRun::class)->handle($this->operator, [$account->id], [
            'message_cap' => 10,
            'group_cap' => 1,
            'pair_cap' => 1,
        ]);
        $this->finish($run);

        $this->assertSame(EmailCanonicalCorrelationRun::STATUS_COMPLETED, $run->fresh()->status);
        $candidate = $run->fresh()->candidates()->sole();
        $this->assertSame([$left->id, $right->id], [
            $candidate->left_email_message_id,
            $candidate->right_email_message_id,
        ]);
    }

    #[Test]
    public function bounded_run_is_frozen_idempotent_resumable_and_does_not_mutate_authoritative_mail(): void
    {
        Queue::fake();
        $leftAccount = $this->account($this->operator, 'left@example.test');
        $rightAccount = $this->account($this->operator, 'right@example.test');
        $left = $this->message($leftAccount, '<cross-account@example.test>', 'secret alpha');
        $right = $this->message($rightAccount, 'cross-account@example.test', 'secret beta');
        $before = EmailMessage::query()->orderBy('id')->get()->map->getAttributes()->all();

        $action = app(StartEmailCanonicalCorrelationRun::class);
        $run = $action->handle($this->operator, [$rightAccount->id, $leftAccount->id], [
            'message_cap' => 10,
            'group_cap' => 10,
            'pair_cap' => 10,
            'per_group_cap' => 5,
        ]);
        $same = $action->handle($this->operator, [$leftAccount->id, $rightAccount->id], [
            'message_cap' => 10,
            'group_cap' => 10,
            'pair_cap' => 10,
            'per_group_cap' => 5,
        ]);
        $this->assertTrue($run->is($same));
        Queue::assertPushed(ProcessEmailCanonicalCorrelationRun::class);

        $late = $this->message($leftAccount, '<cross-account@example.test>', 'late after snapshot');
        $this->assertGreaterThan($run->frozen_max_message_id, $late->id);
        $this->finish($run);

        $run->refresh();
        $candidate = $run->candidates()->sole();
        $this->assertSame(EmailCanonicalCorrelationRun::STATUS_COMPLETED, $run->status);
        $this->assertSame([$left->id, $right->id], [
            $candidate->left_email_message_id,
            $candidate->right_email_message_id,
        ]);
        $this->assertSame(EmailCanonicalCorrelationCandidate::CLASS_STRONG, $candidate->candidate_class);
        $this->assertSame(1, $run->candidate_count);
        $this->assertSame(1, $run->strong_count);
        $this->assertStringNotContainsString('secret', $candidate->toJson());
        $this->assertSame($before, EmailMessage::query()->whereKeyNot($late->id)->orderBy('id')->get()->map->getAttributes()->all());
        $this->assertDatabaseCount('email_mailbox_placements', 0);
        $this->assertDatabaseCount('email_remote_operations', 0);
        $this->assertDatabaseCount('email_rule_execution_attempts', 0);
        $this->assertDatabaseCount('email_account_user_read_baselines', 0);
        $this->assertDatabaseCount('email_message_user_states', 0);
    }

    #[Test]
    public function exact_group_and_pair_caps_complete_while_one_more_group_fails_closed(): void
    {
        Queue::fake();
        $exact = $this->account($this->operator, 'exact-cap@example.test');
        foreach ([1, 1, 2, 2] as $group) {
            $message = $this->message($exact, "<exact-{$group}@example.test>", 'exact');
            $message->update(['checksum_sha1' => sha1('unique-'.$message->id)]);
        }
        $exactRun = app(StartEmailCanonicalCorrelationRun::class)->handle($this->operator, [$exact->id], [
            'message_cap' => 10,
            'group_cap' => 2,
            'pair_cap' => 2,
        ]);
        $this->finish($exactRun);
        $this->assertSame(EmailCanonicalCorrelationRun::STATUS_COMPLETED, $exactRun->fresh()->status);
        $this->assertSame(2, $exactRun->fresh()->groups_processed);
        $this->assertSame(2, $exactRun->fresh()->pairs_processed);

        $overflow = $this->account($this->operator, 'overflow-cap@example.test');
        foreach ([1, 1, 2, 2, 3, 3] as $group) {
            $message = $this->message($overflow, "<overflow-{$group}@example.test>", 'overflow');
            $message->update(['checksum_sha1' => sha1('unique-'.$message->id)]);
        }
        $overflowRun = app(StartEmailCanonicalCorrelationRun::class)->handle($this->operator, [$overflow->id], [
            'message_cap' => 10,
            'group_cap' => 2,
            'pair_cap' => 10,
        ]);
        $this->finish($overflowRun);
        $this->assertSame(EmailCanonicalCorrelationRun::STATUS_FAILED, $overflowRun->fresh()->status);
        $this->assertSame('group_cap_reached', $overflowRun->fresh()->error_code);
    }

    #[Test]
    public function an_exact_message_id_window_can_narrow_a_large_account_without_mutating_mail(): void
    {
        Queue::fake();
        $account = $this->account($this->operator, 'window@example.test');
        $outside = $this->message($account, '<outside@example.test>', 'outside');
        $left = $this->message($account, '<inside@example.test>', 'inside-left');
        $right = $this->message($account, '<inside@example.test>', 'inside-right');

        $run = app(StartEmailCanonicalCorrelationRun::class)->handle($this->operator, [$account->id], [
            'min_message_id' => $left->id,
            'max_message_id' => $right->id,
            'message_cap' => 2,
        ]);
        $this->finish($run);

        $this->assertSame($left->id, $run->fresh()->frozen_min_message_id);
        $this->assertSame($right->id, $run->fresh()->frozen_max_message_id);
        $this->assertSame(2, $run->fresh()->scoped_message_count);
        $this->assertFalse($run->fresh()->candidates->contains(
            fn (EmailCanonicalCorrelationCandidate $candidate): bool => in_array($outside->id, [
                $candidate->left_email_message_id,
                $candidate->right_email_message_id,
            ], true),
        ));
    }

    #[Test]
    public function oversized_groups_are_recorded_once_without_cartesian_comparison(): void
    {
        Queue::fake();
        $account = $this->account($this->operator, 'oversized@example.test');
        foreach (range(1, 5) as $index) {
            $this->message($account, '<reused@example.test>', 'subject-'.$index);
        }

        $run = app(StartEmailCanonicalCorrelationRun::class)->handle($this->operator, [$account->id], [
            'message_cap' => 10,
            'group_cap' => 10,
            'pair_cap' => 10,
            'per_group_cap' => 2,
        ]);
        $this->finish($run);

        $candidate = $run->fresh()->candidates()->sole();
        $this->assertSame(EmailCanonicalCorrelationCandidate::CLASS_OVERSIZED, $candidate->candidate_class);
        $this->assertSame(5, $candidate->group_size);
        $this->assertSame(['group_exceeds_per_group_cap'], $candidate->reason_codes_json);
        $this->assertSame(0, $run->fresh()->pairs_processed);
    }

    #[Test]
    public function overlapping_precise_and_oversized_discovery_is_deterministically_fail_safe(): void
    {
        Queue::fake();
        $account = $this->account($this->operator, 'overlap@example.test');
        $left = $this->message($account, '<precise@example.test>', 'left');
        $right = $this->message($account, '<precise@example.test>', 'right');
        $this->message($account, '<different@example.test>', 'third');

        $run = app(StartEmailCanonicalCorrelationRun::class)->handle($this->operator, [$account->id], [
            'message_cap' => 10,
            'group_cap' => 10,
            'pair_cap' => 10,
            'per_group_cap' => 2,
        ]);
        $this->finish($run);

        $candidate = $run->fresh()->candidates()
            ->where('left_email_message_id', $left->id)
            ->where('right_email_message_id', $right->id)
            ->sole();
        $this->assertSame(EmailCanonicalCorrelationCandidate::CLASS_OVERSIZED, $candidate->candidate_class);
        $this->assertSame(3, $candidate->group_size);
        $this->assertContains('group_exceeds_per_group_cap', $candidate->reason_codes_json);
        $this->assertContains('overlapping_discovery_requires_narrower_scope', $candidate->reason_codes_json);
    }

    #[Test]
    public function run_and_review_reauthorize_every_scoped_account_and_reviews_are_immutable(): void
    {
        Queue::fake();
        $leftAccount = $this->account($this->operator, 'review-left@example.test');
        $rightAccount = $this->account($this->operator, 'review-right@example.test');
        $this->message($leftAccount, '<review@example.test>', 'left');
        $this->message($rightAccount, '<review@example.test>', 'right');
        $run = app(StartEmailCanonicalCorrelationRun::class)->handle(
            $this->operator,
            [$leftAccount->id, $rightAccount->id],
            ['message_cap' => 10],
        );
        $this->finish($run);
        $candidate = $run->fresh()->candidates()->sole();

        app(InspectEmailCanonicalCorrelationCandidate::class)->handle($candidate, $this->operator);

        $reviewed = app(ReviewEmailCanonicalCorrelationCandidate::class)->handle(
            $candidate,
            $this->operator,
            EmailCanonicalCorrelationCandidate::REVIEW_KEEP_SEPARATE,
            'bcc_variant_confirmed',
        );
        $this->assertSame(EmailCanonicalCorrelationCandidate::REVIEW_KEEP_SEPARATE, $reviewed->review_state);
        $this->assertSame('bcc_variant_confirmed', $reviewed->review_reason_code);

        $this->expectException(ValidationException::class);
        app(ReviewEmailCanonicalCorrelationCandidate::class)->handle(
            $candidate,
            $this->operator,
            EmailCanonicalCorrelationCandidate::REVIEW_CONFIRMED,
            'changed_mind',
        );
    }

    #[Test]
    public function review_rejects_changed_or_deleted_authoritative_evidence(): void
    {
        Queue::fake();
        $account = $this->account($this->operator, 'review-stale@example.test');
        $left = $this->message($account, '<stale-review@example.test>', 'left');
        $this->message($account, '<stale-review@example.test>', 'right');
        $run = app(StartEmailCanonicalCorrelationRun::class)->handle(
            $this->operator,
            [$account->id],
            ['message_cap' => 10],
        );
        $this->finish($run);
        $candidate = $run->fresh()->candidates()->sole();

        $left->update(['checksum_sha1' => sha1('changed-after-shadow-run')]);

        try {
            app(ReviewEmailCanonicalCorrelationCandidate::class)->handle(
                $candidate,
                $this->operator,
                EmailCanonicalCorrelationCandidate::REVIEW_CONFIRMED,
                'evidence_checked',
            );
            $this->fail('Changed authoritative evidence must make the shadow review stale.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('review_state', $exception->errors());
        }

        $this->assertSame(
            EmailCanonicalCorrelationCandidate::REVIEW_UNREVIEWED,
            $candidate->fresh()->review_state,
        );
    }

    #[Test]
    public function inspection_is_audited_before_content_and_does_not_change_personal_state(): void
    {
        Queue::fake();
        $account = $this->account($this->operator, 'inspection@example.test');
        $this->message($account, '<inspection@example.test>', 'inspection left');
        $this->message($account, '<inspection@example.test>', 'inspection right');
        $run = app(StartEmailCanonicalCorrelationRun::class)->handle($this->operator, [$account->id]);
        $this->finish($run);
        $candidate = $run->fresh()->candidates()->sole();

        $response = $this->actingAs($this->operator)->get(route(
            'tech.admin.settings.email.correlation.candidates.inspect',
            $candidate->id,
        ));

        $response->assertOk()
            ->assertSee('Inspect canonical candidate #'.$candidate->id)
            ->assertSee('inspection left')
            ->assertSee('inspection right');
        $this->assertDatabaseHas('email_canonical_correlation_inspections', [
            'email_canonical_correlation_candidate_id' => $candidate->id,
            'inspected_by' => $this->operator->id,
        ]);
        $this->assertDatabaseCount('email_message_user_states', 0);
        $this->assertDatabaseCount('email_account_user_read_baselines', 0);

        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $other->givePermissionTo(['email.inbox_view', 'email.mailbox_sync_manage']);
        $this->actingAs($other)
            ->get(route('tech.admin.settings.email.correlation.show', $run->id))
            ->assertNotFound();
        $this->actingAs($other)
            ->get(route('tech.admin.settings.email.correlation.show', 999999))
            ->assertNotFound();
        $this->actingAs($other)
            ->get(route('tech.admin.settings.email.correlation.candidates.inspect', $candidate->id))
            ->assertNotFound();
    }

    #[Test]
    public function inspection_rejects_a_message_moved_out_of_the_recorded_authorized_account(): void
    {
        Queue::fake();
        $account = $this->account($this->operator, 'inspection-binding@example.test');
        $this->message($account, '<inspection-binding@example.test>', 'left');
        $right = $this->message($account, '<inspection-binding@example.test>', 'right');
        $run = app(StartEmailCanonicalCorrelationRun::class)->handle($this->operator, [$account->id]);
        $this->finish($run);
        $candidate = $run->fresh()->candidates()->sole();

        $otherOwner = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $hiddenAccount = $this->account($otherOwner, 'hidden-binding@example.test');
        $right->forceFill(['account_id' => $hiddenAccount->id])->save();

        $this->expectException(AuthorizationException::class);
        app(InspectEmailCanonicalCorrelationCandidate::class)->handle($candidate, $this->operator);
    }

    #[Test]
    public function inspection_audit_blocks_shadow_schema_rollback(): void
    {
        Queue::fake();
        $account = $this->account($this->operator, 'inspection-rollback@example.test');
        $this->message($account, '<inspection-rollback@example.test>', 'left');
        $this->message($account, '<inspection-rollback@example.test>', 'right');
        $run = app(StartEmailCanonicalCorrelationRun::class)->handle($this->operator, [$account->id]);
        $this->finish($run);
        app(InspectEmailCanonicalCorrelationCandidate::class)
            ->handle($run->fresh()->candidates()->sole(), $this->operator);

        $migration = require database_path(
            'migrations/2026_08_16_110000_create_email_canonical_correlation_shadow.php',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exported or carried forward');
        $migration->down();
    }

    #[Test]
    public function frozen_scope_changes_and_cancelled_runs_cannot_write_candidates_or_terminally_race(): void
    {
        Queue::fake();
        $account = $this->account($this->operator, 'frozen-scope@example.test');
        $left = $this->message($account, '<frozen@example.test>', 'left');
        $this->message($account, '<frozen@example.test>', 'right');
        $changed = app(StartEmailCanonicalCorrelationRun::class)->handle($this->operator, [$account->id]);
        $left->update(['body_text' => 'changed after frozen scope']);

        $this->assertFalse(app(EmailCanonicalCorrelationRunner::class)->processBatch($changed->id));
        $this->assertSame(EmailCanonicalCorrelationRun::STATUS_FAILED, $changed->fresh()->status);
        $this->assertDatabaseCount('email_canonical_correlation_candidates', 0);

        $cancelAccount = $this->account($this->operator, 'cancelled-scope@example.test');
        $this->message($cancelAccount, '<cancel@example.test>', 'left');
        $this->message($cancelAccount, '<cancel@example.test>', 'right');
        $cancelled = app(StartEmailCanonicalCorrelationRun::class)->handle($this->operator, [$cancelAccount->id]);
        app(\App\Modules\Email\Actions\CancelEmailCanonicalCorrelationRun::class)
            ->handle($cancelled, $this->operator);

        $this->assertFalse(app(EmailCanonicalCorrelationRunner::class)->processBatch($cancelled->id));
        $this->assertSame(EmailCanonicalCorrelationRun::STATUS_CANCELLED, $cancelled->fresh()->status);
        $this->assertSame(0, $cancelled->candidates()->count());
    }

    #[Test]
    public function access_revocation_before_processing_fails_closed_without_candidates(): void
    {
        Queue::fake();
        $account = $this->account($this->operator, 'revoked@example.test');
        $this->message($account, '<revoked@example.test>', 'first');
        $this->message($account, '<revoked@example.test>', 'second');
        $run = app(StartEmailCanonicalCorrelationRun::class)->handle($this->operator, [$account->id]);

        $this->transferAccountOwnership($account, User::factory()->create());
        app(EmailCanonicalCorrelationRunner::class)->processBatch($run->id);

        $this->assertSame(EmailCanonicalCorrelationRun::STATUS_FAILED, $run->fresh()->status);
        $this->assertSame('correlation_batch_failed', $run->fresh()->error_code);
        $this->assertDatabaseCount('email_canonical_correlation_candidates', 0);

        $this->expectException(AuthorizationException::class);
        app(StartEmailCanonicalCorrelationRun::class)->handle($this->operator, [$account->id]);
    }

    #[Test]
    public function scopes_over_the_message_cap_are_rejected_before_queueing(): void
    {
        Queue::fake();
        $account = $this->account($this->operator, 'cap@example.test');
        $this->message($account, '<one@example.test>', 'one');
        $this->message($account, '<two@example.test>', 'two');

        try {
            app(StartEmailCanonicalCorrelationRun::class)->handle(
                $this->operator,
                [$account->id],
                ['message_cap' => 1],
            );
            $this->fail('Expected the bounded message cap to reject this scope.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('message_cap', $exception->errors());
        }

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('email_canonical_correlation_runs', 0);
    }

    #[Test]
    public function evidence_snapshot_byte_budget_rejects_work_before_queueing(): void
    {
        Queue::fake();
        $account = $this->account($this->operator, 'byte-cap@example.test');
        $this->message($account, '<byte-cap@example.test>', 'bounded evidence');

        try {
            app(StartEmailCanonicalCorrelationRun::class)->handle(
                $this->operator,
                [$account->id],
                [
                    'evidence_snapshot_byte_cap' => 32,
                    'evidence_run_byte_cap' => 64,
                ],
            );
            $this->fail('Expected the aggregate evidence byte budget to reject this scope.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('message_cap', $exception->errors());
        }

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('email_canonical_correlation_runs', 0);
    }

    #[Test]
    public function aggregate_run_evidence_budget_stops_repeated_reads_fail_closed(): void
    {
        Queue::fake();
        $account = $this->account($this->operator, 'run-byte-cap@example.test');
        $left = $this->message($account, '<run-byte-cap@example.test>', 'left');
        $right = $this->message($account, '<run-byte-cap@example.test>', 'right');
        $snapshot = app(EmailCanonicalCorrelationScope::class)->snapshot(
            [$account->id],
            $left->id,
            $right->id,
        );
        $snapshotCap = $snapshot['evidence_bytes'] + 1;

        $run = app(StartEmailCanonicalCorrelationRun::class)->handle(
            $this->operator,
            [$account->id],
            [
                'evidence_snapshot_byte_cap' => $snapshotCap,
                'evidence_run_byte_cap' => $snapshotCap * 2,
            ],
        );

        $this->assertFalse(app(EmailCanonicalCorrelationRunner::class)->processBatch($run->id));
        $this->assertSame(EmailCanonicalCorrelationRun::STATUS_FAILED, $run->fresh()->status);
        $this->assertSame('evidence_read_cap_reached', $run->fresh()->error_code);
        $this->assertDatabaseCount('email_canonical_correlation_candidates', 0);
    }

    private function finish(EmailCanonicalCorrelationRun $run): void
    {
        $runner = app(EmailCanonicalCorrelationRunner::class);
        $iterations = 0;
        while ($runner->processBatch($run->id)) {
            $iterations++;
            $this->assertLessThan(20, $iterations, 'Correlation processing must remain bounded.');
        }
    }

    private function account(User $owner, string $address): EmailAccount
    {
        return EmailAccount::query()->create([
            'address' => $address,
            'description' => 'Canonical correlation test',
            'account_kind' => EmailAccount::KIND_PERSONAL,
            'owner_id' => $owner->id,
            'is_active' => true,
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => $address,
            'imap_secret' => 'encrypted',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'smtp_username' => $address,
            'smtp_secret' => 'encrypted',
        ]);
    }

    private function message(EmailAccount $account, ?string $messageId, string $subject): EmailMessage
    {
        $this->nextUid++;

        $message = EmailMessage::query()->create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid_validity' => 100,
            'imap_uid' => $this->nextUid,
            'message_id' => $messageId,
            'subject' => $subject,
            'from_email' => 'sender@example.test',
            'to_json' => ['recipient@example.test'],
            'cc_json' => [],
            'headers_json' => [
                'bcc' => [],
                'date' => ['Sun, 16 Aug 2026 10:00:00 +0000'],
            ],
            'received_at' => '2026-08-16 10:00:00',
            'size_bytes' => 1234,
            'body_text' => 'same body',
            'body_html_sanitized' => '<p>same body</p>',
            'attachments_count' => 0,
            'checksum_sha1' => sha1('same-content'),
        ]);

        $rawPath = 'email/raw/canonical-test/'.$message->id.'.eml';
        Storage::disk('local')->put($rawPath, "Message-ID: <canonical-shadow@example.test>\r\n\r\nsame body");
        $message->forceFill(['raw_path' => $rawPath])->save();

        return $message;
    }

    private function attachment(EmailMessage $message, string $filename, string $content): EmailAttachment
    {
        return EmailAttachment::query()->create([
            'message_id' => $message->id,
            'filename' => $filename,
            'content_type' => 'application/pdf',
            'size_bytes' => strlen($content),
            'disk' => 'email-private',
            'path' => 'email/attachments/test/'.hash('sha256', $filename.$message->id),
            'is_inline' => false,
            'cid' => null,
            'checksum_sha1' => sha1($content),
        ]);
    }
}
