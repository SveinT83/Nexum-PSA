<?php

namespace App\Modules\DataExchange\Support;

use App\Models\Core\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DataExchangeSourceRegistry
{
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
            ->filter(fn (DataExchangeSourceDefinition $source): bool => $source->permission === null || $user?->can($source->permission))
            ->values();
    }

    public function get(string $key): ?DataExchangeSourceDefinition
    {
        return $this->sources[$key] ?? null;
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
