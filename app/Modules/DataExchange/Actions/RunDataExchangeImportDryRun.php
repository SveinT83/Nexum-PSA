<?php

namespace App\Modules\DataExchange\Actions;

use App\Models\Core\User;
use App\Modules\DataExchange\Models\DataExchangeAuditEvent;
use App\Modules\DataExchange\Models\DataExchangeImportPreview;
use App\Modules\DataExchange\Models\DataExchangeProfile;
use App\Modules\DataExchange\Models\DataExchangeRun;
use App\Modules\DataExchange\Support\DataExchangeSourceRegistry;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

class RunDataExchangeImportDryRun
{
    public function __construct(private readonly DataExchangeSourceRegistry $sources) {}

    public function handle(DataExchangeProfile $profile, UploadedFile|string $file, ?User $actor = null, ?string $format = null): DataExchangeImportPreview
    {
        abort_unless($profile->direction === DataExchangeProfile::DIRECTION_IMPORT, 422, 'Profile is not an import profile.');

        \App\Modules\DataExchange\Support\DataExchangeAuthority::runtime($profile, $actor, 'import');
        abort_unless($profile->status === 'active', 422, 'Use an active import profile.');
        $this->sources->assertProfile($profile, 'import');
        $profile->loadMissing(['sources', 'mappings']);
        $profileSource = $profile->sources->sortBy('sort_order')->first();
        $source = $profileSource ? $this->sources->get($profileSource->source_key) : null;

        abort_unless($source && $source->supportsImport, 422, 'The profile source is not importable.');

        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $filename = $file instanceof UploadedFile ? $file->getClientOriginalName() : basename($file);
        $format ??= strtolower(pathinfo($filename, PATHINFO_EXTENSION) ?: ($profile->format ?: 'csv'));

        abort_unless(in_array($format, ['csv', 'json', 'xlsx'], true), 422, 'Unsupported import format.');
        abort_unless(is_file($path) && filesize($path) <= 10 * 1024 * 1024, 422, 'Use an import file up to 10 MiB.');
        $profileRevision = \App\Modules\DataExchange\Support\DataExchangeRevision::of($profile);
        $sourceRevision = $source->importRevision();
        $run = DataExchangeRun::query()->create([
            'profile_id' => $profile->id,
            'direction' => DataExchangeProfile::DIRECTION_IMPORT,
            'status' => DataExchangeRun::STATUS_RUNNING,
            'trigger_type' => 'manual',
            'triggered_by' => $actor?->id,
            'started_at' => now(),
        ]);

        try {
            $rows = $this->parseRows((string) $path, $format);
            abort_unless(count($rows) > 0 && count($rows) <= 10000, 422, 'Use between 1 and 10000 import rows.');
            $mapping = $this->mappingFor($profile);
            foreach ($rows as $row) {
                foreach (array_keys($row) as $column) {
                    abort_if($this->sources->isSecretField((string) $column), 422, 'Secret-like input columns are not accepted.');
                }
            }
            // Keep only reviewed mapped inputs; unrelated uploaded columns must not enter stored previews.
            $allowedInputs = array_fill_keys(array_values($mapping), true);
            $rows = array_map(fn ($row) => array_intersect_key($row, $allowedInputs), $rows);
            $preview = $source->previewImportRows($profile, $rows, $mapping, $actor);
            $previewRows = $preview['rows'];
            $valid = collect($previewRows)->where('valid', true)->count();
            $invalid = count($previewRows) - $valid;

            $importPreview = DataExchangeImportPreview::query()->create([
                'profile_id' => $profile->id,
                'run_id' => $run->id,
                'status' => DataExchangeImportPreview::STATUS_PREVIEWED,
                'source_key' => $source->key,
                'format' => $format,
                'original_filename' => $filename,
                'row_count' => count($previewRows),
                'valid_count' => $valid,
                'invalid_count' => $invalid,
                'mapping' => $mapping,
                'rows' => $previewRows,
                'errors' => $preview['errors'],
                'summary' => $preview['summary'] + ['review' => [
                    'profile_revision' => $profileRevision, 'source_revision' => $sourceRevision,
                    'input_checksum' => hash_file('sha256', $path),
                    'rows_hash' => \App\Modules\DataExchange\Support\DataExchangeRevision::hash([$previewRows, $mapping]),
                ]],
                'created_by' => $actor?->id,
            ]);

            $run->forceFill([
                'status' => DataExchangeRun::STATUS_SUCCEEDED,
                'finished_at' => now(),
                'summary' => [
                    'preview_id' => $importPreview->id,
                    'rows' => count($previewRows),
                    'valid' => $valid,
                    'invalid' => $invalid,
                ],
            ])->save();

            $this->audit('import_previewed', 'succeeded', $profile, $run, $actor, [
                'preview_id' => $importPreview->id,
                'rows' => count($previewRows),
            ]);

            return $importPreview->refresh();
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => DataExchangeRun::STATUS_FAILED,
                'finished_at' => now(),
                'error_message' => $exception->getMessage(),
            ])->save();

