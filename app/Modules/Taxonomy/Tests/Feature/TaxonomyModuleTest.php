<?php

namespace App\Modules\Taxonomy\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Taxonomy\Controllers\Admin\CategoryController;
use App\Modules\Taxonomy\Controllers\Admin\TagController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TaxonomyModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Admin']);

        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->admin->assignRole('Admin');
    }

    #[Test]
    public function admin_can_open_category_management_from_taxonomy_module(): void
    {
        $route = Route::getRoutes()->getByName('tech.admin.system.category.index');

        $this->assertSame(CategoryController::class . '@index', $route->getActionName());

        $this->actingAs($this->admin)
            ->get(route('tech.admin.system.category.index'))
            ->assertOk()
            ->assertViewIs('taxonomy::Admin.Category.index')
            ->assertViewHas('categories');
    }

    #[Test]
    public function admin_can_open_tag_management_from_taxonomy_module(): void
    {
        $route = Route::getRoutes()->getByName('tech.admin.system.tag.index');

        $this->assertSame(TagController::class . '@index', $route->getActionName());

        $this->actingAs($this->admin)
            ->get(route('tech.admin.system.tag.index'))
            ->assertOk()
            ->assertViewIs('taxonomy::Admin.Tag.index')
            ->assertViewHas('tags');
    }
}
