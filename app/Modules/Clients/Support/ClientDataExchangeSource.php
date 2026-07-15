<?php

namespace App\Modules\Clients\Support;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Clients\Actions\CreateClientWithDefaults;
use App\Modules\DataExchange\Models\DataExchangeProfile;
use App\Modules\DataExchange\Support\DataExchangeFieldDefinition;
use App\Modules\DataExchange\Support\DataExchangeSourceDefinition;
use Illuminate\Support\Facades\Validator;

class ClientDataExchangeSource
{
    public function definition(): DataExchangeSourceDefinition
    {
        return new DataExchangeSourceDefinition(
            key: 'clients',
            label: 'Clients',
            module: 'Clients',
            modelClass: Client::class,
            permission: 'client.view',
            fields: $this->fields(),
            filters: ['client_number', 'name', 'org_no', 'active'],
            supportsImport: true,
            exporter: fn (DataExchangeProfile $profile, array $options = []): array => $this->exportRows(),
            importPreviewer: fn (DataExchangeProfile $profile, array $rows, array $mapping, ?User $actor = null): array => $this->previewRows($rows, $mapping),
            importCommitter: fn (DataExchangeProfile $profile, array $previewRows, ?User $actor = null): array => $this->commitRows($previewRows, $actor),
        );
    }

    /**
     * @return array<int, DataExchangeFieldDefinition>
     */
    private function fields(): array
    {
        return [
            new DataExchangeFieldDefinition('id', 'Client ID', 'integer', importable: false),
            new DataExchangeFieldDefinition('client_number', 'Client number', importable: true),
            new DataExchangeFieldDefinition('name', 'Name', importable: true),
            new DataExchangeFieldDefinition('org_no', 'Org number', importable: true),
            new DataExchangeFieldDefinition('website', 'Website', importable: true),
            new DataExchangeFieldDefinition('billing_email', 'Billing email', importable: true),
            new DataExchangeFieldDefinition('active', 'Active', 'boolean', importable: true),
            new DataExchangeFieldDefinition('notes', 'Notes', importable: true),
            new DataExchangeFieldDefinition('site_name', 'Default site name', exportable: false, importable: true),
            new DataExchangeFieldDefinition('contact_name', 'Default contact name', exportable: false, importable: true),
            new DataExchangeFieldDefinition('contact_email', 'Default contact email', exportable: false, importable: true),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function exportRows(): array
    {
        return Client::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Client $client): array => [
                'id' => $client->id,
                'client_number' => $client->client_number,
                'name' => $client->name,
                'org_no' => $client->org_no,
                'website' => $client->website,
                'billing_email' => $client->billing_email,
                'active' => (bool) $client->active,
                'notes' => $client->notes,
            ])
            ->all();
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{rows: array<int, array<string, mixed>>, errors: array<int, array<string>>, summary: array<string, mixed>}
     */
    private function previewRows(array $rows, array $mapping): array
    {
        $previewRows = [];
        $errors = [];

        foreach ($rows as $index => $row) {
            $values = $this->mappedValues($row, $mapping);
            $validator = Validator::make($values, [
                'client_number' => ['nullable', 'string', 'max:255'],
                'name' => ['required', 'string', 'max:255'],
                'org_no' => ['nullable', 'string', 'max:255'],
                'website' => ['nullable', 'string', 'max:255'],
                'billing_email' => ['nullable', 'email', 'max:255'],
                'active' => ['nullable'],
                'notes' => ['nullable', 'string'],
                'site_name' => ['nullable', 'string', 'max:255'],
                'contact_name' => ['nullable', 'string', 'max:255'],
                'contact_email' => ['nullable', 'email', 'max:255'],
            ]);
            $rowErrors = $validator->errors()->all();

            if ($rowErrors !== []) {
                $errors[$index + 1] = $rowErrors;
            }

            $previewRows[] = [
                'row_number' => $index + 1,
                'input' => $row,
                'values' => $values,
                'valid' => $rowErrors === [],
                'errors' => $rowErrors,
            ];
        }

        return [
            'rows' => $previewRows,
            'errors' => $errors,
            'summary' => [
                'target' => 'clients',
                'valid' => count($previewRows) - count($errors),
                'invalid' => count($errors),
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $previewRows
     * @return array<string, mixed>
     */
    private function commitRows(array $previewRows, ?User $actor): array
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($previewRows as $previewRow) {
            if (! ($previewRow['valid'] ?? false)) {
                $skipped++;
                continue;
            }

            $values = $previewRow['values'];
            $client = $this->findExistingClient($values);
            $attributes = [
                'client_number' => $values['client_number'] ?? null,
                'name' => $values['name'],
                'org_no' => $values['org_no'] ?? null,
                'website' => $values['website'] ?? null,
                'billing_email' => $values['billing_email'] ?? null,
                'active' => $this->boolValue($values['active'] ?? true),
                'notes' => $values['notes'] ?? null,
            ];

            if ($client) {
                $client->fill(array_filter($attributes, fn ($value): bool => $value !== null))->save();
                $updated++;
                continue;
            }

            app(CreateClientWithDefaults::class)->handle(array_merge($attributes, [
                'site_name' => $values['site_name'] ?: 'Main site',
                'user_name' => $values['contact_name'] ?: 'Imported contact',
                'user_email' => $values['contact_email'] ?: null,
            ]));
            $created++;
        }

        return compact('created', 'updated', 'skipped');
    }

    private function findExistingClient(array $values): ?Client
    {
        if (filled($values['client_number'] ?? null)) {
            return Client::query()->where('client_number', $values['client_number'])->first();
        }

        if (filled($values['org_no'] ?? null)) {
            return Client::query()->where('org_no', $values['org_no'])->first();
        }

        return null;
    }

    private function mappedValues(array $row, array $mapping): array
    {
        $values = [];

        foreach ($this->fields() as $field) {
            if (! $field->importable) {
                continue;
            }

            $inputKey = $mapping[$field->key] ?? $field->key;
            $values[$field->key] = array_key_exists($inputKey, $row) ? $row[$inputKey] : data_get($row, $inputKey);
        }

        return $values;
    }

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'ja', 'active'], true);
    }
}
