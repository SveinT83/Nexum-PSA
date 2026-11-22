<?php

namespace App\Modules\Workday\Tests\Feature;

use App\Models\Core\User;
use App\Models\Settings\CommonSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Directory selection is independent of time rows and retains explicit oversight authority. */
class EmployeeSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_staff_are_reachable_on_focus_with_unicode_search_and_no_day_records(): void
    {
        config(['workday.enabled' => true]);
        CommonSetting::where('type', 'workday')->where('name', 'manual_workflow')->update(['json' => json_encode(['enabled' => true, 'retention_years' => 3, 'version' => 0])]);
        $role = Role::findOrCreate('Tech', 'web');
        $role->givePermissionTo('workday.view_all');
        $viewer = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $viewer->assignRole($role);
        for ($i = 0; $i < 53; $i++) {
            $u = User::factory()->create(['name' => sprintf('Employee %03d', $i)]);
            $u->assignRole($role);
        }
        $unicode = User::factory()->create(['name' => 'Øystein Åsen', 'status' => User::STATUS_DISABLED]);
        $unicode->assignRole($role);
        User::factory()->create(['name' => 'Portal excluded']);
        $this->withToken($viewer->createToken('Synthetic directory test', ['workdays.read-all'])->plainTextToken);
        $this->getJson('/api/v1/workdays/overview/employees')->assertOk()->assertJsonCount(50, 'data')
            ->assertJsonPath('meta.next_page', 2)->assertJsonPath('meta.total', 55);
        $this->getJson('/api/v1/workdays/overview/employees?page=2')->assertOk()->assertJsonCount(5, 'data');
        $this->getJson('/api/v1/workdays/overview/employees?q=øystein ås')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $unicode->id)->assertDontSee($unicode->email)->assertDontSee('Portal excluded');
        $this->withoutHeader('Authorization');
        $this->actingAs($viewer, 'web')->get(route('tech.workdays.overview'))->assertOk()
            ->assertSee('role="combobox"', false)->assertSee('name="worker_id"', false);
        $role->revokePermissionTo('workday.view_all');
        $viewer->unsetRelation('roles')->unsetRelation('permissions');
        $this->getJson('/api/v1/workdays/overview/employees')->assertForbidden();
    }
}
