<?php

namespace Database\Seeders;

use App\Modules\Documentation\Models\Vendor;
use App\Modules\Storage\Actions\CreateSupplierOrderProfileVersion;
use App\Modules\Storage\Actions\GetCurrentPurchaseOrderAutomationPolicy;
use App\Modules\Storage\Actions\ValidateSupplierOrderProfileVersion;
use App\Modules\Storage\Models\PurchaseOrderImportProfile;
use App\Modules\Storage\Models\PurchaseOrderImportProfileFixture;
use App\Modules\Storage\Support\StableJson;
use App\Modules\Storage\Support\SupplierOrderProfileFactoryData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SupplierOrderProfileLibrarySeeder extends Seeder
{
    public const ITEGRA_PROFILE_SLUG = 'itegra-order-confirmation';

    /**
     * Install safe, editable supplier-profile library entries without replacing local customizations.
     */
    public function run(): void
    {
        // Every installation starts with an explicit immutable fail-closed policy revision.
        app(GetCurrentPurchaseOrderAutomationPolicy::class)->handle();

        if (PurchaseOrderImportProfile::withTrashed()
            ->where('slug', self::ITEGRA_PROFILE_SLUG)
            ->exists()) {
            return;
        }

        DB::transaction(function (): void {
            // Repeat the guard under the transaction so reruns never version or reactivate a local profile.
            if (PurchaseOrderImportProfile::withTrashed()
                ->where('slug', self::ITEGRA_PROFILE_SLUG)
                ->lockForUpdate()
                ->exists()) {
                return;
            }

            $vendor = Vendor::query()->where('name', 'Itegra')->first();
            if ($vendor === null) {
                throw new RuntimeException('Itegra vendor must be seeded before the supplier profile library.');
            }

            $definition = SupplierOrderProfileFactoryData::itegra();
            $profile = PurchaseOrderImportProfile::query()->create([
                'vendor_id' => $vendor->id,
                'name' => 'Itegra order confirmation',
                'slug' => self::ITEGRA_PROFILE_SLUG,
                'description' => 'Editable library profile for authenticated Itegra order confirmations.',
                'lifecycle_state' => PurchaseOrderImportProfile::STATE_DRAFT,
                'priority' => 100,
                'matching_scope' => SupplierOrderProfileFactoryData::itegraMatchingScope(),
                'policy_overrides' => [],
                'health_state' => 'unknown',
                'created_by' => null,
                'updated_by' => null,
            ]);
            $version = app(CreateSupplierOrderProfileVersion::class)->handle(
                profile: $profile,
                definition: $definition,
                source: 'seed_library',
                actor: null,
            );

            $source = $this->itegraSyntheticSource();
            $expected = $this->itegraSyntheticExpectedDocument();
            PurchaseOrderImportProfileFixture::query()->create([
                'profile_id' => $profile->id,
                'profile_version_id' => $version->id,
                'name' => 'Itegra synthetic protected reference',
                'fixture_type' => 'body',
                'is_protected' => true,
                'safe_source_snapshot' => $source,
                'expected_document' => $expected,
                'source_checksum' => StableJson::checksum($source),
                'expected_checksum' => StableJson::checksum($expected),
                'created_by' => null,
            ]);

            $validation = app(ValidateSupplierOrderProfileVersion::class)->handle($version);
            if (! $validation->valid()) {
                throw new RuntimeException('The seeded Itegra profile did not pass its protected fixture replay.');
            }

            // Library profiles remain inactive until an administrator adds installation-specific
            // routing, runs the protected replay again, and explicitly activates a cloned version.
        });
    }

    /** @return array<string, mixed> */
    private function itegraSyntheticSource(): array
    {
        return [
            'schema_version' => 'storage.supplier_order_source.v1',
            'source' => 'email',
            'mailbox' => 'purchasing@example.invalid',
            'subject' => 'Takk for din ordre',
            'from' => ['name' => 'Itegra', 'email' => 'salg@itegra.no'],
            'to' => [['name' => 'Synthetic purchasing', 'email' => 'purchasing@example.invalid']],
            'cc' => [],
            'received_at' => '2026-01-15T10:30:00+01:00',
            'body_html' => '',
            'body_text' => <<<'TEXT'
Hei!

Takk for din ordre.

Ordresammendrag:
Ordrenr.: 9900000001 (Se ordrestatus)
Bestiller: Nexum Testbed
Betaling: Kort
Best. Ref:
PO. Ref:
Levering: Stykkgods NO

Nexum synthetic profile fixture
Varenr: (NX-SYN-1001)
2
100,00

Total varer
Frakt
Verdikode
Totalt eks. MVA:
100,00
25,00
0,00
125,00
TEXT,
            'attachments' => [],
            'trusted_auth' => [
                'authentication_passed' => true,
                'authenticated_supplier_identity' => 'itegra.no',
                'authenticated_supplier_domain' => 'itegra.no',
                'authserv_id' => 'synthetic.example.invalid',
                'spf' => 'pass',
                'dkim' => 'pass',
                'dmarc' => 'pass',
                'aligned' => true,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function itegraSyntheticExpectedDocument(): array
    {
        return [
            'schema_version' => 'storage.supplier_order.v1',
            'document_type' => 'supplier_order_confirmation',
            'external_order_number' => '9900000001',
            'supplier' => ['name' => 'Itegra'],
            'currency' => 'NOK',
            'lines' => [[
                'supplier_sku' => 'NX-SYN-1001',
                'description' => 'Nexum synthetic profile fixture',
                'quantity' => 2,
                'line_total' => '100',
            ]],
            'totals' => [
                'goods_subtotal' => '100',
                'freight' => '25',
                'discount' => '0',
                'total_ex_tax' => '125',
            ],
        ];
    }
}
