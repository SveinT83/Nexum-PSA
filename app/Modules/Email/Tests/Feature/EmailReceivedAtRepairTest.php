<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAttachment;
use App\Modules\Email\Models\EmailConversation;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailMessageReceivedAtRepair;
use App\Modules\Email\Models\EmailSmartInboxSuggestion;
use App\Modules\Email\Models\EmailSmartInboxSuggestionEvent;
use App\Modules\Email\Services\EmailConversationFingerprint;
use App\Modules\Email\Services\EmailMessageReceivedAtRepairService;
use App\Modules\Email\Services\EmailSmartInboxSuggestionEventRecorder;
use App\Modules\Email\Services\EmailSmartInboxSuggestionIdentity;
use App\Modules\Email\Services\EmailSmartInboxSuggestionStateService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmailReceivedAtRepairTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private int $nextUid = 9100;

    protected function setUp(): void
    {
        parent::setUp();

        $view = Permission::findOrCreate('email.inbox_view', 'web');
        $role = Role::create(['name' => 'Received At Repair Tech']);
        $role->givePermissionTo($view);
        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->assignRole($role);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function non_content_updates_keep_received_at_and_v2_fingerprint_stable(): void
    {
        CarbonImmutable::setTestNow('2026-08-15 10:00:00');
        $fixture = $this->conversationFixture(
            'fingerprint@example.test',
            '2026-08-14 12:17:49',
            ['date' => ['Fri, 14 Aug 2026 12:17:49 +0000']],
        );
        $fingerprints = app(EmailConversationFingerprint::class);
        $beforeV2 = $fingerprints->forConversation($fixture['conversation']);
        $beforeV1 = $fingerprints->forConversation(
            $fixture['conversation'],
            EmailConversationFingerprint::LEGACY_SCHEMA_VERSION,
        );

        CarbonImmutable::setTestNow('2026-08-15 10:01:00');
        $fixture['message']->update(['state' => 'linked']);
        DB::table('email_messages')
            ->where('id', $fixture['message']->id)
            ->update(['subject_search' => 'Friendly subject projection']);

        $message = $fixture['message']->fresh();
        $afterV2 = $fingerprints->forConversation($fixture['conversation']);
        $afterV1 = $fingerprints->forConversation(
            $fixture['conversation'],
            EmailConversationFingerprint::LEGACY_SCHEMA_VERSION,
        );

        $this->assertSame('2026-08-14 12:17:49', $message->received_at->format('Y-m-d H:i:s'));
        $this->assertSame(EmailConversationFingerprint::SCHEMA_VERSION, $afterV2['schema_version']);
        $this->assertSame($beforeV2['fingerprint'], $afterV2['fingerprint']);
        $this->assertNotSame($beforeV1['fingerprint'], $afterV1['fingerprint']);
    }

    #[Test]
    public function v2_hashes_canonical_attachment_metadata_while_v1_remains_byte_compatible(): void
    {
        $fixture = $this->conversationFixture(
            'attachment-fingerprint@example.test',
            '2026-08-14 12:17:49',
            ['date' => ['Fri, 14 Aug 2026 12:17:49 +0000']],
        );
        $fixture['message']->forceFill(['attachments_count' => 1])->save();
        $fingerprints = app(EmailConversationFingerprint::class);
        $beforeV2 = $fingerprints->forConversation($fixture['conversation']);
        $beforeV1 = $fingerprints->forConversation(
            $fixture['conversation'],
            EmailConversationFingerprint::LEGACY_SCHEMA_VERSION,
        );

        $attachment = EmailAttachment::query()->create([
            'message_id' => $fixture['message']->id,
            'filename' => 'evidence.pdf',
            'content_type' => 'application/pdf',
            'size_bytes' => 1234,
            'disk' => 'local',
            'path' => 'email/private/evidence.pdf',
            'is_inline' => false,
            'cid' => null,
            'checksum_sha1' => sha1('attachment-content'),
        ]);
        $afterAddV2 = $fingerprints->forConversation($fixture['conversation']);
        $afterAddV1 = $fingerprints->forConversation(
            $fixture['conversation'],
            EmailConversationFingerprint::LEGACY_SCHEMA_VERSION,
        );

        $attachment->update(['filename' => 'renamed-evidence.pdf']);
        $afterChangeV2 = $fingerprints->forConversation($fixture['conversation']);
        $afterChangeV1 = $fingerprints->forConversation(
            $fixture['conversation'],
            EmailConversationFingerprint::LEGACY_SCHEMA_VERSION,
        );

        $this->assertSame(1, $fixture['message']->fresh()->attachments_count);
        $this->assertNotSame($beforeV2['fingerprint'], $afterAddV2['fingerprint']);
        $this->assertNotSame($afterAddV2['fingerprint'], $afterChangeV2['fingerprint']);
        $this->assertSame($beforeV1['fingerprint'], $afterAddV1['fingerprint']);
        $this->assertSame($beforeV1['fingerprint'], $afterChangeV1['fingerprint']);
    }

    #[Test]
    public function repair_uses_proven_evidence_and_leaves_local_ingest_candidate_unresolved(): void
    {
        CarbonImmutable::setTestNow('2026-08-15 10:00:00');
        $header = $this->conversationFixture(
            'header@example.test',
            '2026-08-14 12:17:49',
            ['date' => ['Fri, 14 Aug 2026 12:17:49 +0000 (GMT)']],
        );
        $boundary = $this->conversationFixture(
            'boundary@example.test',
            '2026-08-13 08:30:00',
            ['date' => ['Wed, 16 Aug 2034 08:30:00 +0000']],
        );
        $fallback = $this->conversationFixture(
            'fallback@example.test',
            '2026-08-12 07:00:00',
            [],
        );
        $fallback['conversation']->forceFill([
            'first_email_message_id' => null,
            'latest_email_message_id' => null,
            'first_message_at' => null,
            'last_message_at' => null,
        ])->save();

        foreach ([$header, $boundary, $fallback] as $fixture) {
            DB::table('email_messages')
                ->where('id', $fixture['message']->id)
                ->update(['received_at' => '2026-08-15 15:40:35']);
            $this->seedRepair($fixture['message']->id, '2026-08-15 15:40:35');
        }

        $repair = app(EmailMessageReceivedAtRepairService::class);
        $preview = $repair->run();

        $this->assertSame(3, $preview['scoped']);
        $this->assertSame(2, $preview['repairable']);
        $this->assertSame(1, $preview['unresolved']);
        $this->assertSame([
            EmailMessageReceivedAtRepair::SOURCE_CONVERSATION_BOUNDARY => 1,
            EmailMessageReceivedAtRepair::SOURCE_HEADER_DATE => 1,
        ], $preview['sources']);
        $this->assertSame([
            EmailMessageReceivedAtRepair::CANDIDATE_LOCAL_INGEST_CREATED_AT => 1,
        ], $preview['candidates']);
        $this->assertSame(1, $preview['issues']['header_date_implausible_future']);
        $this->assertSame(1, $preview['issues']['local_ingest_created_at_requires_review']);
        $this->assertSame('2026-08-15 15:40:35', $header['message']->fresh()->received_at->format('Y-m-d H:i:s'));

        $applied = $repair->run(true);

        $this->assertSame(2, $applied['repaired']);
        $this->assertSame(1, $applied['unresolved']);
        $this->assertSame('2026-08-14 12:17:49', $header['message']->fresh()->received_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-13 08:30:00', $boundary['message']->fresh()->received_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-15 15:40:35', $fallback['message']->fresh()->received_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('email_message_received_at_repairs', [
            'email_message_id' => $fallback['message']->id,
            'status' => EmailMessageReceivedAtRepair::STATUS_UNRESOLVED,
            'candidate_received_at' => '2026-08-15 10:00:00',
            'candidate_source' => EmailMessageReceivedAtRepair::CANDIDATE_LOCAL_INGEST_CREATED_AT,
            'reason_code' => 'local_ingest_created_at_requires_review',
        ]);

        $again = $repair->run(true);
        $this->assertSame(0, $again['repaired']);
        $this->assertSame(2, $again['already_repaired']);
        $this->assertSame(1, $again['unresolved']);
    }

    #[Test]
    public function exact_legacy_fingerprint_repair_recovers_only_the_five_false_stale_suggestions(): void
    {
        CarbonImmutable::setTestNow('2026-08-15 15:40:52');
        $fixture = $this->conversationFixture(
            'recover@example.test',
            '2026-08-14 12:17:49',
            ['date' => ['Fri, 14 Aug 2026 12:17:49 +0000']],
        );
        $source = app(EmailConversationFingerprint::class)->forConversation(
            $fixture['conversation'],
            EmailConversationFingerprint::LEGACY_SCHEMA_VERSION,
        );
        $suggestions = collect();

        foreach (range(1, 6) as $index) {
            $fingerprint = $index <= 5 ? $source['fingerprint'] : hash('sha256', 'does-not-match');
            $suggestion = $this->suggestion($fixture, $fingerprint, $index);
            DB::table('email_smart_inbox_suggestions')
                ->where('id', $suggestion->id)
                ->update(['source_fingerprint_schema' => null]);
            $suggestions->push($suggestion->fresh());
        }

        DB::table('email_messages')
            ->where('id', $fixture['message']->id)
            ->update(['received_at' => '2026-08-15 15:40:35']);
        $this->seedRepair($fixture['message']->id, '2026-08-15 15:40:35');

        foreach ($suggestions as $suggestion) {
            app(EmailSmartInboxSuggestionStateService::class)->refresh($suggestion, $this->actor);
        }

        $this->assertSame(6, EmailSmartInboxSuggestion::query()
            ->where('status', EmailSmartInboxSuggestion::STATUS_STALE)
            ->count());

        $result = app(EmailMessageReceivedAtRepairService::class)->run(true);

        $this->assertSame(5, $result['recovered_suggestions']);
        $this->assertSame(5, EmailSmartInboxSuggestion::query()
            ->whereIn('id', $suggestions->take(5)->pluck('id'))
            ->where('status', EmailSmartInboxSuggestion::STATUS_PENDING)
            ->whereNull('stale_at')
            ->count());
        $this->assertSame(EmailSmartInboxSuggestion::STATUS_STALE, $suggestions->last()->fresh()->status);
        $this->assertSame(5, EmailSmartInboxSuggestionEvent::query()
            ->where('event_type', EmailSmartInboxSuggestionEvent::TYPE_RECOVERED)
            ->where('reason_code', 'received_at_timestamp_repaired')
            ->count());
        $this->assertDatabaseHas('email_message_received_at_repairs', [
            'email_message_id' => $fixture['message']->id,
            'smart_suggestions_recovered' => 5,
        ]);

        // A later real content change may be reverted until its v2 digest once
        // again matches. It is outside this incident's observed-to-repaired
        // window and must never be reactivated by a maintenance rerun.
        CarbonImmutable::setTestNow('2026-08-15 17:00:00');
        $currentV2 = app(EmailConversationFingerprint::class)->forConversation($fixture['conversation']);
        $laterStale = $this->suggestion(
            $fixture,
            $currentV2['fingerprint'],
            7,
            EmailConversationFingerprint::SCHEMA_VERSION,
        );
        DB::table('email_messages')
            ->where('id', $fixture['message']->id)
            ->update(['body_text' => 'A later material content change.']);
        app(EmailSmartInboxSuggestionStateService::class)->refresh($laterStale, $this->actor);
        DB::table('email_messages')
            ->where('id', $fixture['message']->id)
            ->update(['body_text' => 'Repair source body.']);

        $again = app(EmailMessageReceivedAtRepairService::class)->run(true);
        $this->assertSame(0, $again['recovered_suggestions']);
        $this->assertSame(EmailSmartInboxSuggestion::STATUS_STALE, $laterStale->fresh()->status);
        $this->assertSame(5, EmailSmartInboxSuggestionEvent::query()
            ->where('event_type', EmailSmartInboxSuggestionEvent::TYPE_RECOVERED)
            ->count());
    }

    #[Test]
    public function pre_migration_suggestion_writes_select_legacy_fingerprint_schema(): void
    {
        $fixture = $this->conversationFixture(
            'staged@example.test',
            '2026-08-14 12:17:49',
            ['date' => ['Fri, 14 Aug 2026 12:17:49 +0000']],
        );

        Schema::table('email_smart_inbox_suggestions', function (Blueprint $table): void {
            $table->dropColumn('source_fingerprint_schema');
        });

        try {
            $schema = EmailSmartInboxSuggestion::fingerprintSchemaForNewRows();
            $source = app(EmailConversationFingerprint::class)->forConversation(
                $fixture['conversation'],
                $schema,
            );

            $this->assertSame(EmailConversationFingerprint::LEGACY_SCHEMA_VERSION, $schema);
            $this->assertSame(EmailConversationFingerprint::LEGACY_SCHEMA_VERSION, $source['schema_version']);
        } finally {
            Schema::table('email_smart_inbox_suggestions', function (Blueprint $table): void {
                $table->string('source_fingerprint_schema', 80)->nullable();
            });
        }
    }

    #[Test]
    public function unknown_recorded_fingerprint_schema_fails_closed_without_a_runtime_error(): void
    {
        $fixture = $this->conversationFixture(
            'unknown-schema@example.test',
            '2026-08-14 12:17:49',
            ['date' => ['Fri, 14 Aug 2026 12:17:49 +0000']],
        );
        $source = app(EmailConversationFingerprint::class)->forConversation($fixture['conversation']);
        $suggestion = $this->suggestion(
            $fixture,
            $source['fingerprint'],
            8,
            EmailConversationFingerprint::SCHEMA_VERSION,
        );
        $suggestion->forceFill([
            'source_fingerprint_schema' => 'email.conversation_fingerprint.unknown',
        ])->save();

        $refreshed = app(EmailSmartInboxSuggestionStateService::class)
            ->refresh($suggestion, $this->actor);

        $this->assertSame(EmailSmartInboxSuggestion::STATUS_STALE, $refreshed->status);
        $this->assertDatabaseHas('email_smart_inbox_suggestion_events', [
            'email_smart_inbox_suggestion_id' => $suggestion->id,
            'event_type' => EmailSmartInboxSuggestionEvent::TYPE_STALE,
            'reason_code' => 'conversation_fingerprint_schema_unsupported',
        ]);
    }

    #[Test]
    public function apply_refuses_to_overwrite_a_message_changed_after_the_migration_snapshot(): void
    {
        $fixture = $this->conversationFixture(
            'cas@example.test',
            '2026-08-14 12:17:49',
            ['date' => ['Fri, 14 Aug 2026 12:17:49 +0000']],
        );
        DB::table('email_messages')
            ->where('id', $fixture['message']->id)
            ->update(['received_at' => '2026-08-15 15:40:35']);
        $this->seedRepair($fixture['message']->id, '2026-08-15 15:40:35');
        DB::table('email_messages')
            ->where('id', $fixture['message']->id)
            ->update(['received_at' => '2026-08-15 15:41:00']);

        $result = app(EmailMessageReceivedAtRepairService::class)->run(true);

        $this->assertSame(1, $result['unresolved']);
        $this->assertSame('2026-08-15 15:41:00', $fixture['message']->fresh()->received_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('email_message_received_at_repairs', [
            'email_message_id' => $fixture['message']->id,
            'status' => EmailMessageReceivedAtRepair::STATUS_UNRESOLVED,
            'reason_code' => 'message_changed_since_repair_snapshot',
        ]);
    }

    /** @return array{account: EmailAccount, conversation: EmailConversation, message: EmailMessage, placement: EmailMailboxPlacement} */
    private function conversationFixture(string $address, string $receivedAt, array $headers): array
    {
        $uid = ++$this->nextUid;
        $account = EmailAccount::query()->create([
            'address' => $address,
            'description' => 'Received-at repair test account',
            'from_name' => 'Repair Test',
            'account_kind' => EmailAccount::KIND_PERSONAL,
            'owner_id' => $this->actor->id,
            'is_active' => true,
            'is_global_default' => false,
            'defaults_for' => [],
            'ticket_ingress_enabled' => false,
            'delete_policy' => 'local_only',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => $address,
            'imap_secret' => 'test-secret',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => $address,
            'smtp_secret' => 'test-secret',
            'smtp_auth_type' => 'password',
        ]);
        $folder = EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 910,
        ]);
        $message = EmailMessage::query()->create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => $uid,
            'message_id' => '<repair-'.$uid.'@example.test>',
            'subject' => 'Received-at repair source',
            'from_name' => 'Customer',
            'from_email' => 'customer@example.test',
            'to_json' => [['email' => $address]],
            'cc_json' => [],
            'headers_json' => $headers,
            'received_at' => $receivedAt,
            'state' => 'untriaged',
            'body_text' => 'Repair source body.',
            'body_html_sanitized' => '<p>Repair source body.</p>',
            'checksum_sha1' => sha1('repair-'.$uid),
            'attachments_count' => 0,
        ]);
        $conversation = EmailConversation::query()->create([
            'account_id' => $account->id,
            'conversation_key' => 'message:repair-'.$uid.'@example.test',
            'status' => EmailConversation::STATUS_ACTIVE,
            'subject' => $message->subject,
            'first_email_message_id' => $message->id,
            'latest_email_message_id' => $message->id,
            'message_count' => 1,
            'active_placement_count' => 1,
            'provider_unread_count' => 1,
            'has_attachments' => false,
            'first_message_at' => $receivedAt,
            'last_message_at' => $receivedAt,
            'metadata' => ['source' => 'test'],
        ]);
        $placement = EmailMailboxPlacement::query()->create([
            'email_message_id' => $message->id,
            'email_conversation_id' => $conversation->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 910,
            'imap_uid' => $uid,
            'provider_seen' => false,
            'provider_flagged' => false,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
        ]);
        $conversation->forceFill(['latest_email_mailbox_placement_id' => $placement->id])->save();

        return compact('account', 'conversation', 'message', 'placement');
    }

    private function seedRepair(int $messageId, string $observed): EmailMessageReceivedAtRepair
    {
        return EmailMessageReceivedAtRepair::query()->create([
            'email_message_id' => $messageId,
            'observed_received_at' => $observed,
            'status' => EmailMessageReceivedAtRepair::STATUS_PENDING,
            'reason_code' => 'legacy_received_at_on_update_scope',
            'smart_suggestions_recovered' => 0,
        ]);
    }

    /** @param array{account: EmailAccount, conversation: EmailConversation, message: EmailMessage, placement: EmailMailboxPlacement} $fixture */
    private function suggestion(
        array $fixture,
        string $sourceFingerprint,
        int $index,
        string $fingerprintSchema = EmailConversationFingerprint::LEGACY_SCHEMA_VERSION,
    ): EmailSmartInboxSuggestion {
        $proposal = [
            'summary' => 'Suggestion '.$index,
            'key_points' => [],
            'questions' => [],
            'urgency' => 'normal',
            'reply_needed' => false,
            'source_message_ids' => [$fixture['message']->id],
        ];
        $suggestion = EmailSmartInboxSuggestion::query()->create([
            'user_id' => $this->actor->id,
            'account_id' => $fixture['account']->id,
            'email_conversation_id' => $fixture['conversation']->id,
            'selected_email_mailbox_placement_id' => $fixture['placement']->id,
            'effect_type' => EmailSmartInboxSuggestion::EFFECT_REVIEW_SUMMARY,
            'proposal_json' => $proposal,
            'proposal_fingerprint' => app(EmailSmartInboxSuggestionIdentity::class)->checksum($proposal),
            'explanation' => 'Repair regression suggestion.',
            'confidence' => 0.9,
            'source_fingerprint' => $sourceFingerprint,
            'source_fingerprint_schema' => $fingerprintSchema,
            'source_message_ids_json' => [$fixture['message']->id],
            'schema_version' => EmailSmartInboxSuggestion::SCHEMA_VERSION,
            'status' => EmailSmartInboxSuggestion::STATUS_PENDING,
            'idempotency_key' => hash('sha256', 'repair-suggestion-'.$index),
            'generated_at' => now(),
        ]);
        app(EmailSmartInboxSuggestionEventRecorder::class)->record(
            $suggestion,
            EmailSmartInboxSuggestionEvent::TYPE_GENERATED,
            $this->actor,
        );

        return $suggestion;
    }
}
