<?php

namespace App\Modules\Storage\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;

final class SupplierSkuIdentity
{
    public static function normalize(mixed $sku): string
    {
        if (! is_scalar($sku)) {
            return '';
        }

        $sku = preg_replace('/[\x00-\x1F\x7F]/u', '', trim((string) $sku)) ?? '';

        return Str::limit($sku, 255, '');
    }

    public static function claimHash(int $vendorId, string $sku, int $warehouseId): string
    {
        $sku = self::normalize($sku);
        if ($vendorId < 1 || $warehouseId < 1 || $sku === '') {
            throw new InvalidArgumentException('Supplier SKU claim requires supplier, SKU, and warehouse.');
        }

        return StableJson::checksum([
            'vendor_id' => $vendorId,
            'supplier_sku' => $sku,
            'warehouse_id' => $warehouseId,
        ]);
    }
}
