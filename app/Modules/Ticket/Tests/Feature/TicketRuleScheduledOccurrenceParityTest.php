<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Ticket\Actions\BackfillTicketRuleCompatibilityVersions;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Actions\InspectTicketRuleCompatibility;
use App\Modules\Ticket\Actions\MutateLegacyTicketRuleCatalog;
use App\Modules\Ticket\Actions\StoreScheduledTicketOccurrence;
use App\Modules\Ticket\Actions\StoreTicket;
use App\Modules\Ticket\Actions\TicketRuleAutomationActor;
use App\Modules\Ticket\Models\TicketAttachment;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Models\TicketQueue;
use App\Modules\Ticket\Models\TicketRule;
use App\Modules\Ticket\Models\TicketRuleAuthorityFence;
use App\Modules\Ticket\Models\TicketRuleRun;
use App\Modules\Ticket\Models\TicketSchedule;
use App\Modules\Ticket\Models\TicketWorkflowVersion;
use Carbon\Carbon;
use Database\Seeders\SlaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TicketRuleScheduledOccurrenceParityTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $defaults;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SlaSeeder::class);
        $this->defaults = app(EnsureTicketDefaults::class)->handle();
        app(TicketRuleAutomationActor::class)->resolve();
        config()->set('ticket_rules.v2_enabled', false);
        config()->set('ticket_rules.allow_sqlite_mutations_for_tests', false);
        $this->setAuthority(TicketRuleAuthorityFence::AUTHORITY_LEGACY);
    }

    #[Test]
    public function scheduled_occurrence_uses_store_ticket_v2_and_preserves_creator_workflow_sla_and_content(): void
    {
        $targetQueue = TicketQueue::query()->create([
            'name' => 'Scheduled v2 queue',
            'slug' => 'scheduled-v2-queue',
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 85,
        ]);
        $rule = app(MutateLegacyTicketRuleCatalog::class)->create([
            'name' => 'Scheduled occurrence parity',
            'description' => 'Prove scheduled occurrences use the authoritative creation path.',
            'trigger' => TicketRule::TRIGGER_CREATE,
            'weight' => 10,
            'is_active' => true,
            'stop_processing' => false,
            'conditions_json' => [[
                'field' => 'channel',
                'operator' => 'equals',
                'value' => 'scheduled',
            ]],
            'actions_json' => [[
                'type' => 'set_queue',
                'value' => $targetQueue->id,
            ]],
            'created_by' => null,
            'updated_by' => null,
            'hit_count' => 0,
        ]);
        $this->backfillCompatibility();
        config()->set('ticket_rules.v2_enabled', true);
        config()->set('ticket_rules.allow_sqlite_mutations_for_tests', true);
        $this->setAuthority(TicketRuleAuthorityFence::AUTHORITY_V2);
        $creator = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $parent = app(StoreTicket::class)->handle([
            'subject' => 'Weekly infrastructure maintenance',
            'description' => 'Perform the approved maintenance checklist.',
            'channel' => 'manual',
            'owner_id' => null,
            '_skip_initial_description_note' => true,
            '_source_action' => 'TicketRuleScheduledOccurrenceParityTest:parent',
        ], $creator);
        $oldVersion = $parent->workflowVersion()->firstOrFail();
        $workflow = $parent->workflow()->firstOrFail();
        $newVersion = TicketWorkflowVersion::query()->create([
            'ticket_workflow_id' => $workflow->id,
            'version' => ((int) $workflow->versions()->max('version')) + 1,
            'status' => 'published',
            'definition' => $oldVersion->definition,
            'published_by' => $creator->id,
            'published_at' => now(),
        ]);
        $workflow->forceFill(['published_version_id' => $newVersion->id])->save();
        $plannedStart = Carbon::now()->addDays(7)->startOfMinute();
        TicketSchedule::query()->create([
            'ticket_id' => $parent->id,
            'schedule_type' => 'recurring',
            'planned_start_at' => $plannedStart,
            'recurrence_rule' => 'FREQ=WEEKLY',
            'sla_mode' => 'defer_until_planned_start',
            'status' => 'active',
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);
        $internal = TicketMessage::query()->create([
            'ticket_id' => $parent->id,
            'author_id' => $creator->id,
            'author_type' => 'user',
            'type' => 'internal_note',
            'visibility' => 'internal',
            'subject' => 'Checklist',
            'body' => 'Use the internal maintenance checklist.',
            'metadata' => ['template_key' => 'weekly-maintenance'],
        ]);
        TicketMessage::query()->create([
            'ticket_id' => $parent->id,
            'author_id' => $creator->id,
            'author_type' => 'user',
            'type' => 'customer_reply',
            'visibility' => 'customer',
            'subject' => 'Customer-only history',
            'body' => 'This must not be cloned.',
        ]);
        $attachment = TicketAttachment::query()->create([
            'ticket_id' => $parent->id,
            'ticket_message_id' => $internal->id,
            'uploaded_by' => $creator->id,
            'source' => 'upload',
            'filename' => 'checklist.txt',
            'original_filename' => 'checklist.txt',
            'content_type' => 'text/plain',
            'size_bytes' => 42,
            'disk' => 'local',
            'path' => 'ticket-tests/checklist.txt',
            'checksum_sha1' => sha1('checklist'),
        ]);

        $occurrence = app(StoreScheduledTicketOccurrence::class)->handle(
            $parent->refresh(),
            $plannedStart,
        );
        $run = TicketRuleRun::query()->where('ticket_id', $occurrence->id)->firstOrFail();
        $clonedMessage = $occurrence->messages()->firstOrFail();
        $clonedAttachment = $clonedMessage->fileAttachments()->firstOrFail();

        $this->assertSame('scheduled', $occurrence->channel);
        $this->assertSame($parent->id, $occurrence->metadata['parent_ticket_id']);
        $this->assertTrue((bool) $occurrence->metadata['is_occurrence']);
        $this->assertSame($plannedStart->toISOString(), $occurrence->metadata['occurrence_planned_start']);
        $this->assertSame($creator->id, $occurrence->created_by);
        $this->assertSame($oldVersion->id, $occurrence->workflow_version_id);
        $this->assertNotSame($newVersion->id, $occurrence->workflow_version_id);
        $this->assertSame($workflow->id, $occurrence->workflow_id);
        $this->assertSame($targetQueue->id, $occurrence->queue_id);
        $this->assertNull($occurrence->schedule);
        $this->assertNotNull($occurrence->sla_id);
        $this->assertNotNull($occurrence->first_response_due_at);
        $this->assertTrue($occurrence->first_response_due_at->greaterThanOrEqualTo($plannedStart));
        $this->assertSame(1, $occurrence->messages()->count());
        $this->assertSame('internal', $clonedMessage->visibility);
        $this->assertSame($internal->id, $clonedMessage->metadata['cloned_from_message_id']);
        $this->assertSame($internal->body, $clonedMessage->body);
        $this->assertSame('cloned', $clonedAttachment->source);
        $this->assertSame($attachment->path, $clonedAttachment->path);
        $this->assertSame($attachment->checksum_sha1, $clonedAttachment->checksum_sha1);
        $this->assertSame('StoreScheduledTicketOccurrence', $run->source_action);
        $this->assertSame('scheduled', $run->source_channel);
        $this->assertSame($creator->id, $run->initiator_id);
        $this->assertSame(1, $rule->refresh()->hit_count);
        $this->assertDatabaseHas('ticket_events', [
            'ticket_id' => $occurrence->id,
            'type' => 'created',
            'actor_id' => $creator->id,
        ]);
    }

    #[Test]
    public function missing_creator_account_does_not_rewrite_raw_scheduled_creator_evidence(): void
    {
        $creator = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $creatorId = $creator->id;
        $parent = app(StoreTicket::class)->handle([
            'subject' => 'Creator retention parent',
            'description' => null,
            'owner_id' => null,
            'channel' => 'manual',
        ], $creator);
        $plannedStart = Carbon::now()->addDay()->startOfMinute();
        TicketSchedule::query()->create([
            'ticket_id' => $parent->id,
            'schedule_type' => 'recurring',
            'planned_start_at' => $plannedStart,
            'recurrence_rule' => 'FREQ=DAILY',
            'sla_mode' => 'defer_until_planned_start',
            'status' => 'active',
            'created_by' => $creatorId,
            'updated_by' => $creatorId,
        ]);
        $creator->delete();

        $occurrence = app(StoreScheduledTicketOccurrence::class)->handle(
            $parent->refresh(),
            $plannedStart,
        );

        $this->assertSame($creatorId, $occurrence->created_by);
        $this->assertNull($occurrence->updated_by);
        $this->assertSame($parent->id, $occurrence->metadata['parent_ticket_id']);
        $this->assertSame(0, TicketRuleRun::query()->count());
    }

    private function backfillCompatibility(): void
    {
        $preflight = app(InspectTicketRuleCompatibility::class)->handle();
        app(BackfillTicketRuleCompatibilityVersions::class)->handle(
            $preflight['catalog_generation'],
            $preflight['catalog_checksum'],
            'test.issue-231.scheduled',
        );
    }

    private function setAuthority(string $authority): void
    {
        DB::table('ticket_rule_authority_fences')
            ->where('scope', TicketRuleAuthorityFence::SCOPE)
            ->update(['runtime_authority' => $authority]);
    }
}
