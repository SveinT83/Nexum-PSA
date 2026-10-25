<?php

namespace App\Modules\Economy\Actions;

use App\Models\Core\User;
use App\Modules\DataExchange\Actions\EnsureDataExchangeProfileTemplates;
use App\Modules\DataExchange\Actions\RunDataExchangeExport;
use App\Modules\DataExchange\Models\DataExchangeProfile;
use App\Modules\DataExchange\Models\DataExchangeRun;
use App\Modules\Economy\Models\EconomyOrder;
use App\Modules\Economy\Support\EconomyMutex;
use App\Modules\Economy\Support\EconomyRevision;

/** A scoped facade over the existing file-export owner, preserving configured profile content. */
class EconomyExportWorkflow
{
    public function preview(): array
    {
        $profile = DataExchangeProfile::query()->where('key', 'economy_orders_export')->first();
        $parts = [EconomyMutex::revision(), $profile?->getRawOriginal()];
        if ($profile) {
            $profile->load(['sources', 'fields', 'filters', 'deliveryTargets']);
            abort_unless($profile->direction === 'export' && $profile->sources->every(fn ($s) => $s->source_key === 'economy.orders'), 422, 'The Economy profile must use the Economy Orders export source.');
            $parts[] = $profile->sources->map->getRawOriginal()->all();
            $parts[] = $profile->fields->map->getRawOriginal()->all();
            $parts[] = $profile->filters->map->getRawOriginal()->all();
            // Only the fingerprint is returned; protected delivery-target configuration is never projected.
            $parts[] = $profile->deliveryTargets->map->getRawOriginal()->all();
            $rows = app(RunDataExchangeExport::class)->mappedRows($profile);
            $parts[] = $rows;
            $count = count($rows);
        } else {
            $orders = EconomyOrder::query()->with(['client', 'lines.ticket'])->whereIn('status', ['ready', 'approved'])->orderBy('id')->get();
            $parts[] = $orders->toArray();
            $count = $orders->sum(fn ($o) => max(1, $o->lines->count()));
        }

        return ['profile_id' => $profile?->id, 'profile_key' => 'economy_orders_export', 'profile_exists' => (bool) $profile,
            'format' => $profile?->format ?? 'csv', 'row_count' => $count, 'preview_hash' => EconomyRevision::hash($parts),
            'default_statuses' => ['ready', 'approved'], 'uses_configured_filters' => (bool) $profile,
            'configured_delivery_targets' => $profile?->deliveryTargets->count() ?? 0,
            'delivery_will_be_dispatched' => false, 'order_state_will_change' => false,
            'result_is' => 'data_exchange_file_not_provider_invoice', 'profile_initialization' => $profile ? 'preserve_existing' : 'create_existing_default_template_on_execute'];
    }

    public function handle(User $actor, string $trigger = 'api'): DataExchangeRun
    {
        return app(EconomyMutex::class)->run(function () use ($actor, $trigger) {
            $profile = DataExchangeProfile::query()->where('key', 'economy_orders_export')->first();
            if (! $profile) {
                $profile = app(EnsureDataExchangeProfileTemplates::class)->economyOrdersExportProfile($actor);
            }
            $this->preview();

            try {
                return app(RunDataExchangeExport::class)->handle($profile, $actor, $trigger);
            } catch (\Throwable $error) {
                // Preserve the owner's failed run for exact read-back; do not create a success receipt.
                $run = $profile->runs()->where('triggered_by', $actor->id)->latest('id')->first();
                if (! $run || $run->status !== DataExchangeRun::STATUS_FAILED) {
                    throw $error;
                }

                return $run->load('files');
            }
        });
    }

    /** The Economy facade and Data Exchange profile command share header/preview/replay semantics. */
    public function command(\Illuminate\Http\Request $request): array
    {
        $input = \App\Modules\Economy\Support\EconomyInput::validate($request, ['preview_hash' => 'required|string|size:64']);
        $request->route()->defaults('economy_permissions', ['economy.view', 'data_exchange.run']);

        return app(ExecuteEconomyCommand::class)->handle($request, $input, function () use ($request, $input) {
            abort_unless(hash_equals($this->preview()['preview_hash'], $input['preview_hash']), 409, 'The export changed; preview again.');

            return ['data' => self::projection($this->handle($request->user()))];
        });
    }

    public static function projection(DataExchangeRun $run): array
    {
        $run->loadMissing(['profile', 'files']);

        return ['id' => $run->id, 'profile' => ['id' => $run->profile_id, 'key' => $run->profile?->key], 'status' => $run->status,
            'trigger_type' => $run->trigger_type, 'summary' => array_intersect_key($run->summary ?? [], array_flip(['rows', 'format', 'file_id', 'filename'])),
            'error_code' => $run->status === 'failed' ? 'export_failed' : null,
            'files' => $run->files->map(fn ($file) => ['id' => $file->id, 'filename' => $file->filename, 'format' => $file->format, 'size_bytes' => $file->size_bytes, 'checksum' => $file->checksum, 'retention_until' => $file->retention_until?->toIso8601String(), 'download_url' => route('api.v1.data-exchange.files.download', $file)])->values()->all(),
            'order_state_changed' => false, 'provider_invoice_created' => false, 'delivery_dispatched' => false];
    }
}
