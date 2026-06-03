<?php

namespace App\Modules\Storage\Support;

use App\Models\Settings\CommonSetting;
use App\Modules\Storage\Models\Warehouse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class StorageInventoryDefaults
{
    public const SETTING_TYPE = 'storage';
    public const SETTING_NAME = 'inventory_defaults';

    public function defaultWarehouse(): Warehouse
    {
        $configured = $this->configuredDefaultWarehouse();

        if ($configured) {
            return $configured;
        }

        $warehouse = Warehouse::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->first();

        if (! $warehouse) {
            $warehouse = $this->createCompanyWarehouse();
        }

        $this->setDefaultWarehouse($warehouse);

        return $warehouse;
    }

    public function configuredDefaultWarehouse(): ?Warehouse
    {
        $warehouseId = (int) Arr::get($this->payload(), 'default_warehouse_id');

        if (! $warehouseId) {
            return null;
        }

        return Warehouse::query()
            ->whereKey($warehouseId)
            ->where('is_active', true)
            ->first();
    }

    public function setDefaultWarehouse(Warehouse $warehouse): void
    {
        CommonSetting::updateOrCreate(
            ['type' => self::SETTING_TYPE, 'name' => self::SETTING_NAME],
            [
                'description' => 'Storage inventory defaults.',
                'json' => json_encode(['default_warehouse_id' => $warehouse->id]),
            ]
        );
    }

    /**
     * Keep default warehouse creation race-safe for deploys where multiple workers
     * may render Storage for the first time on a clean install.
     */
    private function createCompanyWarehouse(): Warehouse
    {
        return DB::transaction(function () {
            return Warehouse::query()->firstOrCreate(
                ['code' => 'COMPANY'],
                [
                    'name' => 'Company Warehouse',
                    'is_active' => true,
                ]
            );
        });
    }

    private function payload(): array
    {
        $json = CommonSetting::query()
            ->where('type', self::SETTING_TYPE)
            ->where('name', self::SETTING_NAME)
            ->value('json');

        $payload = json_decode((string) $json, true);

        return is_array($payload) ? $payload : [];
    }
}
