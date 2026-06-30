<?php

namespace App\Modules\Documentation\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Core\User;
use App\Modules\Documentation\Controllers\Tech\DocumentationController;
use App\Modules\Documentation\Models\Documentation;
use App\Modules\Documentation\Models\DocumentationTemplate;
use App\Modules\Documentation\Models\Vendor;
use App\Modules\Taxonomy\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
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
        $response->assertSee('<h1>Documentations</h1>', false);
        $response->assertSee('Search');
        $response->assertSee('Context');
        $response->assertSee('New Doc');
        $response->assertSee('Back');
    }

    #[Test]
    public function documentation_index_searches_documents_from_control_card(): void
    {
        $category = Category::query()->create([
            'name' => 'Operations',
            'slug' => 'operations',
            'type' => 'documentation',
            'is_active' => true,
        ]);
        $template = DocumentationTemplate::query()->create([
            'category_id' => $category->id,
            'name' => 'Runbook',
            'fields' => [],
            'is_active' => true,
        ]);
        Documentation::query()->create([
            'template_id' => $template->id,
            'category_id' => $category->id,
            'title' => 'Firewall Runbook',
            'scope_type' => 'internal',
            'template_snapshot_json' => [],
            'data_json' => [],
        ]);
        Documentation::query()->create([
            'template_id' => $template->id,
            'category_id' => $category->id,
            'title' => 'Printer Notes',
            'scope_type' => 'internal',
            'template_snapshot_json' => [],
            'data_json' => [],
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.documentations.index', ['cat' => 'all', 'q' => 'Firewall']))
            ->assertOk()
            ->assertSee('Firewall Runbook')
            ->assertDontSee('Printer Notes')
            ->assertSee('data-href="'.route('tech.documentations.show', Documentation::query()->where('title', 'Firewall Runbook')->first()).'"', false)
            ->assertDontSee('View</a>', false)
            ->assertDontSee('Edit</a>', false);
    }

    #[Test]
    public function authenticated_api_user_can_manage_documentation_records_categories_and_templates(): void
    {
        Sanctum::actingAs($this->tech, ['knowledge.read', 'knowledge.create', 'knowledge.update']);

        $client = Client::factory()->create(['name' => 'API Documentation Client']);
        $site = ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'Main Office']);

        $this->postJson(route('api.v1.knowledge.documentation-categories.store'), [
            'name' => 'Email',
            'slug' => 'email',
            'description' => 'Email documentation.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Email')
            ->assertJsonPath('data.slug', 'email')
            ->assertJsonPath('data.type', 'documentation');

        $category = Category::query()->where('slug', 'email')->firstOrFail();

        $this->postJson(route('api.v1.knowledge.documentation-templates.store'), [
            'category_slug' => 'email',
            'name' => 'Email System',
            'fields' => [
                ['layout' => 'rowStart', 'labelName' => 'Platform'],
                ['Name' => 'platform', 'labelName' => 'Email Platform', 'type' => 'text'],
                ['Name' => 'tenant_id', 'labelName' => 'Tenant ID', 'type' => 'text'],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Email System')
            ->assertJsonPath('data.category.slug', 'email');

        $template = DocumentationTemplate::query()->where('name', 'Email System')->firstOrFail();

        $this->postJson(route('api.v1.knowledge.documentations.store'), [
            'category_slug' => 'email',
            'title' => 'Tronder Service Email',
            'client_id' => $client->id,
            'site_id' => $site->id,
            'data' => [
                'platform' => 'Microsoft 365',
                'tenant_id' => 'tenant-123',
            ],
            'content' => 'SPF, DKIM and DMARC notes.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Tronder Service Email')
            ->assertJsonPath('data.scope_type', 'site')
            ->assertJsonPath('data.category_id', $category->id)
            ->assertJsonPath('data.template_id', $template->id)
            ->assertJsonPath('data.client_id', $client->id)
            ->assertJsonPath('data.site_id', $site->id)
            ->assertJsonPath('data.fields.platform', 'Microsoft 365')
            ->assertJsonPath('data.content', 'SPF, DKIM and DMARC notes.');

        $documentation = Documentation::query()->where('title', 'Tronder Service Email')->firstOrFail();
        $this->assertSame('SPF, DKIM and DMARC notes.', $documentation->data_json['content']);
        $this->assertContains('content', collect($documentation->template_snapshot_json)->pluck('Name')->filter()->all());

        $this->getJson(route('api.v1.knowledge.documentations.index', ['q' => 'Tronder Service']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $documentation->id);

        $this->getJson(route('api.v1.knowledge.documentation-categories.index', ['q' => 'Email']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $category->id);

        $this->getJson(route('api.v1.knowledge.documentation-templates.index', ['category_slug' => 'email']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $template->id);

        $this->patchJson(route('api.v1.knowledge.documentations.update', $documentation), [
            'data' => [
                'tenant_id' => 'tenant-456',
            ],
            'body' => 'Updated mail-flow notes.',
        ])
            ->assertOk()
            ->assertJsonPath('data.fields.platform', 'Microsoft 365')
            ->assertJsonPath('data.fields.tenant_id', 'tenant-456')
            ->assertJsonPath('data.content', 'Updated mail-flow notes.');

        $this->deleteJson(route('api.v1.knowledge.documentations.destroy', $documentation))
            ->assertNoContent();

        $this->assertSoftDeleted('documentations', ['id' => $documentation->id]);
    }

    #[Test]
    public function knowledge_read_api_token_cannot_create_documentation_records(): void
    {
        Sanctum::actingAs($this->tech, ['knowledge.read']);

        $this->postJson(route('api.v1.knowledge.documentation-categories.store'), [
            'name' => 'Blocked Category',
        ])->assertForbidden();
    }

    #[Test]
    public function documentation_api_validates_site_belongs_to_client(): void
    {
        Sanctum::actingAs($this->tech, ['knowledge.create']);

        $category = Category::query()->create([
            'name' => 'LAN',
            'slug' => 'lan',
            'type' => 'documentation',
            'is_active' => true,
        ]);
        DocumentationTemplate::query()->create([
            'category_id' => $category->id,
            'name' => 'LAN Template',
            'fields' => [],
            'is_active' => true,
        ]);
        $client = Client::factory()->create();
        $otherClient = Client::factory()->create();
        $otherSite = ClientSite::factory()->create(['client_id' => $otherClient->id]);

        $this->postJson(route('api.v1.knowledge.documentations.store'), [
            'category_id' => $category->id,
            'title' => 'Mismatched site',
            'client_id' => $client->id,
            'site_id' => $otherSite->id,
            'content' => 'Should fail.',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('site_id');
    }

    #[Test]
    public function vendor_category_opens_fixed_vendor_register_instead_of_template_documents(): void
    {
        $vendor = Vendor::query()->create([
            'name' => 'HP',
            'vendor_code' => 'HP',
            'org_no' => '987654321',
            'url' => 'https://hp.example.test',
            'email' => 'sales@hp.example.test',
            'is_vendor' => true,
            'is_manufacturer' => true,
            'is_supplier' => false,
            'is_active' => true,
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.documentations.index', ['cat' => 'vendors']))
            ->assertOk()
            ->assertViewIs('documentation::Tech.vendors.index')
            ->assertSee('Vendors')
            ->assertSee($vendor->name)
            ->assertSee('Manufacturer')
            ->assertDontSee('New Doc');
    }

    #[Test]
    public function tech_user_can_create_supplier_master_data_from_documentation(): void
    {
        $response = $this->actingAs($this->tech)
            ->post(route('tech.documentations.suppliers.store'), [
                'name' => 'Dustin',
                'vendor_code' => 'DUSTIN',
                'org_no' => '123456789',
                'url' => 'https://dustin.example.test',
                'email' => 'orders@dustin.example.test',
                'phone' => '+47 00 00 00 00',
                'default_lead_time_days' => 3,
                'terms' => 'Net 14',
                'note' => 'Preferred hardware supplier.',
                'is_vendor' => '0',
                'is_manufacturer' => '0',
                'is_supplier' => '1',
                'is_active' => '1',
            ]);

        $supplier = Vendor::query()->where('vendor_code', 'DUSTIN')->firstOrFail();

        $response->assertRedirect(route('tech.documentations.vendors.show', $supplier));

        $this->assertTrue($supplier->is_supplier);
        $this->assertFalse($supplier->is_manufacturer);
        $this->assertSame('Net 14', $supplier->terms);

        $this->actingAs($this->tech)
            ->get(route('tech.documentations.index', ['cat' => 'suppliers']))
            ->assertOk()
            ->assertViewIs('documentation::Tech.vendors.index')
            ->assertSee('Suppliers')
            ->assertSee('Dustin');
    }
}
