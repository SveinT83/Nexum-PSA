<?php

namespace App\Modules\DataExchange\Actions;

use App\Models\Core\User;
use App\Modules\DataExchange\Models\DataExchangeProfile;
use App\Modules\DataExchange\Support\DataExchangeSourceRegistry;
use Illuminate\Support\Str;

class EnsureDataExchangeProfileTemplates
{
    public function __construct(private readonly DataExchangeSourceRegistry $sources) {}

    public function handle(?User $actor = null): array
    {
        return [
            'economy_orders_export' => $this->economyOrdersExportProfile($actor),
            'clients_basic_import' => $this->clientsBasicImportProfile($actor),
        ];
    }

    public function economyOrdersExportProfile(?User $actor = null): DataExchangeProfile
    {
        $profile = DataExchangeProfile::query()->firstOrCreate(
            ['key' => 'economy_orders_export'],
            [
                'name' => 'Economy Orders Export',
                'direction' => DataExchangeProfile::DIRECTION_EXPORT,
                'format' => 'csv',
                'status' => DataExchangeProfile::STATUS_ACTIVE,
                'description' => 'Default line-based billing export for Economy orders.',
                'settings' => [
                    'default_order_statuses' => ['ready', 'approved'],
                    'retention_days' => 90,
                ],
                'created_by' => $actor?->id,
                'updated_by' => $actor?->id,
            ],
        );

        $this->ensureSourceAndFields($profile, 'economy.orders', [
            'order.order_number',
            'client.client_number',
            'client.name',
            'client.org_no',
            'order.period_start',
            'order.period_end',
            'order.status',
            'line.work_date',
            'line.line_type',
            'line.description',
            'line.quantity',
            'line.unit',
            'line.unit_price_ex_vat',
            'line.line_total_ex_vat',
            'line.vat_rate',
            'line.vat_amount',
            'line.total_inc_vat',
            'line.currency',
            'ticket.ticket_key',
        ]);

        if (! $profile->filters()->where('field_key', 'order.status')->exists()) {
            $profile->filters()->create([
                'field_key' => 'order.status',
                'operator' => 'in',
                'value' => ['ready', 'approved'],
                'sort_order' => 0,
                'active' => true,
            ]);
        }

        return $profile->refresh();
    }

    public function clientsBasicImportProfile(?User $actor = null): DataExchangeProfile
    {
        $profile = DataExchangeProfile::query()->firstOrCreate(
            ['key' => 'clients_basic_import'],
            [
                'name' => 'Clients Basic Import',
                'direction' => DataExchangeProfile::DIRECTION_IMPORT,
                'format' => 'csv',
                'status' => DataExchangeProfile::STATUS_ACTIVE,
                'description' => 'Safe client import target for basic client identity and billing fields.',
                'settings' => ['retention_days' => 90],
                'created_by' => $actor?->id,
                'updated_by' => $actor?->id,
            ],
        );

        $this->ensureSourceAndFields($profile, 'clients', [
            'client_number',
            'name',
            'org_no',
            'website',
            'billing_email',
            'active',
            'notes',
            'site_name',
            'contact_name',
            'contact_email',
        ], import: true);

        return $profile->refresh();
    }

    /**
     * @param array<int, string> $fieldKeys
     */
    private function ensureSourceAndFields(DataExchangeProfile $profile, string $sourceKey, array $fieldKeys, bool $import = false): void
    {
        $source = $this->sources->get($sourceKey);

        if (! $source) {
            return;
        }

        $profileSource = $profile->sources()->firstOrCreate(
            ['source_key' => $sourceKey],
            [
                'alias' => Str::slug($source->label, '_'),
                'sort_order' => 0,
            ],
        );

        if ($profile->fields()->exists()) {
            return;
        }

        foreach ($fieldKeys as $index => $fieldKey) {
            $field = $source->field($fieldKey);

            if (! $field || $field->blocked || ($import ? ! $field->importable : ! $field->exportable)) {
                continue;
            }

            $profile->fields()->create([
                'profile_source_id' => $profileSource->id,
                'source_key' => $sourceKey,
                'field_key' => $field->key,
                'output_key' => $field->key,
                'label' => $field->label,
                'sort_order' => $index,
                'active' => true,
            ]);

            $profile->mappings()->firstOrCreate(
                [
                    'output_format' => $import ? 'import' : ($profile->format ?: 'csv'),
                    'mapping_key' => $field->key,
                ],
                [
                    'source_expression' => $field->key,
                    'sort_order' => $index,
                    'active' => true,
                ],
            );
        }
    }
}
