<?php

namespace App\Modules\System\Tests\Feature;

use App\Models\Core\User;
use App\Modules\System\Controllers\Admin\QueueWorkerController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class QueueWorkerAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Admin']);
        Role::create(['name' => 'Tech']);

        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->admin->assignRole('Admin');
    }

    #[Test]
    public function queue_worker_routes_are_owned_by_system_module(): void
    {
        $this->assertSame(
            QueueWorkerController::class . '@index',
            Route::getRoutes()->getByName('tech.admin.system.queues-workers.index')->getActionName()
        );
    }

    #[Test]
    public function admin_can_open_queue_worker_operations_page(): void
    {
        $this->actingAs($this->admin)
            ->get(route('tech.admin.system.queues-workers.index'))
            ->assertOk()
            ->assertViewIs('system::Admin.queues-workers')
            ->assertSee('Queues and Workers')
            ->assertSee('Scheduler cron')
            ->assertSee('Supervisor example');
    }
}
