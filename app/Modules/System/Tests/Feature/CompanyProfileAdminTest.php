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
    public function admin_can_update_company_profile_and_brand_colors(): void
    {
        $this->actingAs($this->admin)
            ->put(route('tech.admin.system.company-profile.update'), [
                'company_name' => 'Tronder Data',
                'legal_name' => 'Tronder Data AS',
                'organization_number' => '123456789',
                'support_email' => 'support@example.test',
                'website' => 'https://example.test',
                'primary_color' => '#123456',
                'secondary_color' => '#654321',
                'accent_color' => '#20c997',
            ])
            ->assertRedirect();

        $setting = CommonSetting::query()
            ->where('type', 'company_profile')
            ->where('name', 'branding')
            ->firstOrFail();

        $payload = json_decode($setting->json, true);

        $this->assertSame('Tronder Data', $payload['company_name']);
        $this->assertSame('Tronder Data AS', $payload['legal_name']);
        $this->assertSame('#123456', $payload['primary_color']);
        $this->assertSame('Tronder Data', $setting->value);
    }

    #[Test]
    public function admin_can_upload_company_logo(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)
            ->put(route('tech.admin.system.company-profile.update'), [
                'company_name' => 'Tronder Data',
                'primary_color' => '#123456',
                'secondary_color' => '#654321',
                'accent_color' => '#20c997',
                'logo' => UploadedFile::fake()->image('logo.png', 160, 80),
            ])
            ->assertRedirect();

        $setting = CommonSetting::query()
            ->where('type', 'company_profile')
            ->where('name', 'branding')
            ->firstOrFail();

        $payload = json_decode($setting->json, true);

        $this->assertStringStartsWith('company-branding/', $payload['logo_path']);
        Storage::disk('public')->assertExists($payload['logo_path']);
    }
}
