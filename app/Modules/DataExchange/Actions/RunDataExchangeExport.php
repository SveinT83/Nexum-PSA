<?php

namespace App\Modules\DataExchange\Actions;

use App\Models\Core\User;
use App\Modules\DataExchange\Models\DataExchangeAuditEvent;
use App\Modules\DataExchange\Models\DataExchangeFile;
use App\Modules\DataExchange\Models\DataExchangeProfile;
use App\Modules\DataExchange\Models\DataExchangeProfileField;
use App\Modules\DataExchange\Models\DataExchangeRun;
use App\Modules\DataExchange\Support\DataExchangeSourceRegistry;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

class RunDataExchangeExport
{
    public function __construct(private readonly DataExchangeSourceRegistry $sources) {}

    public function handle(DataExchangeProfile $profile, ?User $actor = null, string $triggerType = 'manual', array $options = []): DataExchangeRun
    {
        $run = DataExchangeRun::query()->create([
            'profile_id' => $profile->id,
            'direction' => DataExchangeProfile::DIRECTION_EXPORT,
            'status' => DataExchangeRun::STATUS_RUNNING,
            'trigger_type' => $triggerType,
            'triggered_by' => $actor?->id,
            'started_at' => now(),
            'summary' => ['options' => $this->safeOptions($options)],
        ]);

        $this->audit('export_started', 'running', $profile, $run, actor: $actor, metadata: ['trigger_type' => $triggerType]);

        try {
            abort_unless($profile->direction === DataExchangeProfile::DIRECTION_EXPORT, 422, 'Profile is not an export profile.');

            $rows = $this->mappedRows($profile, $options);
            $format = strtolower((string) ($options['format'] ?? $profile->format ?? 'csv'));
            $contents = $this->contentsFor($rows, $format);
            $extension = $format === 'xlsx' ? 'xlsx' : ($format === 'json' ? 'json' : 'csv');
            $filename = Str::slug($profile->key ?: $profile->name).'_'.$run->id.'_'.now()->format('Ymd_His').'.'.$extension;
            $path = 'data-exchange/exports/'.Str::slug($profile->key ?: 'profile-'.$profile->id).'/'.$filename;

            Storage::disk('local')->put($path, $contents);

            $file = DataExchangeFile::query()->create([
                'profile_id' => $profile->id,
                'run_id' => $run->id,
                'disk' => 'local',
                'path' => $path,
                'filename' => $filename,
                'mime_type' => $this->mimeType($format),
                'format' => $format,
                'size_bytes' => strlen($contents),
                'checksum' => hash('sha256', $contents),
                'retention_until' => now()->addDays((int) data_get($profile->settings, 'retention_days', 90)),
                'generated_by' => $actor?->id,
            ]);

            $run->forceFill([
                'status' => DataExchangeRun::STATUS_SUCCEEDED,
                'finished_at' => now(),
                'summary' => [
                    'rows' => count($rows),
                    'format' => $format,
                    'file_id' => $file->id,
                    'filename' => $filename,
                ],
            ])->save();

            $this->audit('export_succeeded', 'succeeded', $profile, $run, $file, $actor, [
                'rows' => count($rows),
                'format' => $format,
            ]);
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => DataExchangeRun::STATUS_FAILED,
                'finished_at' => now(),
                'error_message' => $exception->getMessage(),
                'summary' => array_merge((array) $run->summary, ['failed_at' => now()->toDateTimeString()]),
            ])->save();

            $this->audit('export_failed', 'failed', $profile, $run, actor: $actor, metadata: ['error' => $exception->getMessage()]);