            $this->audit('import_preview_failed', 'failed', $profile, $run, $actor, ['error' => $exception->getMessage()]);

            throw $exception;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseRows(string $path, string $format): array
    {
        return match ($format) {
            'json' => $this->parseJson($path),
            'xlsx' => $this->parseSpreadsheet($path),
            default => $this->parseCsv($path),
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        $headers = [];
        $rows = [];

        while (($line = fgetcsv($handle)) !== false) {
            if ($headers === []) {
                $headers = array_map(fn ($h) => trim(ltrim((string) $h, "\xEF\xBB\xBF")), $line);
                abort_unless(count(array_unique($headers)) === count($headers) && ! in_array('', $headers, true), 422, 'Use unique nonempty column names.');

                continue;
            }

            abort_unless(count($line) <= count($headers), 422, 'A row has more values than column names.');
            $rows[] = array_combine($headers, array_pad($line, count($headers), null));
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseJson(string $path): array
    {
        try {
            $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            abort(422, 'The import JSON is invalid.');
        }
        abort_unless(is_array($payload), 422, 'Use a JSON array of row objects.');
        $rows = $payload['rows'] ?? $payload;

        abort_unless(is_array($rows) && array_is_list($rows) && count(array_filter($rows, 'is_array')) === count($rows), 422, 'Use a JSON array of row objects.');

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseSpreadsheet(string $path): array
    {
        $sheet = IOFactory::load($path)->getActiveSheet();
        $matrix = $sheet->toArray(null, false, false, false);
        $headers = array_map('trim', array_shift($matrix) ?: []);
        abort_unless(count($headers) > 0 && count(array_unique($headers)) === count($headers) && ! in_array('', $headers, true), 422, 'Use unique nonempty column names.');
        $rows = [];

        foreach ($matrix as $line) {
            if (collect($line)->filter(fn ($value): bool => filled($value))->isEmpty()) {
                continue;
            }

            abort_unless(count($line) <= count($headers), 422, 'A row has more values than column names.');
            $rows[] = array_combine($headers, array_pad($line, count($headers), null));
        }

        return $rows;
    }

    private function mappingFor(DataExchangeProfile $profile): array
    {
        $mapping = [];

        foreach ($profile->mappings->where('active', true)->sortBy('sort_order') as $profileMapping) {
            if ($profileMapping->output_format === 'import') {
                $mapping[$profileMapping->mapping_key] = $profileMapping->source_expression ?: $profileMapping->mapping_key;
            }
        }

        if ($mapping !== []) {
            return $mapping;
        }

        foreach ($profile->fields->where('active', true)->sortBy('sort_order') as $field) {
            $mapping[$field->field_key] = $field->output_key ?: $field->field_key;
        }

        return $mapping;
    }

    private function audit(string $type, string $outcome, DataExchangeProfile $profile, DataExchangeRun $run, ?User $actor, array $metadata): void
    {
        DataExchangeAuditEvent::query()->create([
            'profile_id' => $profile->id,
            'run_id' => $run->id,
            'event_type' => $type,
            'outcome' => $outcome,
            'actor_id' => $actor?->id,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }
}
