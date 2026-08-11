<?php

namespace App\Modules\Storage\Tests\Unit;

use App\Modules\Integration\Services\StrictStructuredJsonValidator;
use App\Modules\Storage\Actions\ExtractSupplierOrderWithAi;
use App\Modules\Storage\Support\StableJson;
use App\Modules\Storage\Support\SupplierOrderSourceEvidenceVerifier;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SupplierOrderSourceEvidenceVerifierTest extends TestCase
{
    #[Test]
    public function supplier_order_ai_response_schema_is_strict_and_provider_compatible(): void
    {
        app(StrictStructuredJsonValidator::class)->assertStrictDataSchema(
            app(ExtractSupplierOrderWithAi::class)->responseSchema(),
        );

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function valid_block_and_table_cell_evidence_is_accepted(): void
    {
        [$document, $source] = $this->groundedDocumentAndSource();

        $errors = app(SupplierOrderSourceEvidenceVerifier::class)->verify(
            $document,
            $source,
            StableJson::checksum($source),
        );

        $this->assertSame([], $errors);
    }

    #[Test]
    public function invented_block_id_is_rejected(): void
    {
        [$document, $source] = $this->groundedDocumentAndSource();
        $document['evidence']['external_order_number']['block_id'] = 'b9999';

        $errors = app(SupplierOrderSourceEvidenceVerifier::class)->verify(
            $document,
            $source,
            StableJson::checksum($source),
        );

        $this->assertContains('source_evidence_anchor_unknown', $this->codes($errors));
    }

    #[Test]
    public function quote_not_present_verbatim_in_the_addressed_block_is_rejected(): void
    {
        [$document, $source] = $this->groundedDocumentAndSource();
        $document['evidence']['external_order_number']['quote'] = 'Order PO-43';

        $errors = app(SupplierOrderSourceEvidenceVerifier::class)->verify(
            $document,
            $source,
            StableJson::checksum($source),
        );

        $this->assertContains('source_evidence_quote_mismatch', $this->codes($errors));
    }

    #[Test]
    public function claimed_value_not_supported_by_a_valid_literal_quote_is_rejected(): void
    {
        [$document, $source] = $this->groundedDocumentAndSource();
        $document['external_order_number'] = 'PO-99';

        $errors = app(SupplierOrderSourceEvidenceVerifier::class)->verify(
            $document,
            $source,
            StableJson::checksum($source),
        );

        $this->assertContains('source_evidence_value_mismatch', $this->codes($errors));
    }

    #[Test]
    public function table_evidence_must_name_the_exact_row_and_column(): void
    {
        [$document, $source] = $this->groundedDocumentAndSource();
        $document['lines'][0]['evidence']['quantity']['column'] = 'Unit';

        $errors = app(SupplierOrderSourceEvidenceVerifier::class)->verify(
            $document,
            $source,
            StableJson::checksum($source),
        );

        $this->assertContains('source_evidence_quote_mismatch', $this->codes($errors));
    }

    #[Test]
    public function changed_source_snapshot_is_rejected_before_logical_evidence_checks(): void
    {
        [$document, $source] = $this->groundedDocumentAndSource();
        $fingerprint = StableJson::checksum($source);
        $source['subject'] = 'Tampered source';

        $errors = app(SupplierOrderSourceEvidenceVerifier::class)->verify(
            $document,
            $source,
            $fingerprint,
        );

        $this->assertSame(['source_snapshot_fingerprint_mismatch'], $this->codes($errors));
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function groundedDocumentAndSource(): array
    {
        $source = [
            'schema_version' => 'storage.supplier_order_source.v1',
            'source' => 'email',
            'subject' => 'Order PO-42 from Supplier AS dated 2026-08-05 in NOK',
            'received_at' => '2026-08-05T10:00:00+02:00',
            'body_text' => '',
            'body_html' => <<<'HTML'
<table>
    <tr><th>SKU</th><th>Description</th><th>Qty</th><th>Unit</th><th>Total</th></tr>
    <tr><td>SKU-1</td><td>Grounded item</td><td>2</td><td>10.50</td><td>21.00</td></tr>
</table>
HTML,
        ];
        $block = fn (string $quote): array => [
            'block_id' => 'b0001',
            'row_id' => null,
            'column' => null,
            'quote' => $quote,
        ];
        $cell = fn (string $column, string $quote): array => [
            'block_id' => 't0001',
            'row_id' => 't0001.r0001',
            'column' => $column,
            'quote' => $quote,
        ];

        return [[
            'schema_version' => 'storage.supplier_order.v1',
            'document_type' => 'supplier_order_confirmation',
            'supplier' => ['name' => 'Supplier AS'],
            'external_order_number' => 'PO-42',
            'ordered_at' => '2026-08-05',
            'ordered_at_provenance' => 'explicit',
            'currency' => 'NOK',
            'buyer_reference' => null,
            'supplier_po_reference' => null,
            'delivery' => ['method' => null, 'address' => null, 'expected_at' => null],
            'lines' => [[
                'source_row_identifier' => 't0001.r0001',
                'supplier_sku' => 'SKU-1',
                'description' => 'Grounded item',
                'quantity' => '2',
                'unit_price' => '10.50',
                'line_total' => '21.00',
                'tax_rate' => null,
                'currency' => null,
                'evidence' => [
                    'supplier_sku' => $cell('SKU', 'SKU-1'),
                    'description' => $cell('Description', 'Grounded item'),
                    'quantity' => $cell('Qty', '2'),
                    'unit_price' => $cell('Unit', '10.50'),
                    'line_total' => $cell('Total', '21.00'),
                    'tax_rate' => null,
                    'currency' => null,
                ],
            ]],
            'totals' => [],
            'evidence' => [
                'supplier' => ['name' => $block('Supplier AS')],
                'external_order_number' => $block('Order PO-42'),
                'ordered_at' => $block('2026-08-05'),
                'currency' => $block('NOK'),
                'buyer_reference' => null,
                'supplier_po_reference' => null,
                'delivery' => ['method' => null, 'address' => null, 'expected_at' => null],
                'totals' => [],
            ],
            'unknown_fields' => [],
        ], $source];
    }

    /** @param list<array{code: string, path: string, message: string}> $errors */
    private function codes(array $errors): array
    {
        return array_values(array_column($errors, 'code'));
    }
}
