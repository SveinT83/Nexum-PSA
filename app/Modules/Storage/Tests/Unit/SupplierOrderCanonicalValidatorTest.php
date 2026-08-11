<?php

namespace App\Modules\Storage\Tests\Unit;

use App\Modules\Storage\Models\PurchaseOrderAutomationPolicy;
use App\Modules\Storage\Support\SupplierOrderCanonicalValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SupplierOrderCanonicalValidatorTest extends TestCase
{
    #[Test]
    public function missing_explicit_total_is_safely_derived_from_complete_lines_and_charges(): void
    {
        $document = $this->document();
        $document['totals'] = [
            'goods_subtotal' => null,
            'freight' => '10.00',
            'discount' => '5.00',
            'other_charges' => '0.00',
            'total_ex_tax' => null,
        ];

        $result = app(SupplierOrderCanonicalValidator::class)->validate(
            $document,
            $this->policy(205),
        );

        $this->assertTrue($result->valid(), json_encode($result->errors));
    }

    #[Test]
    public function line_without_cost_basis_and_unknown_total_fail_closed(): void
    {
        $document = $this->document();
        $document['lines'][0]['unit_price'] = null;
        $document['lines'][0]['line_total'] = null;
        $document['totals'] = [
            'goods_subtotal' => null,
            'freight' => '0.00',
            'discount' => '0.00',
            'other_charges' => '0.00',
            'total_ex_tax' => null,
        ];

        $result = app(SupplierOrderCanonicalValidator::class)->validate($document, $this->policy());

        $this->assertContains('line_amount_basis_missing', $this->codes($result->errors));
        $this->assertContains('order_total_unknown', $this->codes($result->errors));
    }

    #[Test]
    public function derived_total_cannot_bypass_the_order_cap_when_total_ex_tax_is_null(): void
    {
        $document = $this->document();
        $document['lines'][0]['unit_price'] = '600.00';
        $document['lines'][0]['line_total'] = '1200.00';
        $document['totals'] = [
            'goods_subtotal' => null,
            'freight' => '0.00',
            'discount' => '0.00',
            'other_charges' => '0.00',
            'total_ex_tax' => null,
        ];

        $result = app(SupplierOrderCanonicalValidator::class)->validate(
            $document,
            $this->policy(1000),
        );

        $this->assertContains('order_total_limit_exceeded', $this->codes($result->errors));
    }

    #[Test]
    public function explicit_line_currency_must_match_header_currency(): void
    {
        $document = $this->document();
        $document['lines'][0]['currency'] = 'USD';

        $result = app(SupplierOrderCanonicalValidator::class)->validate($document, $this->policy());

        $this->assertContains('line_currency_mismatch', $this->codes($result->errors));
    }

    #[Test]
    public function unknown_iso_looking_header_currency_is_not_accepted(): void
    {
        $document = $this->document();
        $document['currency'] = 'ZZZ';
        $document['lines'][0]['currency'] = 'ZZZ';

        $result = app(SupplierOrderCanonicalValidator::class)->validate($document, $this->policy());

        $this->assertContains('currency_unsupported', $this->codes($result->errors));
    }

    #[Test]
    public function received_date_fallback_requires_and_accepts_the_pinned_source_timestamp(): void
    {
        $document = $this->document();
        $document['ordered_at'] = null;
        $document['ordered_at_provenance'] = 'received_at_fallback';

        $withoutPinnedSource = app(SupplierOrderCanonicalValidator::class)->validate(
            $document,
            $this->policy(),
        );
        $withPinnedSource = app(SupplierOrderCanonicalValidator::class)->validate(
            $document,
            $this->policy(),
            ['received_at' => '2026-08-05T10:00:00+02:00'],
        );

        $this->assertContains(
            'order_date_fallback_source_missing',
            $this->codes($withoutPinnedSource->errors),
        );
        $this->assertTrue($withPinnedSource->valid(), json_encode($withPinnedSource->errors));
    }

    #[Test]
    public function invalid_pinned_received_timestamp_cannot_supply_the_order_date(): void
    {
        $document = $this->document();
        $document['ordered_at'] = null;
        $document['ordered_at_provenance'] = 'received_at_fallback';

        $result = app(SupplierOrderCanonicalValidator::class)->validate(
            $document,
            $this->policy(),
            ['received_at' => 'not-a-date'],
        );

        $this->assertContains('order_date_fallback_source_invalid', $this->codes($result->errors));
    }

    #[Test]
    public function invalid_quantity_cannot_be_used_to_derive_a_missing_line_total(): void
    {
        $document = $this->document();
        $document['lines'][0]['quantity'] = 0;
        $document['lines'][0]['line_total'] = null;
        $document['totals']['goods_subtotal'] = null;
        $document['totals']['total_ex_tax'] = null;

        $result = app(SupplierOrderCanonicalValidator::class)->validate($document, $this->policy());

        $this->assertContains('quantity_invalid', $this->codes($result->errors));
        $this->assertContains('line_amount_basis_missing', $this->codes($result->errors));
        $this->assertContains('order_total_unknown', $this->codes($result->errors));
    }

    private function policy(float $maxOrderTotal = 10000): PurchaseOrderAutomationPolicy
    {
        $policy = new PurchaseOrderAutomationPolicy;
        $policy->forceFill([
            'amount_tolerance' => '0.02',
            'max_lines' => 500,
            'max_quantity_per_line' => 100000,
            'max_order_total' => $maxOrderTotal,
        ]);

        return $policy;
    }

    /** @return array<string, mixed> */
    private function document(): array
    {
        $anchor = ['block_id' => 'b0001'];

        return [
            'schema_version' => 'storage.supplier_order.v1',
            'document_type' => 'supplier_order_confirmation',
            'supplier' => ['name' => 'Supplier AS'],
            'external_order_number' => 'PO-42',
            'ordered_at' => '2026-08-05',
            'ordered_at_provenance' => 'explicit',
            'currency' => 'NOK',
            'lines' => [[
                'supplier_sku' => 'SKU-1',
                'description' => 'Grounded item',
                'quantity' => 2,
                'unit_price' => '100.00',
                'line_total' => '200.00',
                'currency' => 'NOK',
                'evidence' => [
                    'supplier_sku' => $anchor,
                    'description' => $anchor,
                    'quantity' => $anchor,
                ],
            ]],
            'totals' => [
                'goods_subtotal' => '200.00',
                'freight' => '0.00',
                'discount' => '0.00',
                'other_charges' => '0.00',
                'total_ex_tax' => '200.00',
            ],
            'evidence' => [
                'supplier' => ['name' => $anchor],
                'external_order_number' => $anchor,
            ],
        ];
    }

    /** @param list<array{code: string, path: string, message: string}> $errors */
    private function codes(array $errors): array
    {
        return array_values(array_column($errors, 'code'));
    }
}
