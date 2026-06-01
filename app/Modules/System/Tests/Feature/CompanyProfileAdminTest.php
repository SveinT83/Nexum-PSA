<?php

namespace App\Modules\System\Tests\Feature;

use App\Models\Core\User;
use App\Models\Settings\CommonSetting;
use App\Modules\System\Controllers\Admin\CompanyProfileController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyProfileAdminTest extends TestCase
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
    public function company_profile_routes_are_owned_by_system_module(): void
    {
        $this->assertSame(
            CompanyProfileController::class . '@edit',
            Route::getRoutes()->getByName('tech.admin.system.company-profile.edit')->getActionName()
        );

        $this->assertSame(
            CompanyProfileController::class . '@editBranding',
            Route::getRoutes()->getByName('tech.admin.system.branding.edit')->getActionName()
        );

        $this->assertSame(
            CompanyProfileController::class . '@resetBranding',
            Route::getRoutes()->getByName('tech.admin.system.branding.reset')->getActionName()
        );
    }

    #[Test]
    public function admin_can_open_company_profile_page(): void
    {
        $this->actingAs($this->admin)
            ->get(route('tech.admin.system.company-profile.edit'))
            ->assertOk()
            ->assertViewIs('system::Admin.CompanyProfile.edit')
            ->assertSee('Company Profile')
            ->assertSee('Branding');
    }

    #[Test]
    public function admin_can_open_branding_page(): void
    {
        $this->actingAs($this->admin)
            ->get(route('tech.admin.system.branding.edit'))
            ->assertOk()
            ->assertViewIs('system::Admin.Branding.edit')
            ->assertSee('Brand Colors')
            ->assertSee('Theme Surfaces')
            ->assertSee('Live Preview')
            ->assertSee('Light mode logo')
            ->assertSee('Header background')
            ->assertSee('Primary button background')
            ->assertSee('Reset to default');
    }

    #[Test]
    public function admin_hub_exposes_existing_settings_surfaces(): void
    {
        $this->actingAs($this->admin)
            ->get(route('tech.admin.index'))
            ->assertOk()
            ->assertSee('Calendar settings')
            ->assertSee(route('tech.admin.settings.calendar'), false)
            ->assertSee('Asset settings')
            ->assertSee(route('tech.admin.settings.assets'), false)
            ->assertSee('Contact settings')
            ->assertSee(route('tech.admin.settings.contacts'), false)
            ->assertSee('Notification channels')
            ->assertSee(route('tech.admin.notification-channels.index'), false)
            ->assertSee('Technicians')
            ->assertSee(route('tech.admin.settings.tickets.technicians'), false)
            ->assertSee('Assignment rules')
            ->assertSee(route('tech.admin.settings.tickets.assignment-rules'), false)
            ->assertSee('Roles')
            ->assertSee(route('tech.admin.user_management.roles.index'), false)
            ->assertSee('Permissions')
            ->assertSee(route('tech.admin.user_management.permissions.index'), false)
            ->assertSee('Two-factor auth')
            ->assertSee(route('tech.admin.user_management.2fa-settings'), false)
            ->assertSee('N-able RMM')
            ->assertSee(route('tech.admin.system.integrations.nable_rmm.settings'), false)
            ->assertSee('Tactical RMM')
            ->assertSee(route('tech.admin.system.integrations.tactical_rmm.settings'), false)
            ->assertSee('BookStack')
            ->assertSee(route('tech.admin.system.integrations.book_stack.settings'), false)
            ->assertSee('Nextcloud')
            ->assertSee(route('tech.admin.nextcloud.connections.index'), false)
            ->assertSee('AI settings')
            ->assertSee(route('tech.admin.system.integrations.ai.index'), false);
    }

    #[Test]
    public function admin_can_update_company_profile(): void
    {
        $this->actingAs($this->admin)
            ->put(route('tech.admin.system.company-profile.update'), [
                'company_name' => 'Tronder Data',
                'legal_name' => 'Tronder Data AS',
                'organization_number' => '123456789',
                'support_email' => 'support@example.test',
                'website' => 'https://example.test',
            ])
            ->assertRedirect();

        $setting = CommonSetting::query()
            ->where('type', 'company_profile')
            ->where('name', 'branding')
            ->firstOrFail();

        $payload = json_decode($setting->json, true);

        $this->assertSame('Tronder Data', $payload['company_name']);
        $this->assertSame('Tronder Data AS', $payload['legal_name']);
        $this->assertSame('Tronder Data', $setting->value);
    }

    #[Test]
    public function admin_can_update_brand_colors_from_branding_page(): void
    {
        $this->actingAs($this->admin)
            ->put(route('tech.admin.system.branding.update'), $this->brandingPayload([
                'primary_color' => '#123456',
                'secondary_color' => '#654321',
                'accent_color' => '#20c997',
                'light_header_background' => '#101010',
                'dark_header_background' => '#202020',
                'light_primary_button_background' => '#303030',
            ]))
            ->assertRedirect();

        $setting = CommonSetting::query()
            ->where('type', 'company_profile')
            ->where('name', 'branding')
            ->firstOrFail();

        $payload = json_decode($setting->json, true);

        $this->assertSame('#123456', $payload['primary_color']);
        $this->assertSame('#654321', $payload['secondary_color']);
        $this->assertSame('#20c997', $payload['accent_color']);
        $this->assertSame('#101010', $payload['light_header_background']);
        $this->assertSame('#202020', $payload['dark_header_background']);
        $this->assertSame('#303030', $payload['light_primary_button_background']);
    }

    #[Test]
    public function tech_layout_applies_company_branding_as_bootstrap_theme_variables(): void
    {
        CommonSetting::query()->create([
            'type' => 'company_profile',
            'name' => 'branding',
            'value' => 'Tronder Data',
            'json' => json_encode([
                'company_name' => 'Tronder Data',
                'primary_color' => '#123456',
                'secondary_color' => '#654321',
                'accent_color' => '#abcdef',
            ]),
        ]);

        $html = $this->actingAs($this->admin)
            ->get(route('tech.admin.system.branding.edit'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('--nexum-brand-primary: #123456;', $html);
        $this->assertStringContainsString('--nexum-brand-secondary: #654321;', $html);
        $this->assertStringContainsString('--nexum-brand-accent: #abcdef;', $html);
        $this->assertStringContainsString('--nexum-header-bg: #333333;', $html);
        $this->assertStringContainsString('--nexum-primary-button-bg: #FF6D1F;', $html);
        $this->assertStringContainsString('--bs-primary: #123456;', $html);
        $this->assertStringContainsString('--bs-primary-rgb: 18, 52, 86;', $html);
    }

    #[Test]
    public function admin_can_upload_company_logo(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)
            ->put(route('tech.admin.system.branding.update'), $this->brandingPayload([
                'primary_color' => '#123456',
                'secondary_color' => '#654321',
                'accent_color' => '#20c997',
                'logo' => UploadedFile::fake()->image('logo.png', 160, 80),
                'logo_light' => UploadedFile::fake()->image('logo-light.png', 160, 80),
                'logo_dark' => UploadedFile::fake()->image('logo-dark.png', 160, 80),
            ]))
            ->assertRedirect();

        $setting = CommonSetting::query()
            ->where('type', 'company_profile')
            ->where('name', 'branding')
            ->firstOrFail();

        $payload = json_decode($setting->json, true);

        $this->assertStringStartsWith('company-branding/', $payload['logo_path']);
        $this->assertStringStartsWith('company-branding/', $payload['logo_light_path']);
        $this->assertStringStartsWith('company-branding/', $payload['logo_dark_path']);
        Storage::disk('public')->assertExists($payload['logo_path']);
        Storage::disk('public')->assertExists($payload['logo_light_path']);
        Storage::disk('public')->assertExists($payload['logo_dark_path']);
    }

    #[Test]
    public function admin_can_reset_branding_to_defaults_without_clearing_company_profile(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)
            ->put(route('tech.admin.system.branding.update'), $this->brandingPayload([
                'primary_color' => '#123456',
                'light_header_background' => '#101010',
                'logo' => UploadedFile::fake()->image('logo.png', 160, 80),
            ]))
            ->assertRedirect();

        $this->actingAs($this->admin)
            ->put(route('tech.admin.system.company-profile.update'), [
                'company_name' => 'Tronder Data',
                'legal_name' => 'Tronder Data AS',
            ])
            ->assertRedirect();

        $setting = CommonSetting::query()
            ->where('type', 'company_profile')
            ->where('name', 'branding')
            ->firstOrFail();
        $beforeReset = json_decode($setting->json, true);

        Storage::disk('public')->assertExists($beforeReset['logo_path']);

        $this->actingAs($this->admin)
            ->put(route('tech.admin.system.branding.reset'))
            ->assertRedirect()
            ->assertSessionHas('success', 'Branding was reset to defaults.');

        $payload = json_decode($setting->fresh()->json, true);

        $this->assertSame('Tronder Data', $payload['company_name']);
        $this->assertSame('Tronder Data AS', $payload['legal_name']);
        $this->assertSame('#FF6D1F', $payload['primary_color']);
        $this->assertSame('#fc7730', $payload['secondary_color']);
        $this->assertSame('#faba98', $payload['accent_color']);
        $this->assertSame('#333333', $payload['light_header_background']);
        $this->assertNull($payload['logo_path']);
        Storage::disk('public')->assertMissing($beforeReset['logo_path']);
    }

    private function brandingPayload(array $overrides = []): array
    {
        return array_merge([
            'primary_color' => '#FF6D1F',
            'secondary_color' => '#fc7730',
            'accent_color' => '#faba98',
            'light_header_background' => '#333333',
            'light_header_color' => '#ffffff',
            'light_footer_background' => '#333333',
            'light_footer_color' => '#ffffff',
            'light_left_sidebar_background' => '#f8f9fa',
            'light_left_sidebar_color' => '#212529',
            'light_main_background' => '#d1d1d1',
            'light_right_sidebar_background' => '#f8f9fa',
            'light_right_sidebar_color' => '#212529',
            'light_page_header_background' => '#5c5c5c',
            'light_page_header_color' => '#ffffff',
            'light_card_header_background' => '#f8f9fa',
            'light_card_header_color' => '#212529',
            'light_content_background' => '#ffffff',
            'light_primary_button_background' => '#FF6D1F',
            'light_primary_button_color' => '#ffffff',
            'light_secondary_button_background' => '#fc7730',
            'light_secondary_button_color' => '#ffffff',
            'dark_header_background' => '#111827',
            'dark_header_color' => '#f8fafc',
            'dark_footer_background' => '#111827',
            'dark_footer_color' => '#f8fafc',
            'dark_left_sidebar_background' => '#1f2937',
            'dark_left_sidebar_color' => '#f8fafc',
            'dark_main_background' => '#0f172a',
            'dark_right_sidebar_background' => '#1f2937',
            'dark_right_sidebar_color' => '#f8fafc',
            'dark_page_header_background' => '#1f2937',
            'dark_page_header_color' => '#f8fafc',
            'dark_card_header_background' => '#111827',
            'dark_card_header_color' => '#f8fafc',
            'dark_content_background' => '#111827',
            'dark_primary_button_background' => '#FF6D1F',
            'dark_primary_button_color' => '#ffffff',
            'dark_secondary_button_background' => '#fc7730',
            'dark_secondary_button_color' => '#ffffff',
        ], $overrides);
    }
}
