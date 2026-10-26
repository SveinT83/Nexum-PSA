<?php

namespace App\Modules\Risk\Support;

use App\Models\Risk\RiskAssessment;
use App\Models\Risk\RiskItem;
use App\Models\Risk\RiskItemUpdate;
use Illuminate\Http\Request;

final class RiskRevision
{
    public static function stable(mixed $v): mixed
    {
        if (! is_array($v)) {
            return $v;
        }
        if (! array_is_list($v)) {
            ksort($v);
        }

        return array_map([self::class, 'stable'], $v);
    }

    public static function of(mixed $record): string
    {
        if ($record instanceof RiskAssessment) {
            $fresh = RiskAssessment::query()->withTrashed()->findOrFail($record->id);
            $state = [$fresh->getRawOriginal(), $fresh->items()->withTrashed()->orderBy('id')->get()->map(fn ($item) => [$item->getRawOriginal(), $item->updates()->reorder()->orderBy('id')->get()->map->getRawOriginal()->all()])->all()];
        } elseif ($record instanceof RiskItem) {
            $fresh = RiskItem::withTrashed()->findOrFail($record->id);
            $state = [$fresh->getRawOriginal(), $fresh->updates()->reorder()->orderBy('id')->get()->map->getRawOriginal()->all(), RiskAssessment::withTrashed()->findOrFail($fresh->risk_assessment_id)->getRawOriginal()];
        } elseif ($record instanceof RiskItemUpdate) {
            $state = [$record->fresh()->getRawOriginal(), self::of(RiskItem::withTrashed()->findOrFail($record->risk_item_id))];
        } else {
            $state = app(RiskSettings::class)->get();
        }

        return hash('sha256', json_encode(self::stable($state), JSON_THROW_ON_ERROR));
    }

    public static function check(Request $request, mixed $record, bool $required = false): void
    {
        $value = $request->header('If-Match');
        abort_if($required && ! $value, 428, 'Read the current revision and send If-Match.');
        if ($value !== null) {
            abort_unless(hash_equals(self::of($record), trim($value, '"')), 409, 'The Risk record changed; reload before writing.');
        }
    }
}
