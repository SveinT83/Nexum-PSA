<?php

namespace App\Modules\Clients\Support;

use App\Modules\Commercial\Support\CommercialRevision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/** Content-based revisions detect same-second writes and custom-field/default changes. */
class ClientRevision
{
    public static function of(Model $record): string
    {
        $record = $record->fresh() ?? $record;
        $fields = \Illuminate\Support\Facades\DB::table('custom_field_values')
            ->whereIn('model_type', app(\App\Modules\CustomField\Support\CustomFieldModelRegistry::class)->storageTypesFor($record->getMorphClass()))->where('model_id', $record->getKey())
            ->orderBy('id')->get()->map(fn ($v) => (array) $v)->all();

        $links = method_exists($record, 'rmmLinks') ? $record->rmmLinks()->orderBy('id')->get()->map->getRawOriginal()->all() : [];

        return hash('sha256', json_encode(CommercialRevision::stable([$record->getTable(), $record->getRawOriginal(), $fields, $links]), JSON_THROW_ON_ERROR));
    }

    public static function check(Request $request, Model $record, bool $required = true): void
    {
        $value = $request->header('If-Match');
        abort_if($required && ! $value, 428, 'If-Match is required; read the current revision first.');
        if ($value) {
            abort_unless(hash_equals(self::of($record), trim($value, '"')), 409, 'The record changed; reload and review it.');
        }
    }
}
