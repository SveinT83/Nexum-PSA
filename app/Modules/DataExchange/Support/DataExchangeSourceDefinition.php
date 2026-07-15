<?php

namespace App\Modules\DataExchange\Support;

use App\Models\Core\User;
use App\Modules\DataExchange\Models\DataExchangeProfile;
use Closure;
use Illuminate\Database\Eloquent\Model;

class DataExchangeSourceDefinition
{
    /**
     * @param array<int, DataExchangeFieldDefinition> $fields
     * @param array<int, array<string, mixed>> $relations
     * @param array<int, string> $filters
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $module,
        public ?string $modelClass = null,
        public ?string $permission = null,
        private array $fields = [],
        public array $relations = [],
        public array $filters = [],
        public bool $supportsExport = true,
        public bool $supportsImport = false,
        private ?Closure $exporter = null,
        private ?Closure $importPreviewer = null,
        private ?Closure $importCommitter = null,
    ) {}

    /**
     * @return array<int, DataExchangeFieldDefinition>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * @return array<int, DataExchangeFieldDefinition>
     */
    public function exportableFields(): array
    {
        return array_values(array_filter(
            $this->fields,
            fn (DataExchangeFieldDefinition $field): bool => $field->exportable && ! $field->blocked,
        ));
    }

    /**
     * @return array<int, DataExchangeFieldDefinition>
     */
    public function importableFields(): array
    {
        return array_values(array_filter(
            $this->fields,
            fn (DataExchangeFieldDefinition $field): bool => $field->importable && ! $field->blocked,
        ));
    }

    public function field(string $key): ?DataExchangeFieldDefinition
    {
        foreach ($this->fields as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function exportRows(DataExchangeProfile $profile, array $options = []): array
    {
        if ($this->exporter instanceof Closure) {
            return ($this->exporter)($profile, $options);
        }

        if (! $this->modelClass || ! is_a($this->modelClass, Model::class, true)) {
            return [];
        }

        return $this->modelClass::query()
            ->get()
            ->map(function (Model $model): array {
                $row = [];

                foreach ($this->exportableFields() as $field) {
                    $row[$field->key] = data_get($model, $field->key);
                }

                return $row;
            })
            ->all();
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{rows: array<int, array<string, mixed>>, errors: array<int, array<string>>, summary: array<string, mixed>}
     */
    public function previewImportRows(DataExchangeProfile $profile, array $rows, array $mapping, ?User $actor = null): array
    {
        if ($this->importPreviewer instanceof Closure) {
            return ($this->importPreviewer)($profile, $rows, $mapping, $actor);
        }

        return [
            'rows' => array_map(fn (array $row): array => [
                'input' => $row,
                'values' => [],
                'valid' => false,
                'errors' => ['This source does not support imports.'],
            ], $rows),
            'errors' => [['source' => 'This source does not support imports.']],
            'summary' => ['supported' => false],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $previewRows
     * @return array<string, mixed>
     */
    public function commitImportRows(DataExchangeProfile $profile, array $previewRows, ?User $actor = null): array
    {
        if ($this->importCommitter instanceof Closure) {
            return ($this->importCommitter)($profile, $previewRows, $actor);
        }

        return [
            'created' => 0,
            'updated' => 0,
            'skipped' => count($previewRows),
            'errors' => ['This source does not support import commits.'],
        ];
    }

    /**
     * @param array<int, DataExchangeFieldDefinition> $fields
     */
    public function withFields(array $fields): self
    {
        return new self(
            key: $this->key,
            label: $this->label,
            module: $this->module,
            modelClass: $this->modelClass,
            permission: $this->permission,
            fields: $fields,
            relations: $this->relations,
            filters: $this->filters,
            supportsExport: $this->supportsExport,
            supportsImport: $this->supportsImport,
            exporter: $this->exporter,
            importPreviewer: $this->importPreviewer,
            importCommitter: $this->importCommitter,
        );
    }
}
