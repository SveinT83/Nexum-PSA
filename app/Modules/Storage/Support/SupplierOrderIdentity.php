<?php

namespace App\Modules\Storage\Support;

use Illuminate\Support\Facades\DB;

/**
 * Builds the immutable cross-channel identity for one supplier order.
 *
 * Leading zeroes, punctuation, and internal whitespace remain significant.
 * Only surrounding spaces and letter case are normalized so unrelated
 * supplier order numbers cannot be merged by a fuzzy comparison.
 */
final class SupplierOrderIdentity
{
    public static function normalize(?string $externalOrderNumber): ?string
    {
        $stored = self::storedReference($externalOrderNumber);
        if ($stored === null) {
            return null;
        }

        $row = DB::selectOne("SELECT NULLIF(UPPER(TRIM(?)), '') AS normalized_reference", [$stored]);
        $normalized = $row?->normalized_reference;

        return $normalized === null ? null : (string) $normalized;
    }

    public static function hash(int $vendorId, ?string $externalOrderNumber): ?string
    {
        $normalized = self::normalize($externalOrderNumber);
        if ($vendorId < 1 || $normalized === null) {
            return null;
        }

        return StableJson::checksum([
            'vendor_id' => $vendorId,
            'external_order_number' => $normalized,
        ]);
    }

    public static function storedReference(?string $externalOrderNumber): ?string
    {
        $trimmed = trim((string) $externalOrderNumber, ' ');

        return $trimmed === '' ? null : $trimmed;
    }
}
