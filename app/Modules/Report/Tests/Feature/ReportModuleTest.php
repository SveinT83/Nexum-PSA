<?php

namespace App\Modules\Report\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Report\Controllers\Tech\ReportController;
use App\Modules\Report\Support\ReportRegistry;
use App\Modules\Ticket\Reports\TicketSlaReportDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportModuleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function report_routes_are_owned_by_report_module(): void
    {
        $this->assertSame(
            ReportController::class.'@index',
            Route::getRoutes()->getByName('tech.reports.index')->getActionName(),
        );
    }

    #[Test]
    public function report_hub_lists_registered_reports_for_allowed_user(): void
    {
        Permission::findOrCreate('report.view', 'web');
        Role::create(['name' => 'Tech']);

        $tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $tech->assignRole('Tech');
        $tech->givePermissionTo('report.view');

        $this->actingAs($tech)
            ->get(route('tech.reports.index'))
            ->assertOk()
            ->assertViewIs('report::Tech.index')
            ->assertSee('Report Library')
            ->assertSee('Documentation / Help')
            ->assertSee('Reports Help')
            ->assertSee('Ticket SLA')
            ->assertSee('Operations')
            ->assertSee(route('tech.reports.tickets.sla'), false)
            ->assertDontSee('Work workspace')
            ->assertDontSee('Report Scope');
    }

    #[Test]
    public function report_registry_discovers_ticket_sla_report_definition(): void
    {
        $registry = new ReportRegistry([TicketSlaReportDefinition::class]);
        $report = $registry->all()->first();

        $this->assertSame('ticket.sla', $report->key);
        $this->assertSame('Ticket', $report->domain);
        $this->assertSame('tech.reports.tickets.sla', $report->routeName);
        $this->assertSame('report.view', $report->permission);
    }
}
