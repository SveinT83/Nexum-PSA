<?php

namespace App\Modules\DataExchange\Support;

use App\Modules\DataExchange\Models\DataExchangeProfile;
use Illuminate\Database\Eloquent\Model;

/** Content revisions include configuration children; timestamps alone are not concurrency tokens. */
final class DataExchangeRevision
{
    public static function hash(mixed $value): string
    {
        $normalize = function ($item) use (&$normalize) {
            if (! is_array($item)) {
                return $item;
            }
            if (! array_is_list($item)) {
                ksort($item);
            }

            return array_map($normalize, $item);
        };

        return hash('sha256', json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    public static function of(Model $record): string
    {
        $parts = $record->getRawOriginal();
        if ($record instanceof DataExchangeProfile) {
            foreach (['sources', 'fields', 'filters', 'mappings'] as $relation) {
                $parts[$relation] = $record->$relation()->orderBy('sort_order')->orderBy('id')->get()->map->getRawOriginal()->all();
            }
        }

        return self::hash($parts);
    }

    public static function match(Model $record, ?string $revision, bool $required = true): void
    {
        abort_if($required && ! $revision, 428, 'Read the current revision and supply If-Match.');
        if ($revision !== null) {
            abort_unless(hash_equals(self::of($record), trim($revision, '"')), 409, 'The record changed; read it again.');
        }
    }
}
