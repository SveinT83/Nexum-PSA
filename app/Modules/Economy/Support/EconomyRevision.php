<?php

namespace App\Modules\Economy\Support;

use App\Modules\Economy\Actions\EnsureEconomyDefaults;
use App\Modules\Economy\Models\EconomyOrder;
use Illuminate\Http\Request;

/** Content plus a monotonic writer revision catches edits even within the same clock second. */
class EconomyRevision
{
    public static function stable(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::stable($item);
            }
        }

        return $value;
    }

    public static function hash(array $parts): string
    {
        return hash('sha256', json_encode(self::stable($parts), JSON_THROW_ON_ERROR));
    }

    public static function settings(): string
    {
        return self::hash([EconomyMutex::revision(), app(EnsureEconomyDefaults::class)->effective()->getAttributes()]);
    }

    public static function order(EconomyOrder $order): string
    {
        $fresh = $order->fresh() ?? $order;

        return self::hash([$fresh->getRawOriginal(), $fresh->lines()->orderBy('id')->get()->map->getRawOriginal()->all(), self::settings()]);
    }

    public static function check(Request $request, string $revision, bool $required = true): void
    {
        $given = $request->header('If-Match');
        abort_if($required && ! $given, 428, 'If-Match is required; read the current revision first.');
        if ($given) {
            abort_unless(hash_equals($revision, trim($given, '"')), 409, 'The record or its dependencies changed; reload and review it.');
        }
    }
}
