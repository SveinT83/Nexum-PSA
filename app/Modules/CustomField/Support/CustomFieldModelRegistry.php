<?php

namespace App\Modules\CustomField\Support;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Modules\Ticket\Models\Ticket;

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
        'ticket' => Ticket::class,
    ];

    public const LABELS = [
        'client' => 'Client',
        'client_site' => 'Client site',
        'ticket' => 'Ticket',
    ];

    public function all(): array
    {
        return self::MODELS;
    }

    public function labelFor(string $modelType): string
    {
        return array_search($modelType, self::MODELS, true) ?: class_basename($modelType);
    }

    public function displayLabelFor(string $aliasOrClass): string
    {
        if (array_key_exists($aliasOrClass, self::MODELS)) {
            $alias = $aliasOrClass;
        } else {
            $alias = array_search($aliasOrClass, self::MODELS, true) ?: $aliasOrClass;
        }

        return self::LABELS[$alias] ?? class_basename($aliasOrClass);
    }

    public function classFor(string $aliasOrClass): ?string
    {
        return self::MODELS[$aliasOrClass] ?? (in_array($aliasOrClass, self::MODELS, true) ? $aliasOrClass : null);
    }

    public function aliasFor(string $aliasOrClass): ?string
    {
        $class = $this->classFor($aliasOrClass);

        if ($class === null) {
            return null;
        }

        $alias = array_search($class, self::MODELS, true);

        return $alias === false ? null : $alias;
    }

    public function canonicalStorageType(string $aliasOrClass): ?string
    {
        return $this->classFor($aliasOrClass);
    }

    /**
     * @return array<int, string>
     */
    public function storageTypesFor(string $aliasOrClass): array
    {
        $class = $this->classFor($aliasOrClass) ?? $aliasOrClass;
        $aliases = array_keys(array_filter(
            self::MODELS,
            fn (string $modelClass): bool => $modelClass === $class,
        ));

        return array_values(array_unique(array_filter([$class, $aliasOrClass, ...$aliases])));
    }
}
