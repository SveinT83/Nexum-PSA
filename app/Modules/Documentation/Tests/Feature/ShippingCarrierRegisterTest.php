<?php

namespace App\Modules\Documentation\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Documentation\Controllers\Tech\ShippingCarrierController;
use App\Modules\Documentation\Models\Documentation;
use App\Modules\Documentation\Models\ShippingCarrier;
use App\Modules\Documentation\Support\ShippingTrackingLinkResolver;
use Database\Seeders\ShippingCarrierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ShippingCarrierRegisterTest extends TestCase
{
    use RefreshDatabase;

    private User $viewer;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'documentation.view',
            'documentation.create',
            'documentation.update',
            'documentation.carrier_manage',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Role::findOrCreate('Tech', 'web');

        $this->viewer = $this->user('carrier-viewer@example.test');
        $this->viewer->givePermissionTo('documentation.view');

        $this->manager = $this->user('carrier-manager@example.test');
        $this->manager->givePermissionTo([
            'documentation.view',
            'documentation.carrier_manage',
        ]);
    }

    #[Test]
    public function fixed_carrier_routes_render_the_register_and_sidebar_entry(): void
    {
        $carrier = $this->carrier();
        $route = Route::getRoutes()->getByName('tech.documentations.shipping-carriers.index');

        $this->assertSame(ShippingCarrierController::class.'@index', $route->getActionName());

        $this->actingAs($this->viewer)
            ->get(route('tech.documentations.shipping-carriers.index'))
            ->assertOk()
            ->assertViewIs('documentation::Tech.shipping-carriers.index')
            ->assertSee('Shipping Carriers')
            ->assertSee($carrier->name)
            ->assertSee('Documentation categories')
            ->assertDontSee('New Carrier');

        $this->actingAs($this->viewer)
            ->get(route('tech.documentations.shipping-carriers.show', $carrier))
            ->assertOk()
            ->assertViewIs('documentation::Tech.shipping-carriers.show')
            ->assertSee('Tracking Configuration')
            ->assertSee('track.example.test');
    }

    #[Test]
    public function exact_carrier_manage_permission_is_required_for_mutation_routes(): void
    {
        $genericDocumentationEditor = $this->user('generic-documentation-editor@example.test');
        $genericDocumentationEditor->givePermissionTo([
            'documentation.view',
            'documentation.create',
            'documentation.update',
        ]);
        $carrier = $this->carrier();

        $this->actingAs($genericDocumentationEditor)
            ->get(route('tech.documentations.shipping-carriers.create'))
            ->assertForbidden();
        $this->actingAs($genericDocumentationEditor)
            ->get(route('tech.documentations.shipping-carriers.edit', $carrier))
            ->assertForbidden();

        $this->actingAs($this->manager)
            ->get(route('tech.documentations.shipping-carriers.create'))
            ->assertOk()
            ->assertViewIs('documentation::Tech.shipping-carriers.form')
            ->assertSee('Save Carrier');
        $this->actingAs($this->manager)
            ->get(route('tech.documentations.shipping-carriers.edit', $carrier))
            ->assertOk();
    }

    #[Test]
    public function manager_can_create_and_update_a_structured_carrier_profile(): void
    {
        $response = $this->actingAs($this->manager)
            ->post(route('tech.documentations.shipping-carriers.store'), $this->validPayload([
                'code' => 'north-line',
                'name' => 'North Line',
                'service_tags' => 'parcel, domestic freight',
                'allowed_tracking_hosts' => "tracking.north-line.example\nnorth-line.example",
            ]));

        $carrier = ShippingCarrier::query()->where('code', 'north-line')->firstOrFail();

        $response->assertRedirect(route('tech.documentations.shipping-carriers.show', $carrier));
        $this->assertSame(['parcel', 'domestic', 'freight'], $carrier->service_tags);
        $this->assertSame(['tracking.north-line.example', 'north-line.example'], $carrier->allowed_tracking_hosts);
        $this->assertSame($this->manager->id, $carrier->created_by);
        $this->assertSame($this->manager->id, $carrier->updated_by);
        $this->assertSame(0, Documentation::query()->count());

        $this->actingAs($this->manager)
            ->patch(route('tech.documentations.shipping-carriers.update', $carrier), $this->validPayload([
                'code' => 'north-line',
                'name' => 'North Line Logistics',
                'lifecycle_state' => ShippingCarrier::LIFECYCLE_LEGACY,
            ]))
            ->assertRedirect(route('tech.documentations.shipping-carriers.show', $carrier));

        $carrier->refresh();
        $this->assertSame('North Line Logistics', $carrier->name);
        $this->assertSame(ShippingCarrier::LIFECYCLE_LEGACY, $carrier->lifecycle_state);
        $this->assertSame($this->manager->id, $carrier->updated_by);
    }

    #[Test]
    public function carrier_form_rejects_unsafe_urls_hosts_and_templates(): void
    {
        $route = route('tech.documentations.shipping-carriers.store');

        $this->actingAs($this->manager)
            ->from(route('tech.documentations.shipping-carriers.create'))
            ->post($route, $this->validPayload([
                'website_url' => 'http://north-line.example',
            ]))
            ->assertSessionHasErrors('website_url');

        $this->actingAs($this->manager)
            ->from(route('tech.documentations.shipping-carriers.create'))
            ->post($route, $this->validPayload([
                'tracking_page_url' => 'https://tracking.attacker.example/parcel',
            ]))
            ->assertSessionHasErrors('tracking_page_url');

        foreach ([
            'https://tracking.north-line.example/parcel/no-placeholder',
            'https://tracking.north-line.example/{tracking_number}/{tracking_number}',
            'https://tracking.attacker.example/{tracking_number}',
        ] as $template) {
            $this->actingAs($this->manager)
                ->from(route('tech.documentations.shipping-carriers.create'))
                ->post($route, $this->validPayload([
                    'tracking_method' => ShippingCarrier::TRACKING_TEMPLATE,
                    'tracking_url_template' => $template,
                ]))
                ->assertSessionHasErrors('tracking_url_template');
        }

        $this->assertSame(0, ShippingCarrier::query()->where('code', 'north-line')->count());
    }

    #[Test]
    public function verified_https_template_with_one_placeholder_is_accepted(): void
    {
        $this->actingAs($this->manager)
            ->post(route('tech.documentations.shipping-carriers.store'), $this->validPayload([
                'tracking_method' => ShippingCarrier::TRACKING_TEMPLATE,
                'tracking_url_template' => 'https://tracking.north-line.example/parcel/{tracking_number}',
                'verification_state' => ShippingCarrier::VERIFICATION_VERIFIED,
                'verified_at' => '2026-08-04',
            ]))
            ->assertRedirect();

        $this->assertDatabaseHas('shipping_carriers', [
            'code' => 'north-line',
            'tracking_method' => ShippingCarrier::TRACKING_TEMPLATE,
            'verification_state' => ShippingCarrier::VERIFICATION_VERIFIED,
        ]);
    }

    #[Test]
    public function resolver_prefers_safe_direct_then_verified_template_then_generic_page(): void
    {
        $resolver = app(ShippingTrackingLinkResolver::class);
        $template = 'https://tracking.example.test/parcel/{tracking_number}';
        $page = 'https://tracking.example.test/search';
        $hosts = ['example.test'];

        $this->assertSame(
            'https://tracking.example.test/direct/secret-token',
            $resolver->resolve(
                trackingNumber: 'ABC/123 ?',
                directUrl: 'https://tracking.example.test/direct/secret-token',
                trackingUrlTemplate: $template,
                trackingPageUrl: $page,
                allowedHosts: $hosts,
                configurationVerified: true,
            ),
        );

        $this->assertSame(
            'https://tracking.example.test/parcel/ABC%2F123%20%3F',
            $resolver->resolve(
                trackingNumber: 'ABC/123 ?',
                directUrl: 'https://attacker.example/direct',
                trackingUrlTemplate: $template,
                trackingPageUrl: $page,
                allowedHosts: $hosts,
                configurationVerified: true,
            ),
        );

        $this->assertSame(
            $page,
            $resolver->resolve(
                trackingNumber: 'ABC123',
                trackingUrlTemplate: $template,
                trackingPageUrl: $page,
                allowedHosts: $hosts,
                configurationVerified: false,
            ),
        );
    }

    #[Test]
    public function resolver_never_returns_unsafe_or_non_allowlisted_links(): void
    {
        $resolver = app(ShippingTrackingLinkResolver::class);

        $this->assertNull($resolver->resolve(
            trackingNumber: 'ABC123',
            directUrl: 'http://tracking.example.test/direct',
            trackingUrlTemplate: 'https://attacker.example/{tracking_number}',
            trackingPageUrl: 'https://tracking.example.test:8443/search',
            allowedHosts: ['example.test'],
            configurationVerified: true,
        ));

        $this->assertFalse($resolver->isAllowedUrl(
            'https://example.test@attacker.example/parcel',
            ['example.test'],
        ));
        $this->assertFalse($resolver->isValidTemplate(
            'https://tracking.example.test/{tracking_number}/{other}',
            ['example.test'],
        ));
    }

    #[Test]
    public function seeder_creates_distinct_profiles_and_preserves_administrator_changes(): void
    {
        $this->seed(ShippingCarrierSeeder::class);

        $this->assertSame(16, ShippingCarrier::query()->count());
        $this->assertDatabaseHas('shipping_carriers', [
            'code' => 'posten',
            'name' => 'Posten',
            'tracking_method' => ShippingCarrier::TRACKING_TEMPLATE,
        ]);
        $this->assertDatabaseHas('shipping_carriers', [
            'code' => 'bring',
            'name' => 'Bring',
            'tracking_method' => ShippingCarrier::TRACKING_TEMPLATE,
        ]);
        $this->assertSame(4, ShippingCarrier::query()->where('code', 'like', 'dhl-%')->count());
        $this->assertDatabaseHas('shipping_carriers', [
            'code' => 'dsv',
            'lifecycle_state' => ShippingCarrier::LIFECYCLE_ACTIVE,
        ]);
        $this->assertDatabaseHas('shipping_carriers', [
            'code' => 'db-schenker',
            'lifecycle_state' => ShippingCarrier::LIFECYCLE_LEGACY,
        ]);
        $this->assertDatabaseHas('shipping_carriers', [
            'code' => 'budbee',
            'lifecycle_state' => ShippingCarrier::LIFECYCLE_INACTIVE,
        ]);
        $this->assertDatabaseHas('shipping_carriers', [
            'code' => 'porterbuddy',
            'verification_state' => ShippingCarrier::VERIFICATION_NEEDS_REVIEW,
        ]);

        $posten = ShippingCarrier::query()->where('code', 'posten')->firstOrFail();
        $posten->update([
            'name' => 'Administrator Posten Name',
            'tracking_page_url' => 'https://sporing.posten.no/custom-safe-page',
        ]);

        $this->seed(ShippingCarrierSeeder::class);

        $this->assertSame(16, ShippingCarrier::query()->count());
        $posten->refresh();
        $this->assertSame('Administrator Posten Name', $posten->name);
        $this->assertSame('https://sporing.posten.no/custom-safe-page', $posten->tracking_page_url);
    }

    private function user(string $email): User
    {
        $user = User::query()->create([
            'name' => 'Carrier Test User',
            'email' => $email,
            'password' => Hash::make('password'),
            'status' => User::STATUS_ACTIVE,
        ]);
        $user->assignRole('Tech');

        return $user;
    }

    /** @param array<string, mixed> $overrides */
    private function carrier(array $overrides = []): ShippingCarrier
    {
        return ShippingCarrier::query()->create(array_merge([
            'code' => 'example-carrier',
            'name' => 'Example Carrier',
            'lifecycle_state' => ShippingCarrier::LIFECYCLE_ACTIVE,
            'sort_order' => 100,
            'service_tags' => ['parcel'],
            'website_url' => 'https://example.test',
            'tracking_page_url' => 'https://track.example.test/search',
            'tracking_method' => ShippingCarrier::TRACKING_GENERIC_PAGE,
            'allowed_tracking_hosts' => ['example.test'],
            'link_visibility' => ShippingCarrier::VISIBILITY_NORMAL,
            'source_url' => 'https://example.test/tracking-help',
            'verification_state' => ShippingCarrier::VERIFICATION_VERIFIED,
            'verified_at' => '2026-08-04',
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'north-line',
            'name' => 'North Line',
            'legal_name' => 'North Line Logistics AS',
            'lifecycle_state' => ShippingCarrier::LIFECYCLE_ACTIVE,
            'sort_order' => 100,
            'service_tags' => 'parcel, domestic',
            'website_url' => 'https://north-line.example',
            'support_url' => 'https://north-line.example/support',
            'tracking_page_url' => 'https://tracking.north-line.example/search',
            'tracking_method' => ShippingCarrier::TRACKING_GENERIC_PAGE,
            'tracking_url_template' => null,
            'allowed_tracking_hosts' => "tracking.north-line.example\nnorth-line.example",
            'link_visibility' => ShippingCarrier::VISIBILITY_NORMAL,
            'connector_type' => null,
            'source_url' => 'https://north-line.example/tracking-help',
            'verification_state' => ShippingCarrier::VERIFICATION_UNVERIFIED,
            'verified_at' => null,
            'notes' => 'Test profile.',
        ], $overrides);
    }
}
