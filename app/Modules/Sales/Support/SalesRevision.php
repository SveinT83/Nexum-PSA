<?php

namespace App\Modules\Sales\Support;

use App\Modules\Sales\Models\SalesOpportunity;
use App\Modules\Sales\Models\SalesQuoteVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/** Strong content revisions include child facts, not only second-precision timestamps. */
final class SalesRevision
{
    public static function of(Model $model): string
    {
        $model = $model->fresh() ?? $model;
        $parts = [$model->getRawOriginal()];
        if ($model instanceof SalesQuoteVersion || $model instanceof \App\Modules\Sales\Models\SalesQuoteTemplate) {
            $relations = $model instanceof SalesQuoteVersion ? ['lines', 'optionGroups', 'acknowledgements', 'conversionPlans'] : ['lines', 'optionGroups', 'acknowledgements'];
            foreach ($relations as $relation) {
                $parts[$relation] = $model->$relation()->get()->sortBy('id')->map->getRawOriginal()->values()->all();
            }
        }
        if ($model instanceof SalesOpportunity) {
            $parts['stakeholders'] = $model->stakeholders()->orderBy('id')->get()->map->getRawOriginal()->all();
            $parts['current_quote'] = $model->currentQuoteVersion ? self::of($model->currentQuoteVersion) : null;
        }

        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }

    public static function check(Request $request, Model $model, bool $required = true): void
    {
        $expected = trim((string) $request->header('If-Match'), '"');
        abort_if($required && $expected === '', 428, 'If-Match is required; read the current revision first.');
        abort_if($expected !== '' && ! hash_equals(self::of($model), $expected), 409, 'The record changed. Read it and review the command again.');
    }
}
