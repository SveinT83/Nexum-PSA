<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Modules\Ticket\Actions\BackfillTicketRuleCompatibilityVersions;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Actions\InspectTicketRuleCompatibility;
use App\Modules\Ticket\Actions\MutateLegacyTicketRuleCatalog;
use App\Modules\Ticket\Models\TicketRule;
use App\Modules\Ticket\Models\TicketRuleAuthorityFence;
use App\Modules\Ticket\Models\TicketRuleVersion;
use App\Modules\Ticket\Services\TicketRuleEngine;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class TicketRuleCompatibilityFoundationTest extends TestCase
{
    use RefreshDatabase;

    private int $queueId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->queueId = (int) app(EnsureTicketDefaults::class)->handle()['queue']->id;
    }

    #[Test]
    public function backfill_is_truthful_idempotent_and_includes_soft_deleted_rules(): void
    {
        $catalog = app(MutateLegacyTicketRuleCatalog::class);
        $active = $catalog->create($this->ruleAttributes([
            'name' => 'Active legacy rule',
            'created_by' => 41,
            'updated_by' => 42,
            'hit_count' => 7,
        ]));
        $deleted = $catalog->create($this->ruleAttributes([
            'name' => 'Deleted legacy rule',
            'is_active' => false,
        ]));
        $catalog->delete($deleted);

        $preflight = app(InspectTicketRuleCompatibility::class)->handle();

        $this->assertSame('ready_for_backfill', $preflight['status']);
        $this->assertSame(2, $preflight['counts']['unversioned']);
        $this->assertFalse($preflight['mapping_complete']);

        $first = app(BackfillTicketRuleCompatibilityVersions::class)->handle(
            $preflight['catalog_generation'],
            $preflight['catalog_checksum'],
            'deploy.issue-231',
        );

        $this->assertSame('legacy', $first['runtime_authority']);
        $this->assertSame(2, $first['counts']['created']);
        $this->assertDatabaseCount('ticket_rule_versions', 2);

        $active->refresh();
        $activeVersion = $active->publishedVersion()->firstOrFail();
        $this->assertSame(TicketRule::COMPATIBILITY_ELIGIBLE, $active->compatibility_status);
        $this->assertSame(TicketRule::LIFECYCLE_PUBLISHED, $active->lifecycle_status);
        $this->assertNull($active->published_by);
        $this->assertNull($active->published_at);
        $this->assertNull($activeVersion->published_by);
        $this->assertNull($activeVersion->published_at);
        $this->assertSame('legacy_backfill', $activeVersion->provenance);
        $this->assertSame('deploy.issue-231', $activeVersion->provenance_key);
        $this->assertSame(41, $activeVersion->source_created_by);
        $this->assertSame(42, $activeVersion->source_updated_by);
        $this->assertSame(7, $activeVersion->source_hit_count);

        $deleted = TicketRule::withTrashed()->findOrFail($deleted->id);
        $deletedVersion = $deleted->publishedVersion()->firstOrFail();
        $this->assertTrue($deleted->trashed());
        $this->assertSame(TicketRule::LIFECYCLE_DELETED, $deleted->lifecycle_status);
        $this->assertNotNull($deletedVersion->source_deleted_at);
        $this->assertFalse(TicketRule::query()->whereKey($deleted->id)->exists());

        $after = app(InspectTicketRuleCompatibility::class)->handle();
        $this->assertSame('compatible', $after['status']);
        $this->assertTrue($after['mapping_complete']);

        $second = app(BackfillTicketRuleCompatibilityVersions::class)->handle(
            $after['catalog_generation'],
            $after['catalog_checksum'],
            null,
        );

        $this->assertSame(0, $second['counts']['created']);
        $this->assertSame(2, $second['counts']['skipped']);
        $this->assertDatabaseCount('ticket_rule_versions', 2);
    }

    #[Test]
    public function invalid_or_ambiguous_rules_remain_active_and_v2_ineligible(): void
    {
        $rule = app(MutateLegacyTicketRuleCatalog::class)->create($this->ruleAttributes([
            'is_active' => true,
            'actions_json' => [[
                'type' => 'run_arbitrary_query',
                'value' => '1',
            ]],
        ]));

        $preflight = app(InspectTicketRuleCompatibility::class)->handle();
        $this->assertSame('blocked', $preflight['status']);
        $this->assertSame(1, $preflight['counts']['ambiguous']);

        app(BackfillTicketRuleCompatibilityVersions::class)->handle(
            $preflight['catalog_generation'],
            $preflight['catalog_checksum'],
        );

        $rule->refresh();
        $this->assertTrue($rule->is_active);
        $this->assertSame(TicketRule::COMPATIBILITY_AMBIGUOUS, $rule->compatibility_status);
        $this->assertSame('unknown_action_type', $rule->compatibility_reason_code);
        $this->assertNull($rule->published_version_id);
        $this->assertDatabaseCount('ticket_rule_versions', 0);
    }

    #[Test]
    public function catalogue_mutation_invalidates_stale_preflight_without_rewriting_version_one(): void
    {
        $catalog = app(MutateLegacyTicketRuleCatalog::class);
        $rule = $catalog->create($this->ruleAttributes());
        $preflight = app(InspectTicketRuleCompatibility::class)->handle();

        $catalog->update($rule, ['weight' => 99]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('changed after preflight');

        app(BackfillTicketRuleCompatibilityVersions::class)->handle(
            $preflight['catalog_generation'],
            $preflight['catalog_checksum'],
        );
    }

    #[Test]
    public function operational_engine_counters_do_not_advance_the_catalogue_fence(): void
    {
        $rule = app(MutateLegacyTicketRuleCatalog::class)->create($this->ruleAttributes([
            'conditions_json' => [],
            'actions_json' => [],
        ]));
        $before = TicketRuleAuthorityFence::query()->findOrFail(TicketRuleAuthorityFence::SCOPE);

        app(TicketRuleEngine::class)->apply(TicketRule::TRIGGER_CREATE, ['channel' => 'manual']);

        $after = TicketRuleAuthorityFence::query()->findOrFail(TicketRuleAuthorityFence::SCOPE);
        $this->assertSame($before->catalog_generation, $after->catalog_generation);
        $this->assertSame($before->catalog_checksum, $after->catalog_checksum);
        $this->assertSame(1, $rule->refresh()->hit_count);
    }

    #[Test]
    public function immutable_version_guards_reject_model_and_raw_updates(): void
    {
        $rule = app(MutateLegacyTicketRuleCatalog::class)->create($this->ruleAttributes());
        $preflight = app(InspectTicketRuleCompatibility::class)->handle();
        app(BackfillTicketRuleCompatibilityVersions::class)->handle(
            $preflight['catalog_generation'],
            $preflight['catalog_checksum'],
        );
        $version = $rule->refresh()->publishedVersion()->firstOrFail();

        try {
            $version->update(['weight' => 900]);
            $this->fail('Model update should have been refused.');
        } catch (LogicException $exception) {
            $this->assertSame('Ticket Rule versions are immutable.', $exception->getMessage());
        }

        $this->expectException(QueryException::class);
        DB::table('ticket_rule_versions')
            ->where('id', $version->id)
            ->update(['weight' => 901]);
    }

    #[Test]
    public function destructive_rollback_is_refused_after_evidence_exists(): void
    {
        app(MutateLegacyTicketRuleCatalog::class)->create($this->ruleAttributes());
        $preflight = app(InspectTicketRuleCompatibility::class)->handle();
        app(BackfillTicketRuleCompatibilityVersions::class)->handle(
            $preflight['catalog_generation'],
            $preflight['catalog_checksum'],
        );

        $migration = require database_path(
            'migrations/2026_08_25_240000_create_ticket_rule_versioning_foundation.php',
        );
        $guard = new ReflectionMethod($migration, 'assertNoVersioningEvidenceWouldBeDeleted');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('immutable versions');

        $guard->invoke($migration);
    }

    #[Test]
    public function foundation_migration_resumes_empty_later_ddl_stages_without_duplicate_guards(): void
    {
        $migration = require database_path(
            'migrations/2026_08_25_240000_create_ticket_rule_versioning_foundation.php',
        );

        // A fully installed but still-empty migration is safe to replay after an interrupted runner.
        $migration->up();

        Schema::drop('ticket_rule_authority_fences');
        $migration->up();

        $this->assertTrue(Schema::hasTable('ticket_rule_versions'));
        $this->assertTrue(Schema::hasTable('ticket_rule_authority_fences'));
        $this->assertDatabaseHas('ticket_rule_authority_fences', [
            'scope' => TicketRuleAuthorityFence::SCOPE,
            'runtime_authority' => TicketRuleAuthorityFence::AUTHORITY_LEGACY,
            'catalog_generation' => 0,
        ]);
        $this->assertTrue(collect(Schema::getForeignKeys('ticket_rules'))
            ->contains(fn (array $foreignKey): bool => ($foreignKey['columns'] ?? []) === ['published_version_id']
                && ($foreignKey['foreign_table'] ?? null) === 'ticket_rule_versions'
            ));

        $this->assertSame(0, TicketRuleVersion::query()->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function ruleAttributes(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Legacy compatibility rule',
            'description' => 'Compatibility test rule.',
            'trigger' => TicketRule::TRIGGER_CREATE,
            'weight' => 10,
            'is_active' => true,
            'stop_processing' => false,
            'conditions_json' => [[
                'field' => 'channel',
                'operator' => 'equals',
                'value' => 'email',
            ]],
            'actions_json' => [[
                'type' => 'set_queue',
                'value' => (string) $this->queueId,
            ]],
            'created_by' => null,
            'updated_by' => null,
            'hit_count' => 0,
        ], $overrides);
    }
}
