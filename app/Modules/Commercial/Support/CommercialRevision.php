<?php

namespace App\Modules\Commercial\Support;

use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Commercial\Models\Packages\Package;
use App\Modules\Commercial\Models\Services\Services;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/** Content hashes include dependent snapshots; second-resolution timestamps alone are insufficient. */
class CommercialRevision
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

    public static function of(Model $record): string
    {
        // Include database defaults omitted from a just-created model's original attributes.
        $record = $record->fresh() ?? $record;

        $parts = [$record->getTable(), $record->getRawOriginal()];
        if ($record instanceof Contracts) {
            $fresh = $record->load(['items.timeRates', 'items.service.serviceTerms', 'termSnapshots.termVersion', 'client', 'sla']);
            $parts[] = $fresh->toArray();
        } elseif ($record instanceof Services) {
            $parts[] = $record->load(['serviceTerms', 'serviceTimeRates', 'costRelations.cost'])->toArray();
        } elseif ($record instanceof Package) {
            $parts[] = $record->load(['services', 'terms'])->toArray();
        }

        return hash('sha256', json_encode(self::stable($parts), JSON_THROW_ON_ERROR));
    }

    public static function check(Request $request, Model $record, bool $required = true): void
    {
        $given = $request->header('If-Match');
        abort_if($required && ! $given, 428, 'If-Match is required; read the current revision first.');
        if ($given) {
            abort_unless(hash_equals(self::of($record), trim($given, '"')), 409, 'The record changed; reload and review it.');
        }
    }
}
