<?php

namespace App\Modules\Storage\Tests\Feature;

use App\Modules\Documentation\Models\Vendor;
use App\Modules\Storage\Models\PurchaseOrderAutomationPolicy;
use App\Modules\Storage\Models\PurchaseOrderAutomationPolicyRevision;
use App\Modules\Storage\Models\PurchaseOrderImportProfile;
use App\Modules\Storage\Models\PurchaseOrderImportProfileFixture;
use App\Modules\Storage\Models\PurchaseOrderImportProfileVersion;
use App\Modules\Storage\Support\SupplierOrderDeterministicExtractor;
use Database\Seeders\SupplierOrderProfileLibrarySeeder;
use Database\Seeders\VendorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SupplierOrderProfileLibrarySeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    #[Test]
    public function it_installs_a_replayed_inactive_itegra_draft_once_without_selecting_a_user(): void
    {

        $this->seed(VendorSeeder::class);
        $this->seed(SupplierOrderProfileLibrarySeeder::class);

        $policy = PurchaseOrderAutomationPolicy::query()->where('is_current', true)->sole();
        $this->assertSame(PurchaseOrderAutomationPolicy::MODE_OFF, $policy->runtime_mode);
        $this->assertSame('off', $policy->ai_mode);
        $this->assertNull($policy->automation_user_id);
        $revision = PurchaseOrderAutomationPolicyRevision::query()->sole();
        $this->assertSame($policy->id, $revision->policy_id);
        $this->assertSame('Initial fail-closed policy.', $revision->reason);

        $vendor = Vendor::query()->where('name', 'Itegra')->sole();
        $this->assertSame('salg@itegra.no', $vendor->email);
        $this->assertSame('https://www.itegra.no', $vendor->url);
        $this->assertTrue($vendor->is_vendor);
        $this->assertTrue($vendor->is_supplier);
        $this->assertTrue($vendor->is_active);

        $profile = PurchaseOrderImportProfile::query()
            ->where('slug', SupplierOrderProfileLibrarySeeder::ITEGRA_PROFILE_SLUG)
            ->with(['activeVersion', 'versions', 'fixtures'])
            ->sole();
        $version = $profile->versions->sole();
        $fixture = $profile->fixtures->sole();

        $this->assertSame($vendor->id, $profile->vendor_id);
        $this->assertSame(PurchaseOrderImportProfile::STATE_DRAFT, $profile->lifecycle_state);
        $this->assertSame('unknown', $profile->health_state);
        $this->assertNull($profile->activeVersion);
        $this->assertSame(PurchaseOrderImportProfileVersion::STATUS_VALIDATED, $version->status);
        $this->assertNotNull($version->validated_at);
        $this->assertNull($profile->created_by);
        $this->assertNull($profile->updated_by);
        $this->assertNull($version->created_by);
        $this->assertNull($version->activated_by);
        $this->assertNull($version->activated_at);
        $this->assertTrue($fixture->is_protected);
        $this->assertNull($fixture->created_by);
        $this->assertSame('passed', $fixture->last_result);
        $this->assertNotNull($fixture->last_tested_at);
        $this->assertSame(1, data_get($version->test_metrics, 'protected_total'));
        $this->assertSame(1, data_get($version->test_metrics, 'protected_passed'));
        $this->assertArrayNotHasKey('activation_protected_passed', $version->test_metrics);

        $extraction = app(SupplierOrderDeterministicExtractor::class)->extract(
            $version,
            (array) $fixture->safe_source_snapshot,
        );
        $this->assertTrue($extraction->valid(), json_encode($extraction->errors));
        $this->assertSame('9900000001', data_get($extraction->document, 'external_order_number'));
        $this->assertSame('NX-SYN-1001', data_get($extraction->document, 'lines.0.supplier_sku'));
        $this->assertSame(2, data_get($extraction->document, 'lines.0.quantity'));
        $this->assertSame('125', data_get($extraction->document, 'totals.total_ex_tax'));

        $profile->update([
            'name' => 'Locally customized Itegra profile',
            'policy_overrides' => ['automation_mode' => 'review'],
        ]);
        $originalVersionChecksum = $version->checksum;
        $this->seed(SupplierOrderProfileLibrarySeeder::class);

        $profile->refresh();
        $this->assertSame('Locally customized Itegra profile', $profile->name);
        $this->assertSame(['automation_mode' => 'review'], $profile->policy_overrides);
        $this->assertSame(1, PurchaseOrderImportProfile::withTrashed()->count());
        $this->assertSame(1, PurchaseOrderImportProfileVersion::query()->count());
        $this->assertSame(1, PurchaseOrderImportProfileFixture::query()->count());
        $this->assertSame($originalVersionChecksum, $profile->versions()->sole()->checksum);
        $this->assertSame(1, PurchaseOrderAutomationPolicy::query()->where('is_current', true)->count());
        $this->assertSame(1, PurchaseOrderAutomationPolicyRevision::query()->count());
        Http::assertNothingSent();
    }
}
