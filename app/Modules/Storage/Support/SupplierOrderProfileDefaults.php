<?php

namespace App\Modules\Storage\Support;

/** Shared browser/API starter definition; these are examples, not live grants. */
class SupplierOrderProfileDefaults
{
    public function definition(): array
    {
        return [
            'schema_version' => 'storage.supplier_order_profile.v1',
            'document_type' => 'supplier_order_confirmation',
            'locale' => [
                'language' => 'nb-NO',
                'decimal_separator' => ',',
                'thousands_separators' => [' ', '.', "\u{00A0}"],
                'date_formats' => ['Y-m-d', 'd.m.Y'],
            ],
            'match' => [
                'mailboxes' => ['INBOX'],
                'recipients' => ['purchasing@example.no'],
                'senders' => ['orders@supplier.example'],
                'sender_domains' => ['supplier.example'],
                'subject_markers' => ['order confirmation'],
                'body_markers' => ['order number'],
                'authenticated_supplier_domains' => ['supplier.example'],
                'require_trusted_auth' => true,
                'require_aligned' => true,
            ],
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
                    'labels' => ['Order number', 'Ordrenummer'],
                    'value_offset' => 0,
                ],
                'currency' => [
                    'source' => 'fixed',
                    'type' => 'currency',
                    'required' => true,
                    'value' => 'NOK',
                ],
            ],
            'lines' => [
                'max_matches' => 250,
                'fields' => [
                    'supplier_sku' => [
                        'capture' => 'supplier_sku',
                        'type' => 'string',
                        'required' => true,
                        'source_column' => 'supplier_sku',
                    ],
                    'description' => [
                        'capture' => 'description',
                        'type' => 'string',
                        'required' => true,
                        'source_column' => 'description',
                    ],
                    'quantity' => [
                        'capture' => 'quantity',
                        'type' => 'decimal',
                        'required' => true,
                        'source_column' => 'quantity',
                    ],
                    'unit_price' => [
                        'capture' => 'unit_price',
                        'type' => 'decimal',
                        'required' => true,
                        'source_column' => 'unit_price',
                    ],
                    'line_total' => [
                        'capture' => 'line_total',
                        'type' => 'decimal',
                        'required' => true,
                        'source_column' => 'line_total',
                    ],
                ],
                'html_table' => [
                    'header_aliases' => [
                        'supplier_sku' => ['SKU', 'Item number', 'Varenummer'],
                        'description' => ['Description', 'Beskrivelse'],
                        'quantity' => ['Quantity', 'Antall'],
                        'unit_price' => ['Unit price', 'Enhetspris'],
                        'line_total' => ['Line total', 'Linjesum'],
                    ],
                    'required_columns' => ['supplier_sku', 'quantity', 'unit_price'],
                ],
            ],
            'validation' => [
                'required_fields' => ['external_order_number', 'currency'],
                'amount_tolerance' => 0.01,
                'max_lines' => 250,
                'max_quantity' => 10000,
                'max_order_total' => 1000000,
            ],
        ];
    }
}
