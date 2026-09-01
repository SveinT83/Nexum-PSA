<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Actions\BackfillTicketRuleCompatibilityVersions;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Actions\InspectTicketRuleCompatibility;
use App\Modules\Ticket\Actions\MutateLegacyTicketRuleCatalog;
use App\Modules\Ticket\Actions\StoreTicket;
use App\Modules\Ticket\Actions\TicketRuleAutomationActor;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketPriority;
use App\Modules\Ticket\Models\TicketQueue;
use App\Modules\Ticket\Models\TicketRule;
use App\Modules\Ticket\Models\TicketRuleAuthorityFence;
use App\Modules\Ticket\Models\TicketRuleRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Tests\TestCase;

class TicketRuleCreationParityTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $defaults;

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaults = app(EnsureTicketDefaults::class)->handle();
        app(TicketRuleAutomationActor::class)->resolve();
        config()->set('ticket_rules.v2_enabled', false);
        config()->set('ticket_rules.allow_sqlite_mutations_for_tests', false);
        $this->setAuthority(TicketRuleAuthorityFence::AUTHORITY_LEGACY);
    }

    #[Test]
    public function default_off_store_ticket_preserves_legacy_created_rule_result(): void
    {
        $targets = $this->targets('legacy');
        $rule = $this->legacyRule($targets);
        $operator = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $ticket = app(StoreTicket::class)->handle([
            'channel' => 'api',
            'subject' => 'Legacy parity ticket',
            'description' => 'Legacy and v2 should produce the same final fields.',
            'queue_id' => $this->defaults['queue']->id,
            'priority_id' => $this->defaults['priority']->id,
            '_source_action' => 'TicketRuleCreationParityTest:legacy',
        ], $operator);

        $this->assertSame($targets['queue']->id, $ticket->queue_id);
        $this->assertSame($targets['priority']->id, $ticket->priority_id);
        $this->assertSame([$targets['tag']->id], $ticket->tags->pluck('id')->all());
        $this->assertSame($operator->id, $ticket->created_by);
        $this->assertSame($operator->id, $ticket->updated_by);
        $this->assertSame(1, $rule->refresh()->hit_count);
        $this->assertNotNull($rule->last_hit_at);
        $this->assertSame(0, TicketRuleRun::query()->count());
        $this->assertDatabaseHas('ticket_events', [
            'ticket_id' => $ticket->id,
            'actor_id' => $operator->id,
            'type' => 'created',
        ]);
    }

    #[Test]
    public function v2_store_ticket_matches_legacy_final_state_and_runtime_counters(): void
    {
        $targets = $this->targets('v2');
        $rule = $this->legacyRule($targets);
        $this->backfillCompatibility();
        config()->set('ticket_rules.v2_enabled', true);
        config()->set('ticket_rules.allow_sqlite_mutations_for_tests', true);
        $this->setAuthority(TicketRuleAuthorityFence::AUTHORITY_V2);
        $operator = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $ticket = app(StoreTicket::class)->handle([
            'channel' => 'api',
            'subject' => 'V2 parity ticket',
            'description' => 'Legacy and v2 should produce the same final fields.',
            'queue_id' => $this->defaults['queue']->id,
            'priority_id' => $this->defaults['priority']->id,
            '_source_action' => 'TicketRuleCreationParityTest:v2',
        ], $operator);
        $run = TicketRuleRun::query()->where('ticket_id', $ticket->id)->firstOrFail();

        $this->assertSame($targets['queue']->id, $ticket->queue_id);
        $this->assertSame($targets['priority']->id, $ticket->priority_id);
        $this->assertSame([$targets['tag']->id], $ticket->tags->pluck('id')->all());
        $this->assertSame($operator->id, $ticket->created_by);
        $this->assertSame(1, $rule->refresh()->hit_count);
        $this->assertNotNull($rule->last_hit_at);
        $this->assertSame(0, $rule->publishedVersion()->firstOrFail()->source_hit_count);
        // User-visible fields stay in parity while v2 intentionally records
        // the protected automation actor as the final compatibility writer.
        $this->assertSame($run->automation_actor_id, $ticket->updated_by);
        $this->assertSame(TicketRuleRun::STATUS_SUCCEEDED, $run->status);
        $this->assertSame('api', $run->source_channel);
        $this->assertSame('TicketRuleCreationParityTest:v2', $run->source_action);
        $this->assertSame($operator->id, $run->initiator_id);
        $this->assertNotSame($operator->id, $run->automation_actor_id);
        $this->assertDatabaseHas('ticket_events', [
            'ticket_id' => $ticket->id,
            'ticket_rule_run_id' => $run->id,
            'type' => 'automation_run',
        ]);
    }

    #[Test]
    public function v2_authority_with_a_disabled_worker_fails_closed_before_ticket_creation(): void
    {
        $this->setAuthority(TicketRuleAuthorityFence::AUTHORITY_V2);
        config()->set('ticket_rules.v2_enabled', false);
        $before = Ticket::query()->count();

        try {
            app(StoreTicket::class)->handle([
                'subject' => 'Must not be partially created',
                'channel' => 'api',
            ]);
            $this->fail('A stale worker must not silently fall back to the legacy runtime.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'v2 is authoritative',
                $exception->getMessage(),
            );
        }

        $this->assertSame($before, Ticket::query()->count());
        $this->assertSame(0, TicketRuleRun::query()->count());
    }

    #[Test]
    public function production_code_has_one_direct_ticket_insert_boundary(): void
    {
        $appPath = app_path();
        $allowed = str_replace('\\', '/', app_path('Modules/Ticket/Actions/StoreTicket.php'));
        $violations = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($appPath, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());
            if (str_contains($path, '/Tests/')) {
                continue;
            }

            $contents = file_get_contents($path);
            if ($contents === false
                || preg_match('/\bTicket::(?:query\(\)->)?create\s*\(/', $contents) !== 1) {
                continue;
            }

            if ($path !== $allowed) {
                $violations[] = str_replace(str_replace('\\', '/', base_path()).'/', '', $path);
            }
        }

        $this->assertSame([], $violations, 'Unexpected direct Ticket creators: '.implode(', ', $violations));
        $this->assertStringContainsString(
            'Ticket::create(',
            file_get_contents($allowed) ?: '',
        );
    }

    /**
     * @return array{queue: TicketQueue, priority: TicketPriority, tag: Tag}
     */
    private function targets(string $suffix): array
    {
        return [
            'queue' => TicketQueue::query()->create([
                'name' => 'Parity queue '.$suffix,
                'slug' => 'parity-queue-'.$suffix,
                'is_default' => false,
                'is_active' => true,
                'sort_order' => 80,
            ]),
            'priority' => TicketPriority::query()->create([
                'name' => 'Parity priority '.$suffix,
                'slug' => 'parity-priority-'.$suffix,
                'level' => 2,
                'is_default' => false,
                'is_active' => true,
                'sort_order' => 80,
            ]),
            'tag' => Tag::query()->create([
                'name' => 'Parity tag '.$suffix,
                'slug' => 'parity-tag-'.$suffix,
                'active' => true,
            ]),
        ];
    }

    /**
     * @param  array{queue: TicketQueue, priority: TicketPriority, tag: Tag}  $targets
     */
    private function legacyRule(array $targets): TicketRule
    {
        return app(MutateLegacyTicketRuleCatalog::class)->create([
            'name' => 'Creation parity rule',
            'description' => 'Representative compatibility action order.',
            'trigger' => TicketRule::TRIGGER_CREATE,
            'weight' => 10,
            'is_active' => true,
            'stop_processing' => false,
            'conditions_json' => [[
                'field' => 'subject',
                'operator' => 'contains',
                'value' => '',
            ]],
            'actions_json' => [
                ['type' => 'set_queue', 'value' => $targets['queue']->id],
                ['type' => 'set_priority', 'value' => $targets['priority']->id],
                ['type' => 'add_tag', 'value' => $targets['tag']->id],
            ],
            'created_by' => null,
            'updated_by' => null,
            'hit_count' => 0,
        ]);
    }

    private function backfillCompatibility(): void
    {
        $preflight = app(InspectTicketRuleCompatibility::class)->handle();
        $this->assertSame('ready_for_backfill', $preflight['status']);

        app(BackfillTicketRuleCompatibilityVersions::class)->handle(
            $preflight['catalog_generation'],
            $preflight['catalog_checksum'],
            'test.issue-231.slice-2',
        );
    }

    private function setAuthority(string $authority): void
    {
        DB::table('ticket_rule_authority_fences')
            ->where('scope', TicketRuleAuthorityFence::SCOPE)
            ->update(['runtime_authority' => $authority]);
    }
}
