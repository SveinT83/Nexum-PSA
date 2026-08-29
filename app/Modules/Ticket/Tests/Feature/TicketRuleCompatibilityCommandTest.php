<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Modules\Ticket\Actions\MutateLegacyTicketRuleCatalog;
use App\Modules\Ticket\Models\TicketRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TicketRuleCompatibilityCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function preflight_command_is_english_bounded_and_read_only(): void
    {
        app(MutateLegacyTicketRuleCatalog::class)->create([
            'name' => 'Read-only preflight rule',
            'trigger' => TicketRule::TRIGGER_CREATE,
            'weight' => 10,
            'is_active' => true,
            'stop_processing' => false,
            'conditions_json' => [],
            'actions_json' => [],
        ]);

        $beforeRule = DB::table('ticket_rules')->first();
        $beforeVersionCount = DB::table('ticket_rule_versions')->count();

        $exitCode = Artisan::call('ticket-rules:compatibility-preflight', ['--limit' => 1]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('"catalog_generation"', $output);
        $this->assertStringContainsString('"catalog_checksum"', $output);
        $this->assertStringContainsString('Read-only preflight complete.', $output);

        $this->assertEquals($beforeRule, DB::table('ticket_rules')->first());
        $this->assertSame($beforeVersionCount, DB::table('ticket_rule_versions')->count());
    }

    #[Test]
    public function backfill_command_requires_explicit_write_confirmation(): void
    {
        $this->artisan('ticket-rules:backfill-compatibility', [
            '--expected-generation' => 0,
            '--expected-checksum' => str_repeat('a', 64),
        ])
            ->expectsOutputToContain('The --confirm-write flag is required.')
            ->assertExitCode(2);

        $this->assertDatabaseCount('ticket_rule_versions', 0);
    }
}
