<?php

namespace App\Modules\DataExchange\Support;

use App\Models\Core\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DataExchangeSourceRegistry
{
    public const OPERATORS = ['equals', 'not_equals', 'contains', 'starts_with', 'greater_than', 'less_than', 'in', 'is_empty', 'is_not_empty'];

    /**
     * Field names matching these patterns are permanently blocked from export/import profiles.
     */
    private const SECRET_FIELD_PATTERNS = [
        '/(^|_)(password|password_hash|remember_token)($|_)/',
        '/(^|_)(two_factor_secret|two_factor_recovery_codes)($|_)/',
        '/(^|_)(api_key|api_token|access_token|refresh_token|token_hash)($|_)/',
        '/(^|_)(client_secret|webhook_secret|private_key)($|_)/',
        '/(^|_)(inbound_token|outbound_token)($|_)/',
        '/(^|_)(secret)($|_)/',
    ];

    /** @var array<string, DataExchangeSourceDefinition> */
    private array $sources = [];

    public function register(DataExchangeSourceDefinition $source): self
    {
        $safeFields = array_map(
            fn (DataExchangeFieldDefinition $field): DataExchangeFieldDefinition => $this->sanitizeField($field),
            $source->fields(),
        );

        $this->sources[$source->key] = $source->withFields($safeFields);

        return $this;
    }

    /**
     * @return Collection<int, DataExchangeSourceDefinition>
     */
    public function all(): Collection
    {
        return collect($this->sources)->values();
    }

    /**
     * @return Collection<int, DataExchangeSourceDefinition>
     */
    public function visibleFor(?User $user): Collection
    {
        return $this->all()
            ->filter(fn (DataExchangeSourceDefinition $source): bool => $source->permission === null || DataExchangeAuthority::permits($user, $source->permission))
            ->values();
    }

    public function get(string $key): ?DataExchangeSourceDefinition
    {
        return $this->sources[$key] ?? null;
    }

    /** Revalidate old persisted configuration before reading or writing any domain data. */
    public function assertProfile(\App\Modules\DataExchange\Models\DataExchangeProfile $profile, string $direction): DataExchangeSourceDefinition
    {
        $profile->load(['sources', 'fields', 'filters', 'mappings']);
        abort_unless($profile->sources->count() === 1, 422, 'Exactly one supported registered source is required.');
        $source = $this->get($profile->sources->first()->source_key);
        abort_unless($source && ($direction === 'export' ? $source->supportsExport : $source->supportsImport), 422, 'Unsupported source direction.');
        foreach ($profile->fields->where('active', true) as $field) {
            $definition = $source->field($field->field_key);
            abort_unless($field->source_key === $source->key && $definition && ! $definition->blocked && ($direction === 'export' ? $definition->exportable : $definition->importable), 422, 'A configured field is no longer allowed.');
        }
        foreach ($profile->filters->where('active', true) as $filter) {
            abort_unless(in_array($filter->operator, self::OPERATORS, true) && in_array($filter->field_key, $source->filters ?: array_map(fn ($f) => $f->key, $source->exportableFields()), true) && ! $this->isSecretField($filter->field_key), 422, 'A configured filter is no longer allowed.');
        }
        foreach ($profile->mappings->where('active', true) as $mapping) {
            $field = $source->field($mapping->mapping_key);
            // Old templates retain mappings for the other direction; only active-direction mappings execute.
            if ($mapping->output_format === ($direction === 'import' ? 'import' : $profile->format)) {
                abort_unless($field && ! $field->blocked && ($direction === 'import' ? $field->importable : $field->exportable), 422, 'A configured mapping is no longer allowed.');
            }
        }

        return $source;
    }

    public function isSecretField(string $fieldKey): bool
    {
        $normalized = Str::of($fieldKey)
            ->replace(['-', '.', ' '], '_')
            ->lower()
            ->toString();

        foreach (self::SECRET_FIELD_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return true;
            }
        }

        return false;
    }

    private function sanitizeField(DataExchangeFieldDefinition $field): DataExchangeFieldDefinition
    {
        if ($field->blocked || $field->sensitive || $this->isSecretField($field->key)) {
            return $field->blockedCopy();
        }

        return $field;
    }
}
