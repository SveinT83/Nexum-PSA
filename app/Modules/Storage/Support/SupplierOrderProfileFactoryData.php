<?php

namespace App\Modules\Storage\Support;

final class SupplierOrderProfileFactoryData
{
    /** @return array<string, mixed> */
    public static function itegra(): array
    {
        return [
            'schema_version' => SupplierOrderProfileDefinitionValidator::SCHEMA_VERSION,
            'document_type' => 'supplier_order_confirmation',
            'locale' => [
                'language' => 'nb-NO',
                'decimal_separator' => ',',
                'thousands_separators' => [' ', '.', "\u{00A0}", "\u{202F}"],
                'date_formats' => ['Y-m-d', 'd.m.Y', 'd.m.y'],
            ],
            'match' => self::itegraMatchingScope(),
            'defaults' => [
                'warehouse_id' => null,
                'currency' => 'NOK',
                'ordered_date_fallback' => 'received_at',
            ],
            'item_defaults' => [
                'vat_rate' => null,
                'has_serials' => false,
                'track_batch' => false,
                'expiry_enabled' => false,
                'becomes_asset' => false,
                'default_warranty_months' => null,
                'lead_time_days' => 0,
                'moq' => 1,
            ],
            'fields' => [
                'external_order_number' => [
                    'source' => 'label',
                    'type' => 'string',
                    'required' => true,
                    'labels' => ['Ordrenr.', 'Ordrenr:', 'Ordrenummer:', 'Order number:'],
                    'pattern' => '(?<value>[0-9]{6,20})',
                    'value_offset' => 0,
                ],
                'supplier.name' => [
                    'source' => 'fixed',
                    'type' => 'string',
                    'required' => true,
                    'value' => 'Itegra',
                ],
                'ordered_at' => [
                    'source' => 'received_at',
                    'type' => 'date',
                    'required' => true,
                ],
                'currency' => [
                    'source' => 'fixed',
                    'type' => 'currency',
                    'required' => true,
                    'value' => 'NOK',
                ],
                'buyer_reference' => [
                    'source' => 'label',
                    'type' => 'string',
                    'required' => false,
                    'labels' => ['Best. Ref:', 'Best.ref:', 'Bestillerreferanse:'],
                    'value_offset' => 0,
                ],
                'po_reference' => [
                    'source' => 'label',
                    'type' => 'string',
                    'required' => false,
                    'labels' => ['PO. Ref:', 'PO Ref:', 'PO-referanse:'],
                    'value_offset' => 0,
                ],
                'delivery_method' => [
                    'source' => 'label',
                    'type' => 'string',
                    'required' => false,
                    'labels' => ['Levering:', 'Leveringsmåte:'],
                    'value_offset' => 0,
                ],
                'totals.goods_subtotal' => [
                    'source' => 'label',
                    'type' => 'decimal',
                    'required' => true,
                    'labels' => ['Total varer'],
                    'value_offset' => 4,
                ],
                'totals.freight' => [
                    'source' => 'label',
                    'type' => 'decimal',
                    'required' => true,
                    'labels' => ['Frakt'],
                    'value_offset' => 4,
                ],
                'totals.discount' => [
                    'source' => 'label',
                    'type' => 'decimal',
                    'required' => false,
                    'labels' => ['Verdikode'],
                    'value_offset' => 4,
                ],
                'totals.total_ex_tax' => [
                    'source' => 'label',
                    'type' => 'decimal',
                    'required' => true,
                    'labels' => ['Totalt eks. MVA:', 'Totalt eks. mva:', 'Totalt eks MVA:'],
                    'value_offset' => 4,
                ],
            ],
            'lines' => [
                'max_matches' => 500,
                'fields' => [
                    'supplier_sku' => [
                        'capture' => 'supplier_sku',
                        'type' => 'string',
                        'required' => true,
                        'source_column' => 'description',
                        'pattern' => '(?<value>[A-Za-z0-9._-]{1,100})\)?$',
                    ],
                    'description' => [
                        'capture' => 'description',
                        'type' => 'string',
                        'required' => true,
                        'source_column' => 'description',
                        'pattern' => '(?<value>[^\n]{2,500}?)(?:\s+Varenr:|$)',
                    ],
                    'quantity' => [
                        'capture' => 'quantity',
                        'type' => 'integer',
                        'required' => true,
                    ],
                    'line_total' => [
                        'capture' => 'line_total',
                        'type' => 'decimal',
                        'required' => true,
                    ],
                ],
                'repeated_regex' => [
                    'pattern' => '^(?<description>[^\n]{2,500})\nVarenr:\s*\(?(?<supplier_sku>[A-Za-z0-9._-]{1,100})\)?\n(?<quantity>[0-9]{1,7})\n(?<line_total>[0-9 .]{1,30},[0-9]{2})$',
                ],
                'html_table' => [
                    'header_aliases' => [
                        'supplier_sku' => ['Varenr', 'Varenr.', 'Artikkelnummer', 'SKU'],
                        'description' => ['Vare', 'Produkt', 'Beskrivelse'],
                        'quantity' => ['Antall', 'Qty'],
                        'line_total' => ['Total', 'Totalt', 'Beløp'],
                    ],
                    'required_columns' => ['description', 'quantity', 'line_total'],
                ],
            ],
            'validation' => [
                'required_fields' => [
                    'external_order_number',
                    'supplier.name',
                    'ordered_at',
                    'currency',
                    'totals.goods_subtotal',
                    'totals.freight',
                    'totals.total_ex_tax',
                ],
                'amount_tolerance' => 0.02,
                'max_lines' => 500,
                'max_quantity' => 100000,
                'max_order_total' => 100000000,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function itegraMatchingScope(): array
    {
        return [
            'account_ids' => [],
            'mailboxes' => [],
            'recipients' => ['purchasing@example.invalid'],
            'senders' => [],
            'sender_domains' => ['itegra.no'],
            'subject_markers' => [],
            'body_markers' => ['Ordresammendrag:'],
            'authenticated_supplier_domains' => ['itegra.no'],
            'require_trusted_auth' => true,
            'require_aligned' => true,
        ];
    }
}
