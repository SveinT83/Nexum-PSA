<?php

namespace App\Modules\Report\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Report\Controllers\Api\V1\ReportController as ApiReportController;
use App\Modules\Report\Controllers\Tech\ReportController;
use App\Modules\Report\Support\ReportRegistry;
use App\Modules\Ticket\Reports\TicketSlaReportDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
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
        $this->assertSame(
            ApiReportController::class.'@index',
            Route::getRoutes()->getByName('api.v1.reports.index')->getActionName(),
        );
        $this->assertSame(
            ApiReportController::class.'@show',
            Route::getRoutes()->getByName('api.v1.reports.show')->getActionName(),
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

    #[Test]
    public function authenticated_api_user_can_discover_visible_reports(): void
    {
        Permission::findOrCreate('report.view', 'web');
        Role::create(['name' => 'Tech']);

        $tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $tech->assignRole('Tech');
        $tech->givePermissionTo('report.view');

        Sanctum::actingAs($tech, ['report.read']);

        $this->getJson('/api/v1/reports?q=sla')
            ->assertOk()
            ->assertJsonPath('data.0.key', 'ticket.sla')
            ->assertJsonPath('data.0.domain', 'Ticket')
            ->assertJsonPath('data.0.ui_route_name', 'tech.reports.tickets.sla');

        $this->getJson('/api/v1/reports/ticket.sla')
            ->assertOk()
            ->assertJsonPath('data.key', 'ticket.sla')
            ->assertJsonPath('data.title', 'Ticket SLA');
    }

    #[Test]
    public function report_api_hides_reports_without_domain_permission(): void
    {
        Role::create(['name' => 'Tech']);

        $tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $tech->assignRole('Tech');

        Sanctum::actingAs($tech, ['report.read']);

        $this->getJson('/api/v1/reports')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/v1/reports/ticket.sla')
            ->assertNotFound();
    }

    #[Test]
    public function report_api_requires_report_scope(): void
    {
        Permission::findOrCreate('report.view', 'web');
        Role::create(['name' => 'Tech']);

        $tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $tech->assignRole('Tech');
        $tech->givePermissionTo('report.view');

        Sanctum::actingAs($tech, ['tickets.read']);

        $this->getJson('/api/v1/reports')
            ->assertForbidden();
    }
}