            throw $exception;
        }

        return $run->refresh()->load('files');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function mappedRows(DataExchangeProfile $profile, array $options = []): array
    {
        $profile->loadMissing(['sources', 'fields', 'filters']);
        $profileSource = $profile->sources->sortBy('sort_order')->first();
        $source = $profileSource ? $this->sources->get($profileSource->source_key) : null;

        abort_unless($source && $source->supportsExport, 422, 'The profile source is not exportable.');

        $rawRows = collect($source->exportRows($profile, $options));
        $filteredRows = $rawRows->filter(fn (array $row): bool => $this->rowMatchesFilters($row, $profile))->values();
        $fields = $profile->fields
            ->where('active', true)
            ->sortBy('sort_order')
            ->values();

        return $filteredRows
            ->map(function (array $row) use ($fields): array {
                $mapped = [];

                /** @var DataExchangeProfileField $field */
                foreach ($fields as $field) {
                    $key = $field->field_key;
                    $mapped[$field->output_key ?: $field->label ?: $key] = $this->value($row, $key);
                }

                return $mapped;
            })
            ->all();
    }

    private function rowMatchesFilters(array $row, DataExchangeProfile $profile): bool
    {
        foreach ($profile->filters->where('active', true) as $filter) {
            $actual = $this->value($row, $filter->field_key);
            $expected = $filter->value;

            if (! $this->matches((string) $filter->operator, $actual, $expected)) {
                return false;
            }
        }

        return true;
    }

    private function matches(string $operator, mixed $actual, mixed $expected): bool
    {
        return match ($operator) {
            'not_equals' => (string) $actual !== (string) $expected,
            'contains' => str_contains(Str::lower((string) $actual), Str::lower((string) $expected)),
            'starts_with' => str_starts_with(Str::lower((string) $actual), Str::lower((string) $expected)),
            'greater_than' => (float) $actual > (float) $expected,
            'less_than' => (float) $actual < (float) $expected,
            'in' => in_array((string) $actual, array_map('strval', (array) $expected), true),
            'is_empty' => blank($actual),
            'is_not_empty' => filled($actual),
            default => (string) $actual === (string) $expected,
        };
    }

    private function value(array $row, string $key): mixed
    {
        return array_key_exists($key, $row) ? $row[$key] : data_get($row, $key);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function contentsFor(array $rows, string $format): string
    {
        return match ($format) {
            'json' => json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'xlsx' => $this->xlsxContents($rows),
            default => $this->csvContents($rows),
        };
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function csvContents(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        $headers = array_keys($rows[0] ?? []);

        if ($headers !== []) {
            fputcsv($handle, $headers);
        }

        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (mixed $value): mixed => $this->scalar($value), $row));
        }

        rewind($handle);
        $contents = stream_get_contents($handle);
        fclose($handle);

        return (string) $contents;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function xlsxContents(array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $headers = array_keys($rows[0] ?? []);

        foreach ($headers as $column => $header) {
            $sheet->setCellValue([$column + 1, 1], $header);
        }

        foreach (array_values($rows) as $rowIndex => $row) {
            foreach (array_values($row) as $column => $value) {
                $sheet->setCellValue([$column + 1, $rowIndex + 2], $this->scalar($value));
            }
        }

        $temp = tempnam(sys_get_temp_dir(), 'dxlsx_');
        (new Xlsx($spreadsheet))->save($temp);
        $contents = (string) file_get_contents($temp);
        @unlink($temp);

        return $contents;
    }

    private function scalar(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return $value;
    }

    private function mimeType(string $format): string
    {
        return match ($format) {
            'json' => 'application/json',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            default => 'text/csv',
        };
    }

    private function audit(string $type, string $outcome, DataExchangeProfile $profile, DataExchangeRun $run, ?DataExchangeFile $file = null, ?User $actor = null, array $metadata = []): void
    {
        DataExchangeAuditEvent::query()->create([
            'profile_id' => $profile->id,
            'run_id' => $run->id,
            'file_id' => $file?->id,
            'event_type' => $type,
            'outcome' => $outcome,
            'actor_id' => $actor?->id,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }

    private function safeOptions(array $options): array
    {
        unset($options['password'], $options['secret'], $options['token'], $options['api_key']);

        return $options;
    }
}
