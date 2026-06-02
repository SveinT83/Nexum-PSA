<?php

namespace App\Modules\Taxonomy\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Taxonomy\Controllers\Admin\CategoryController;
use App\Modules\Taxonomy\Controllers\Admin\TagController;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
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

    #[Test]
    public function authenticated_api_user_can_manage_categories(): void
    {
        Sanctum::actingAs($this->admin, [
            'taxonomy.read',
            'taxonomy.create',
            'taxonomy.update',
            'taxonomy.delete',
        ]);

        $parent = Category::create([
            'name' => 'Hardware',
            'slug' => 'hardware',
            'type' => 'asset',
            'is_active' => true,
        ]);

        $created = $this->postJson(route('api.v1.taxonomy.categories.store'), [
            'parent_id' => $parent->id,
            'name' => 'Laptop',
            'type' => 'asset',
            'description' => 'Portable computers.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Laptop')
            ->assertJsonPath('data.slug', 'laptop')
            ->assertJsonPath('data.parent.id', $parent->id);

        $categoryId = $created->json('data.id');

        $this->getJson(route('api.v1.taxonomy.categories.index', ['q' => 'lap', 'type' => 'asset']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $categoryId);

        $this->patchJson(route('api.v1.taxonomy.categories.update', $categoryId), [
            'name' => 'Laptop Devices',
            'is_active' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Laptop Devices')
            ->assertJsonPath('data.slug', 'laptop-devices')
            ->assertJsonPath('data.is_active', false);

        $this->deleteJson(route('api.v1.taxonomy.categories.destroy', $categoryId))
            ->assertNoContent();

        $this->assertSoftDeleted('categories', ['id' => $categoryId]);
    }

    #[Test]
    public function taxonomy_category_api_prevents_deleting_categories_in_use(): void
    {
        Sanctum::actingAs($this->admin, ['taxonomy.delete']);

        $parent = Category::create([
            'name' => 'Parent Category',
            'slug' => 'parent-category',
            'type' => 'ticket',
            'is_active' => true,
        ]);
        Category::create([
            'parent_id' => $parent->id,
            'name' => 'Child Category',
            'slug' => 'child-category',
            'type' => 'ticket',
            'is_active' => true,
        ]);

        $this->deleteJson(route('api.v1.taxonomy.categories.destroy', $parent))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category');

        $this->assertDatabaseHas('categories', [
            'id' => $parent->id,
            'deleted_at' => null,
        ]);
    }

    #[Test]
    public function authenticated_api_user_can_manage_tags(): void
    {
        Sanctum::actingAs($this->admin, [
            'taxonomy.read',
            'taxonomy.create',
            'taxonomy.update',
            'taxonomy.delete',
        ]);

        $created = $this->postJson(route('api.v1.taxonomy.tags.store'), [
            'name' => 'VIP Customer',
            'color' => '#f59e0b',
            'icon' => 'star',
            'description' => 'Important customer marker.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'VIP Customer')
            ->assertJsonPath('data.slug', 'vip-customer')
            ->assertJsonPath('data.active', true);

        $tagId = $created->json('data.id');

        $this->getJson(route('api.v1.taxonomy.tags.index', ['q' => 'vip']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $tagId);

        $this->patchJson(route('api.v1.taxonomy.tags.update', $tagId), [
            'name' => 'Priority Customer',
            'active' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Priority Customer')
            ->assertJsonPath('data.slug', 'priority-customer')
            ->assertJsonPath('data.active', false);

        $this->deleteJson(route('api.v1.taxonomy.tags.destroy', $tagId))
            ->assertNoContent();

        $this->assertSoftDeleted('tags', ['id' => $tagId]);
    }

    #[Test]
    public function taxonomy_read_api_token_cannot_write_taxonomy(): void
    {
        Sanctum::actingAs($this->admin, ['taxonomy.read']);

        Tag::create([
            'name' => 'Read Only Tag',
            'slug' => 'read-only-tag',
            'active' => true,
        ]);

        $this->getJson(route('api.v1.taxonomy.tags.index'))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Read Only Tag');

        $this->postJson(route('api.v1.taxonomy.tags.store'), [
            'name' => 'Forbidden Tag',
        ])->assertForbidden();
    }
}
