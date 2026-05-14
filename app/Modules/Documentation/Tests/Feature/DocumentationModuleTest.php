<?php

namespace App\Modules\Documentation\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Documentation\Controllers\Tech\DocumentationController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Feature coverage for the module-level Documentation route contract.
 */
class DocumentationModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Tech']);

        $this->tech = User::create([
            'name' => 'Documentation Tech',
            'email' => 'documentation-tech@example.test',
            'password' => Hash::make('password'),
            'status' => User::STATUS_ACTIVE,
        ]);
        $this->tech->assignRole('Tech');
    }

    #[Test]
    public function tech_user_can_open_documentation_index_from_module(): void
    {
        $route = Route::getRoutes()->getByName('tech.documentations.index');

        $this->assertSame(DocumentationController::class . '@index', $route->getActionName());

        $response = $this->actingAs($this->tech)
            ->get(route('tech.documentations.index', ['cat' => 'all']));

        $response->assertOk();
        $response->assertViewIs('documentation::Tech.index');
        $response->assertViewHas('documentations');
    }
}
