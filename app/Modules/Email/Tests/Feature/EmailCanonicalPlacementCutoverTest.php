<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Actions\ApplyEmailCanonicalCutover;
use App\Modules\Email\Actions\InspectEmailCanonicalCorrelationCandidate;
use App\Modules\Email\Actions\PreviewEmailCanonicalCutover;
use App\Modules\Email\Actions\ProcessEmailCanonicalParityAttestation;
use App\Modules\Email\Actions\ReviewEmailCanonicalCorrelationCandidate;
use App\Modules\Email\Actions\RollbackEmailCanonicalCutover;
use App\Modules\Email\Actions\StartEmailCanonicalCorrelationRun;
use App\Modules\Email\Actions\StartEmailCanonicalParityAttestation;
use App\Modules\Email\Jobs\EmailRetentionPurgeJob;
use App\Modules\Email\Livewire\Tech\MailWorkspace;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAttachment;
use App\Modules\Email\Models\EmailCanonicalCorrelationCandidate;
use App\Modules\Email\Models\EmailCanonicalCorrelationRun;
use App\Modules\Email\Models\EmailCanonicalCutoverRun;
use App\Modules\Email\Models\EmailCanonicalMessage;
use App\Modules\Email\Models\EmailCanonicalMessageSource;
use App\Modules\Email\Models\EmailCanonicalParityAttestation;
use App\Modules\Email\Models\EmailCanonicalReadMode;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailRetentionPurgeAttempt;
use App\Modules\Email\Models\EmailRetentionPurgeRun;
use App\Modules\Email\Services\EmailCanonicalContentResolver;
use App\Modules\Email\Services\EmailCanonicalCorrelationRunner;
use App\Modules\Email\Services\EmailCanonicalCutoverEvidence;
use App\Modules\Email\Services\EmailCanonicalSelfMapper;
use App\Modules\Email\Services\EmailRetentionEligibilityService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmailCanonicalPlacementCutoverTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private int $nextUid = 7000;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();
        foreach ([
            'email.inbox_view',
            'email.mailbox_sync_manage',
            'email.canonical_cutover_manage',
            'email.raw_source_view',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->operator = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->operator->givePermissionTo([
            'email.inbox_view',
            'email.mailbox_sync_manage',
            'email.canonical_cutover_manage',
            'email.raw_source_view',
        ]);
    }

    #[Test]
    public function bounded_self_map_mode_cutover_and_newest_first_rollbacks_preserve_source_identity(): void
    {
        $account = $this->account('self-map@example.test');
        [$message, $placement] = $this->messageWithPlacement($account, '<self-map@example.test>');
        $sourceBefore = $message->getAttributes();

        $preview = app(PreviewEmailCanonicalCutover::class)->backfill(
            $this->operator,
            [$account->id],
        );
        $this->assertSame(1, $preview->item_count);
        app(ApplyEmailCanonicalCutover::class)->handle($preview, $this->operator);

        $mapping = EmailCanonicalMessageSource::query()->sole();
        $this->assertSame($message->id, $mapping->source_email_message_id);
        $this->assertSame($mapping->canonical_email_message_id, $placement->fresh()->canonical_email_message_id);
        $this->assertSame($sourceBefore, $message->fresh()->getAttributes());
        $this->assertDatabaseCount('email_remote_operations', 0);
        $this->assertDatabaseCount('email_message_user_states', 0);

        $mode = app(PreviewEmailCanonicalCutover::class)->mode(
            $this->operator,
            [$account->id],
            EmailCanonicalReadMode::MODE_CANONICAL,
        );
        app(ApplyEmailCanonicalCutover::class)->handle($mode, $this->operator);
        $resolution = app(EmailCanonicalContentResolver::class)->resolve(
            $placement->fresh(),
            $message->fresh(),
        );
        $this->assertTrue($resolution->usedCanonical);
        $this->assertSame($message->id, $resolution->message->id);
        $this->assertSame($message->account_id, $resolution->message->account_id);

        app(RollbackEmailCanonicalCutover::class)->handle($mode, $this->operator);
        $this->assertDatabaseMissing('email_canonical_read_modes', ['email_account_id' => $account->id]);
        app(RollbackEmailCanonicalCutover::class)->handle($preview, $this->operator);
        $this->assertDatabaseCount('email_canonical_message_sources', 0);
        $this->assertNull($placement->fresh()->canonical_email_message_id);
        $this->assertSame(EmailCanonicalMessage::STATUS_RETIRED, EmailCanonicalMessage::query()->sole()->status);
    }

    #[Test]
    public function only_exact_complete_reviewed_shadow_evidence_can_merge_and_rollback(): void
    {
        $leftAccount = $this->account('left-cutover@example.test');
        $rightAccount = $this->account('right-cutover@example.test');
        [$left, $leftPlacement] = $this->messageWithPlacement($leftAccount, '<merge@example.test>');
        [$right, $rightPlacement] = $this->messageWithPlacement($rightAccount, 'merge@example.test');
        $candidate = $this->confirmedCandidate([$leftAccount, $rightAccount]);

        $preview = app(PreviewEmailCanonicalCutover::class)->merge(
            $this->operator,
            $candidate->run,
            [$candidate->id],
        );
        app(ApplyEmailCanonicalCutover::class)->handle($preview, $this->operator);

        $mappings = EmailCanonicalMessageSource::query()->orderBy('source_email_message_id')->get();
        $this->assertCount(2, $mappings);
        $this->assertSame(1, $mappings->pluck('canonical_email_message_id')->unique()->count());
        $canonicalId = $mappings->first()->canonical_email_message_id;
        $this->assertSame($canonicalId, $leftPlacement->fresh()->canonical_email_message_id);
        $this->assertSame($canonicalId, $rightPlacement->fresh()->canonical_email_message_id);
        $this->assertSame($left->id, EmailMessage::query()->findOrFail($left->id)->id);
        $this->assertSame($right->id, EmailMessage::query()->findOrFail($right->id)->id);

        app(RollbackEmailCanonicalCutover::class)->handle($preview, $this->operator);
        $this->assertDatabaseCount('email_canonical_message_sources', 0);
        $this->assertNull($leftPlacement->fresh()->canonical_email_message_id);
        $this->assertNull($rightPlacement->fresh()->canonical_email_message_id);
    }

    #[Test]
    public function stored_attachment_hashes_cannot_hide_different_or_missing_actual_files(): void
    {
        $leftAccount = $this->account('file-left@example.test');
        $rightAccount = $this->account('file-right@example.test');
        [$left] = $this->messageWithPlacement($leftAccount, '<file-evidence@example.test>');
        [$right] = $this->messageWithPlacement($rightAccount, '<file-evidence@example.test>');
        $declared = sha1('declared-identical');
        $this->attachment($left, 'proof.pdf', 'actual-left', $declared);
        $this->attachment($right, 'proof.pdf', 'actual-right', $declared);
        $left->update(['attachments_count' => 1]);
        $right->update(['attachments_count' => 1]);
        $candidate = $this->confirmedCandidate([$leftAccount, $rightAccount]);

        $this->expectException(ValidationException::class);
        app(PreviewEmailCanonicalCutover::class)->merge(
            $this->operator,
            $candidate->run,
            [$candidate->id],
        );
    }

    #[Test]
    public function a_connected_but_incomplete_confirmed_candidate_set_is_never_a_component(): void
    {
        $account = $this->account('clique@example.test');
        $this->messageWithPlacement($account, '<clique@example.test>');
        $this->messageWithPlacement($account, '<clique@example.test>');
        $this->messageWithPlacement($account, '<clique@example.test>');
        $run = $this->correlate([$account]);
        $candidates = $run->candidates()->orderBy('id')->get();
        $this->assertCount(3, $candidates);
        foreach ($candidates->take(2) as $candidate) {
            $this->confirm($candidate);
        }

        $this->expectException(ValidationException::class);
        app(PreviewEmailCanonicalCutover::class)->merge(
            $this->operator,
            $run,
            $candidates->take(2)->modelKeys(),
        );
    }

    #[Test]
    public function apply_reauthorizes_and_fails_closed_when_source_or_access_changes(): void
    {
        $account = $this->account('reauthorize@example.test');
        [$message] = $this->messageWithPlacement($account, '<reauthorize@example.test>');
        $preview = app(PreviewEmailCanonicalCutover::class)->backfill($this->operator, [$account->id]);
        $message->update(['subject' => 'changed after preview']);

        try {
            app(ApplyEmailCanonicalCutover::class)->handle($preview, $this->operator);
            $this->fail('Expected source drift to fail the frozen cutover.');
        } catch (ValidationException) {
            $this->assertSame(EmailCanonicalCutoverRun::STATUS_FAILED, $preview->fresh()->status);
            $this->assertDatabaseCount('email_canonical_message_sources', 0);
        }

        $fresh = app(PreviewEmailCanonicalCutover::class)->backfill($this->operator, [$account->id]);
        $account->update(['owner_id' => User::factory()->create()->id]);
        $this->expectException(AuthorizationException::class);
        app(ApplyEmailCanonicalCutover::class)->handle($fresh, $this->operator);
    }

    #[Test]
    public function canonical_mode_falls_back_to_the_authorized_source_on_projection_drift(): void
    {
        $account = $this->account('fallback@example.test');
        [$message, $placement] = $this->messageWithPlacement($account, '<fallback@example.test>');
        $backfill = app(PreviewEmailCanonicalCutover::class)->backfill($this->operator, [$account->id]);
        app(ApplyEmailCanonicalCutover::class)->handle($backfill, $this->operator);
        $mode = app(PreviewEmailCanonicalCutover::class)->mode(
            $this->operator,
            [$account->id],
            EmailCanonicalReadMode::MODE_CANONICAL,
        );
        app(ApplyEmailCanonicalCutover::class)->handle($mode, $this->operator);

        EmailCanonicalMessage::query()->sole()->update(['subject' => 'projection drift']);
        $resolution = app(EmailCanonicalContentResolver::class)->resolve(
            $placement->fresh(),
            $message->fresh(),
        );

        $this->assertFalse($resolution->usedCanonical);
        $this->assertTrue($resolution->driftDetected);
        $this->assertSame($message->subject, $resolution->message->subject);
        $this->assertSame($message->id, $resolution->message->id);
    }

    #[Test]
    public function drift_audit_dissolves_the_complete_component_and_unsafe_rollback_is_blocked(): void
    {
        $leftAccount = $this->account('drift-left@example.test');
        $rightAccount = $this->account('drift-right@example.test');
        [$left] = $this->messageWithPlacement($leftAccount, '<drift@example.test>');
        [$right] = $this->messageWithPlacement($rightAccount, '<drift@example.test>');

        $backfill = app(PreviewEmailCanonicalCutover::class)->backfill(
            $this->operator,
            [$leftAccount->id, $rightAccount->id],
        );
        app(ApplyEmailCanonicalCutover::class)->handle($backfill, $this->operator);
        $candidate = $this->confirmedCandidate([$leftAccount, $rightAccount]);
        $merge = app(PreviewEmailCanonicalCutover::class)->merge(
            $this->operator,
            $candidate->run,
            [$candidate->id],
        );
        app(ApplyEmailCanonicalCutover::class)->handle($merge, $this->operator);
        $mergedCanonicalId = EmailCanonicalMessageSource::query()
            ->where('source_email_message_id', $left->id)
            ->value('canonical_email_message_id');

        $right->update(['body_text' => 'drifted source body']);
        $audit = app(PreviewEmailCanonicalCutover::class)->audit(
            $this->operator,
            [$leftAccount->id, $rightAccount->id],
        );
        $this->assertSame(2, $audit->item_count);
        app(ApplyEmailCanonicalCutover::class)->handle($audit, $this->operator);
        $this->assertSame(
            2,
            EmailCanonicalMessageSource::query()->pluck('canonical_email_message_id')->unique()->count(),
        );

        try {
            app(RollbackEmailCanonicalCutover::class)->handle($audit, $this->operator);
            $this->fail('Expected divergent content to block restoration of the shared component.');
        } catch (ValidationException) {
            $this->assertSame(EmailCanonicalCutoverRun::STATUS_APPLIED, $audit->fresh()->status);
        }

        $right->update(['body_text' => 'same body']);
        app(RollbackEmailCanonicalCutover::class)->handle($audit, $this->operator);
        $this->assertSame(
            [$mergedCanonicalId],
            EmailCanonicalMessageSource::query()
                ->orderBy('source_email_message_id')
                ->pluck('canonical_email_message_id')
                ->unique()
                ->values()
                ->all(),
        );
    }

    #[Test]
    public function evidence_budget_counts_bodies_and_structured_fields_not_only_private_files(): void
    {
        $account = $this->account('materialized-budget@example.test');
        [$message] = $this->messageWithPlacement($account, '<materialized-budget@example.test>');
        $body = str_repeat('b', 128 * 1024);
        $html = str_repeat('h', 128 * 1024);
        $headers = ['bcc' => [], 'large' => str_repeat('j', 128 * 1024)];
        $message->forceFill([
            'body_text' => $body,
            'body_html_sanitized' => $html,
            'headers_json' => $headers,
        ])->save();

        $evidence = app(EmailCanonicalCutoverEvidence::class)->forMessage($message->fresh());
        $this->assertGreaterThanOrEqual(
            strlen($body) + strlen($html) + strlen(json_encode($headers, JSON_THROW_ON_ERROR)),
            $evidence['evidence_bytes'],
        );

        $message->forceFill([
            'headers_json' => ['bcc' => [], 'too_large' => str_repeat('x', (2 * 1024 * 1024) + 1)],
        ])->save();
        $oversized = app(EmailCanonicalCutoverEvidence::class)->forMessage($message->fresh());
        $this->assertFalse($oversized['complete']);
        $this->assertContains('structured_evidence_too_large', $oversized['reason_codes']);
        $this->assertGreaterThan(2 * 1024 * 1024, $oversized['evidence_bytes']);

        $deep = 'leaf';
        for ($depth = 0; $depth < 30; $depth++) {
            $deep = ['nested' => $deep];
        }
        $message->forceFill(['headers_json' => ['deep' => $deep]])->save();
        $overDepth = app(EmailCanonicalCutoverEvidence::class)->forMessage($message->fresh());
        $this->assertFalse($overDepth['complete']);
        $this->assertContains('structured_evidence_complexity_exceeded', $overDepth['reason_codes']);

        $message->forceFill(['headers_json' => array_fill(0, 5_001, 'node')])->save();
        $overNodes = app(EmailCanonicalCutoverEvidence::class)->forMessage($message->fresh());
        $this->assertFalse($overNodes['complete']);
        $this->assertContains('structured_evidence_complexity_exceeded', $overNodes['reason_codes']);
    }

    #[Test]
    public function canonical_mode_cuts_over_workspace_api_raw_and_attachment_reads_without_exposing_canonical_identity(): void
    {
        $account = $this->account('surface-cutover@example.test');
        [$message, $placement] = $this->messageWithPlacement($account, '<surface-cutover@example.test>');
        $attachment = $this->attachment($message, 'surface.pdf', 'surface attachment');
        $message->update(['attachments_count' => 1]);
        $backfill = app(PreviewEmailCanonicalCutover::class)->backfill($this->operator, [$account->id]);
        app(ApplyEmailCanonicalCutover::class)->handle($backfill, $this->operator);
        $mode = app(PreviewEmailCanonicalCutover::class)->mode(
            $this->operator,
            [$account->id],
            EmailCanonicalReadMode::MODE_CANONICAL,
        );
        app(ApplyEmailCanonicalCutover::class)->handle($mode, $this->operator);

        $api = $this->actingAs($this->operator)->getJson(route(
            'api.v1.email.inbox.messages.show',
            [$message, 'include_html' => true],
        ));
        $api->assertOk()
            ->assertJsonPath('data.id', $message->id)
            ->assertJsonPath('data.account_id', $account->id)
            ->assertJsonMissingPath('data.canonical_email_message_id');
        $this->assertStringNotContainsString(
            'canonical_email_message_id',
            (string) $api->getContent(),
        );

        $this->get(route('tech.mail.raw-source.show', ['placement' => $placement]))
            ->assertOk()
            ->assertHeader('Content-Type', 'message/rfc822');
        $this->get(route('tech.mail.attachments.download', [
            'placement' => $placement,
            'attachment' => $attachment,
        ]))->assertOk()->assertDownload('surface.pdf');

        Livewire::actingAs($this->operator)
            ->test(MailWorkspace::class)
            ->call('selectPlacement', $placement->id)
            ->assertSee('Exact canonical subject')
            ->assertSee('same body')
            ->assertSee('surface.pdf')
            ->assertDontSee('canonical_email_message_id');
    }

    #[Test]
    public function canonical_download_keeps_the_exact_route_bound_source_part_for_duplicate_metadata(): void
    {
        $account = $this->account('duplicate-parts@example.test');
        [$message, $placement] = $this->messageWithPlacement($account, '<duplicate-parts@example.test>');
        $first = $this->attachment(
            $message,
            'duplicate.pdf',
            'part-one',
            pathKey: 'duplicate-first.pdf',
        );
        $second = $this->attachment(
            $message,
            'duplicate.pdf',
            'part-two',
            pathKey: 'duplicate-second.pdf',
        );
        $message->update(['attachments_count' => 2]);
        $this->enableCanonicalMode($account);

        $response = $this->actingAs($this->operator)->get(route('tech.mail.attachments.download', [
            'placement' => $placement,
            'attachment' => $second,
        ]));
        $response->assertOk()->assertDownload('duplicate.pdf');
        $this->assertSame('part-two', $response->streamedContent());
        $this->assertSame($second->path, $this->downloadCallbackPath($response));
        $this->assertNotSame($first->path, $this->downloadCallbackPath($response));
    }

    #[Test]
    public function canonical_download_does_not_collapse_identical_duplicate_parts_to_the_first_row(): void
    {
        $account = $this->account('identical-parts@example.test');
        [$message, $placement] = $this->messageWithPlacement($account, '<identical-parts@example.test>');
        $first = $this->attachment(
            $message,
            'same.pdf',
            'same-part',
            pathKey: 'same-first.pdf',
        );
        $second = $this->attachment(
            $message,
            'same.pdf',
            'same-part',
            pathKey: 'same-second.pdf',
        );
        $message->update(['attachments_count' => 2]);
        $this->enableCanonicalMode($account);

        foreach ([$first, $second] as $sourcePart) {
            $response = $this->actingAs($this->operator)->get(route('tech.mail.attachments.download', [
                'placement' => $placement,
                'attachment' => $sourcePart,
            ]));
            $response->assertOk()->assertDownload('same.pdf');
            $this->assertSame('same-part', $response->streamedContent());
            $this->assertSame($sourcePart->path, $this->downloadCallbackPath($response));
        }
    }

    #[Test]
    public function mail_and_api_reads_stay_legacy_before_the_additive_schema_exists(): void
    {
        $account = $this->account('pre-schema@example.test');
        [$message, $placement] = $this->messageWithPlacement($account, '<pre-schema@example.test>');
        $migration = require database_path(
            'migrations/2026_08_16_111000_add_email_canonical_message_placement_cutover.php',
        );
        $migration->down();

        try {
            $this->assertFalse(Schema::hasTable('email_canonical_message_sources'));
            $this->actingAs($this->operator)
                ->get(route('tech.admin.settings.email.canonical-cutover.index'))
                ->assertOk()
                ->assertSee('additive canonical cutover migration is pending');
            $this->actingAs($this->operator)
                ->getJson(route('api.v1.email.inbox.messages.show', $message))
                ->assertOk()
                ->assertJsonPath('data.id', $message->id)
                ->assertJsonPath('data.subject', 'Exact canonical subject');

            Livewire::actingAs($this->operator)
                ->test(MailWorkspace::class)
                ->call('selectPlacement', $placement->id)
                ->assertSee('Exact canonical subject');
        } finally {
            $migration->up();
        }
    }

    #[Test]
    public function retention_protects_a_mapped_source_before_touching_private_payloads(): void
    {
        $account = $this->account('canonical-retention@example.test');
        [$message, $placement] = $this->messageWithPlacement($account, '<canonical-retention@example.test>');
        $backfill = app(PreviewEmailCanonicalCutover::class)->backfill($this->operator, [$account->id]);
        app(ApplyEmailCanonicalCutover::class)->handle($backfill, $this->operator);

        $placement->delete();
        $message->forceFill(['received_at' => now()->subMonths(25)])->save();
        $rawPath = (string) $message->raw_path;

        app()->call([new EmailRetentionPurgeJob(24), 'handle']);

        $this->assertNotNull(EmailMessage::withTrashed()->find($message->id));
        Storage::disk('local')->assertExists($rawPath);
        $attempt = EmailRetentionPurgeAttempt::query()->sole();
        $this->assertSame(EmailRetentionPurgeAttempt::STATUS_PROTECTED, $attempt->status);
        $this->assertContains(
            EmailRetentionEligibilityService::REASON_CANONICAL_CUTOVER,
            $attempt->reasons_json,
        );
        $this->assertDatabaseHas('email_retention_purge_runs', [
            'id' => EmailRetentionPurgeRun::query()->sole()->id,
            'status' => EmailRetentionPurgeRun::STATUS_COMPLETED,
            'failed_count' => 0,
            'purged_count' => 0,
        ]);
    }

    #[Test]
    public function admin_cutover_is_metadata_only_permission_separated_and_runs_preview_apply_rollback(): void
    {
        $account = $this->account('cutover-admin@example.test');
        [$message] = $this->messageWithPlacement($account, '<cutover-admin@example.test>');

        $this->actingAs($this->operator)
            ->get(route('tech.admin.settings.email.canonical-cutover.index'))
            ->assertOk()
            ->assertSee('Canonical mail cutover')
            ->assertSee($account->address)
            ->assertSee('Every operation starts as an immutable bounded preview');

        $this->post(route('tech.admin.settings.email.canonical-cutover.backfill'), [
            'account_ids' => [$account->id],
            'item_cap' => 10,
        ])->assertRedirect();
        $run = EmailCanonicalCutoverRun::query()->sole();
        $this->assertSame(EmailCanonicalCutoverRun::STATUS_PREVIEWED, $run->status);

        $report = $this->get(route('tech.admin.settings.email.canonical-cutover.show', $run));
        $report->assertOk()
            ->assertSee('Canonical cutover run #'.$run->id)
            ->assertSee('#'.$message->id)
            ->assertDontSee('canonical_email_message_id');

        $this->post(route('tech.admin.settings.email.canonical-cutover.apply', $run), [
            'confirmation' => 'wrong',
        ])->assertSessionHasErrors('confirmation');
        $this->assertSame(EmailCanonicalCutoverRun::STATUS_PREVIEWED, $run->fresh()->status);

        $this->post(route('tech.admin.settings.email.canonical-cutover.apply', $run), [
            'confirmation' => 'APPLY RUN #'.$run->id,
        ])->assertRedirect();
        $this->assertSame(EmailCanonicalCutoverRun::STATUS_APPLIED, $run->fresh()->status);
        $this->assertDatabaseHas('email_canonical_message_sources', [
            'source_email_message_id' => $message->id,
        ]);

        $this->post(route('tech.admin.settings.email.canonical-cutover.rollback', $run), [
            'confirmation' => 'ROLLBACK RUN #'.$run->id,
        ])->assertRedirect();
        $this->assertSame(EmailCanonicalCutoverRun::STATUS_ROLLED_BACK, $run->fresh()->status);

        $missingNewPermission = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $missingNewPermission->givePermissionTo(['email.inbox_view', 'email.mailbox_sync_manage']);
        $this->actingAs($missingNewPermission)
            ->get(route('tech.admin.settings.email.canonical-cutover.index'))
            ->assertForbidden();

        $missingSyncPermission = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $missingSyncPermission->givePermissionTo(['email.inbox_view', 'email.canonical_cutover_manage']);
        $this->actingAs($missingSyncPermission)
            ->get(route('tech.admin.settings.email.canonical-cutover.index'))
            ->assertForbidden();

        $otherOperator = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $otherOperator->givePermissionTo([
            'email.inbox_view',
            'email.mailbox_sync_manage',
            'email.canonical_cutover_manage',
        ]);
        $this->actingAs($otherOperator)
            ->get(route('tech.admin.settings.email.canonical-cutover.show', $run))
            ->assertNotFound();
    }

    #[Test]
    public function a_current_authorized_operator_can_apply_and_rollback_after_requester_offboarding(): void
    {
        $account = $this->account('offboarded-requester@example.test');
        [$message] = $this->messageWithPlacement($account, '<offboarded-requester@example.test>');
        $run = app(PreviewEmailCanonicalCutover::class)->backfill($this->operator, [$account->id]);

        $replacement = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $replacement->givePermissionTo([
            'email.inbox_view',
            'email.mailbox_sync_manage',
            'email.canonical_cutover_manage',
        ]);
        $account->update(['owner_id' => $replacement->id]);
        $this->operator->update(['status' => User::STATUS_DISABLED]);

        $this->actingAs($replacement)
            ->get(route('tech.admin.settings.email.canonical-cutover.index'))
            ->assertOk()
            ->assertSee('#'.$run->id);
        $this->actingAs($replacement)
            ->get(route('tech.admin.settings.email.canonical-cutover.show', $run))
            ->assertOk()
            ->assertSee('Canonical cutover run #'.$run->id);
        $this->post(route('tech.admin.settings.email.canonical-cutover.apply', $run), [
            'confirmation' => 'APPLY RUN #'.$run->id,
        ])->assertRedirect();
        $this->assertDatabaseHas('email_canonical_message_sources', [
            'source_email_message_id' => $message->id,
            'mapped_by' => $replacement->id,
        ]);
        $this->post(route('tech.admin.settings.email.canonical-cutover.rollback', $run), [
            'confirmation' => 'ROLLBACK RUN #'.$run->id,
        ])->assertRedirect();
        $this->assertSame($replacement->id, $run->fresh()->rolled_back_by);
        $this->assertSame(EmailCanonicalCutoverRun::STATUS_ROLLED_BACK, $run->fresh()->status);
    }

    #[Test]
    public function canonical_cutover_permission_is_seeded_only_to_privileged_roles(): void
    {
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $this->assertTrue(Permission::findByName('email.canonical_cutover_manage')->exists);
        $this->assertTrue(Role::findByName('Admin')->hasPermissionTo('email.canonical_cutover_manage'));
        $this->assertTrue(Role::findByName('Superuser')->hasPermissionTo('email.canonical_cutover_manage'));
        $this->assertFalse(Role::findByName('Tech')->hasPermissionTo('email.canonical_cutover_manage'));
    }

    #[Test]
    public function paginated_account_attestation_unlocks_more_than_five_hundred_placements_and_rejects_drift_and_age(): void
    {
        $account = $this->account('large-parity@example.test');
        $selfMapper = app(EmailCanonicalSelfMapper::class);
        for ($index = 1; $index <= 501; $index++) {
            [$message] = $this->messageWithPlacement(
                $account,
                '<large-parity-'.$index.'@example.test>',
            );
            $this->assertNotNull($selfMapper->map($message));
        }

        $start = app(StartEmailCanonicalParityAttestation::class);
        $process = app(ProcessEmailCanonicalParityAttestation::class);
        $attestation = $start->handle($this->operator, $account->id, strictEvidence: true);
        $attestation = $process->handle($attestation, $this->operator, batchSize: 100);
        $this->assertSame(100, $attestation->verified_placement_count);
        $this->assertSame(EmailCanonicalParityAttestation::STATUS_PENDING, $attestation->status);

        $replacement = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $replacement->givePermissionTo([
            'email.inbox_view',
            'email.mailbox_sync_manage',
            'email.canonical_cutover_manage',
        ]);
        $account->update(['owner_id' => $replacement->id]);
        $this->operator->update(['status' => User::STATUS_DISABLED]);

        $attestation = $this->completeParityAttestation($attestation, $replacement, $process);
        $this->assertSame(501, $attestation->frozen_active_placement_count);
        $this->assertSame(501, $attestation->verified_placement_count);
        $this->assertSame(501, $attestation->items()->count());
        $this->assertSame($this->operator->id, $attestation->requested_by);
        $this->assertSame($replacement->id, $attestation->completed_by);
        $this->assertNotNull($attestation->attestation_fingerprint);

        $driftedMode = app(PreviewEmailCanonicalCutover::class)->mode(
            $replacement,
            [$account->id],
            EmailCanonicalReadMode::MODE_CANONICAL,
        );
        $driftedItem = $driftedMode->items()->sole();
        $this->assertSame($attestation->id, $driftedItem->parity_attestation_id);
        $this->assertSame(
            $attestation->attestation_fingerprint,
            $driftedItem->parity_attestation_fingerprint,
        );

        [$newMessage] = $this->messageWithPlacement($account, '<large-parity-new@example.test>');
        $this->assertNotNull($selfMapper->map($newMessage));
        try {
            app(ApplyEmailCanonicalCutover::class)->handle($driftedMode, $replacement);
            $this->fail('A mode preview must reject a changed active-placement scope.');
        } catch (ValidationException) {
            $this->assertSame(EmailCanonicalCutoverRun::STATUS_FAILED, $driftedMode->fresh()->status);
        }

        $freshAttestation = $start->handle($replacement, $account->id, strictEvidence: true);
        $this->assertNotSame($attestation->id, $freshAttestation->id);
        $freshAttestation = $this->completeParityAttestation(
            $freshAttestation,
            $replacement,
            $process,
        );
        $this->assertSame(502, $freshAttestation->verified_placement_count);

        $staleMode = app(PreviewEmailCanonicalCutover::class)->mode(
            $replacement,
            [$account->id],
            EmailCanonicalReadMode::MODE_VERIFY,
        );
        $this->assertSame(
            $freshAttestation->attestation_fingerprint,
            $staleMode->items()->sole()->parity_attestation_fingerprint,
        );
        $this->travel(16)->minutes();
        try {
            app(ApplyEmailCanonicalCutover::class)->handle($staleMode, $replacement);
            $this->fail('A mode preview must reject an aged parity attestation.');
        } catch (ValidationException) {
            $this->assertSame(EmailCanonicalCutoverRun::STATUS_FAILED, $staleMode->fresh()->status);
        } finally {
            $this->travelBack();
        }

        $canonicalMode = app(PreviewEmailCanonicalCutover::class)->mode(
            $replacement,
            [$account->id],
            EmailCanonicalReadMode::MODE_CANONICAL,
        );
        $this->assertSame(
            $freshAttestation->id,
            $canonicalMode->items()->sole()->parity_attestation_id,
        );
        app(ApplyEmailCanonicalCutover::class)->handle($canonicalMode, $replacement);
        $this->assertDatabaseHas('email_canonical_read_modes', [
            'email_account_id' => $account->id,
            'mode' => EmailCanonicalReadMode::MODE_CANONICAL,
            'updated_by' => $replacement->id,
        ]);
    }

    #[Test]
    public function schema_rollback_refuses_to_erase_preview_and_parity_audit_evidence(): void
    {
        $account = $this->account('durable-schema-audit@example.test');
        $this->messageWithPlacement($account, '<durable-schema-audit@example.test>');
        $run = app(PreviewEmailCanonicalCutover::class)->backfill($this->operator, [$account->id]);
        $attestation = app(StartEmailCanonicalParityAttestation::class)->handle(
            $this->operator,
            $account->id,
            strictEvidence: true,
        );
        $migration = require database_path(
            'migrations/2026_08_16_111000_add_email_canonical_message_placement_cutover.php',
        );

        try {
            $migration->down();
            $this->fail('Durable preview/attestation evidence must block schema rollback.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('durable audit evidence', $exception->getMessage());
        }

        $this->assertDatabaseHas('email_canonical_cutover_runs', ['id' => $run->id]);
        $this->assertDatabaseHas('email_canonical_cutover_items', [
            'email_canonical_cutover_run_id' => $run->id,
        ]);
        $this->assertDatabaseHas('email_canonical_parity_attestations', ['id' => $attestation->id]);
        $this->assertTrue(Schema::hasTable('email_canonical_messages'));
    }

    private function confirmedCandidate(array $accounts): EmailCanonicalCorrelationCandidate
    {
        $run = $this->correlate($accounts);
        $candidate = $run->candidates()->sole();
        $this->confirm($candidate);

        return $candidate->fresh(['run']);
    }

    private function completeParityAttestation(
        EmailCanonicalParityAttestation $attestation,
        User $actor,
        ProcessEmailCanonicalParityAttestation $process,
    ): EmailCanonicalParityAttestation {
        $iterations = 0;
        while ($attestation->status !== EmailCanonicalParityAttestation::STATUS_COMPLETED) {
            $before = (int) $attestation->verified_placement_count;
            $attestation = $process->handle($attestation, $actor, batchSize: 100);
            $this->assertGreaterThan($before, (int) $attestation->verified_placement_count);
            $this->assertLessThanOrEqual(
                ProcessEmailCanonicalParityAttestation::MAX_BATCH_SIZE,
                (int) $attestation->verified_placement_count - $before,
            );
            $this->assertLessThan(10, ++$iterations);
        }

        return $attestation;
    }

    private function correlate(array $accounts): EmailCanonicalCorrelationRun
    {
        $run = app(StartEmailCanonicalCorrelationRun::class)->handle(
            $this->operator,
            collect($accounts)->pluck('id')->all(),
            ['message_cap' => 20, 'group_cap' => 20, 'pair_cap' => 50],
        );
        $runner = app(EmailCanonicalCorrelationRunner::class);
        $iterations = 0;
        while ($runner->processBatch($run->id)) {
            $this->assertLessThan(20, ++$iterations);
        }

        return $run->fresh();
    }

    private function confirm(EmailCanonicalCorrelationCandidate $candidate): void
    {
        app(InspectEmailCanonicalCorrelationCandidate::class)->handle($candidate, $this->operator);
        app(ReviewEmailCanonicalCorrelationCandidate::class)->handle(
            $candidate,
            $this->operator,
            EmailCanonicalCorrelationCandidate::REVIEW_CONFIRMED,
            'manual_exact_review',
        );
    }

    private function account(string $address): EmailAccount
    {
        return EmailAccount::query()->create([
            'address' => $address,
            'description' => 'Canonical cutover test',
            'account_kind' => EmailAccount::KIND_PERSONAL,
            'owner_id' => $this->operator->id,
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

    /** @return array{0:EmailMessage,1:EmailMailboxPlacement} */
    private function messageWithPlacement(EmailAccount $account, string $messageId): array
    {
        $this->nextUid++;
        $message = EmailMessage::query()->create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid_validity' => 991,
            'imap_uid' => $this->nextUid,
            'message_id' => $messageId,
            'subject' => 'Exact canonical subject',
            'from_name' => 'Sender',
            'from_email' => 'sender@example.test',
            'to_json' => ['recipient@example.test'],
            'cc_json' => [],
            'headers_json' => [
                'bcc' => [],
                'date' => ['Sun, 16 Aug 2026 10:00:00 +0000'],
            ],
            'in_reply_to' => null,
            'references' => null,
            'received_at' => '2026-08-16 10:00:00',
            'size_bytes' => 1234,
            'is_oversize' => false,
            'state' => 'untriaged',
            'labels_json' => [],
            'body_text' => 'same body',
            'body_html_sanitized' => '<p>same body</p>',
            'attachments_count' => 0,
            'checksum_sha1' => sha1('same-content'),
        ]);
        $rawPath = 'email/raw/cutover/'.$message->id.'.eml';
        Storage::disk('local')->put(
            $rawPath,
            "Message-ID: <canonical-cutover@example.test>\r\n\r\nsame body",
        );
        $message->forceFill(['raw_path' => $rawPath])->save();

        $folder = EmailFolder::query()->firstOrCreate([
            'account_id' => $account->id,
            'path' => 'INBOX',
        ], [
            'provider' => 'imap',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 991,
            'sync_status' => EmailFolder::SYNC_SYNCED,
        ]);
        $placement = EmailMailboxPlacement::query()->create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'provider' => 'imap',
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 991,
            'imap_uid' => $message->imap_uid,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
        ]);

        return [$message->fresh(), $placement->fresh()];
    }

    private function attachment(
        EmailMessage $message,
        string $filename,
        string $actualContent,
        ?string $declaredChecksum = null,
        ?string $pathKey = null,
    ): EmailAttachment {
        $path = 'email/attachments/cutover/'.$message->id.'/'.($pathKey ?? $filename);
        Storage::disk('local')->put($path, $actualContent);

        return EmailAttachment::query()->create([
            'message_id' => $message->id,
            'filename' => $filename,
            'content_type' => 'application/pdf',
            'size_bytes' => strlen($actualContent),
            'disk' => 'local',
            'path' => $path,
            'is_inline' => false,
            'cid' => null,
            'checksum_sha1' => $declaredChecksum ?? sha1($actualContent),
        ]);
    }

    private function enableCanonicalMode(EmailAccount $account): void
    {
        $backfill = app(PreviewEmailCanonicalCutover::class)->backfill($this->operator, [$account->id]);
        app(ApplyEmailCanonicalCutover::class)->handle($backfill, $this->operator);
        $mode = app(PreviewEmailCanonicalCutover::class)->mode(
            $this->operator,
            [$account->id],
            EmailCanonicalReadMode::MODE_CANONICAL,
        );
        app(ApplyEmailCanonicalCutover::class)->handle($mode, $this->operator);
    }

    private function downloadCallbackPath($response): string
    {
        $callback = $response->baseResponse->getCallback();
        $variables = (new \ReflectionFunction($callback))->getStaticVariables();

        return (string) ($variables['path'] ?? '');
    }
}
