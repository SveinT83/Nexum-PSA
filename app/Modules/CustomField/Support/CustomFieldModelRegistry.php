<?php

namespace App\Modules\CustomField\Support;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;

class CustomFieldModelRegistry
{
    /*
    |--------------------------------------------------------------------------
    | Supported custom field targets
    |--------------------------------------------------------------------------
    |
    | Add domains here one at a time after their UI, API, validation, tests, and
    | documentation are implemented.
    |
    */
    public const MODELS = [
        'client' => Client::class,
        'client_site' => ClientSite::class,
    ];

    public function all(): array
    {
        return self::MODELS;
    }

    public function labelFor(string $modelType): string
    {
        return array_search($modelType, self::MODELS, true) ?: class_basename($modelType);
    }

    public function classFor(string $aliasOrClass): ?string
    {
        return self::MODELS[$aliasOrClass] ?? (in_array($aliasOrClass, self::MODELS, true) ? $aliasOrClass : null);
    }
}
