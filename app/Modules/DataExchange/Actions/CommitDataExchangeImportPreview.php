<?php

namespace App\Modules\DataExchange\Actions;

use App\Models\Core\User;
use App\Modules\DataExchange\Models\DataExchangeAuditEvent;
use App\Modules\DataExchange\Models\DataExchangeImportPreview;
use App\Modules\DataExchange\Models\DataExchangeRun;
use App\Modules\DataExchange\Support\DataExchangeSourceRegistry;
use Illuminate\Support\Facades\DB;

class CommitDataExchangeImportPreview
{
    public function __construct(private readonly DataExchangeSourceRegistry $sources) {}

    public function handle(DataExchangeImportPreview $preview, ?User $actor = null): DataExchangeImportPreview
    {
        abort_unless($preview->status === DataExchangeImportPreview::STATUS_PREVIEWED, 422, 'Import preview is not commit-ready.');
        abort_unless($preview->invalid_count === 0, 422, 'Import preview has invalid rows.');

        $profile = $preview->profile()->with(['sources', 'fields', 'mappings'])->firstOrFail();
        $source = $this->sources->get((string) $preview->source_key);

        abort_unless($source && $source->supportsImport, 422, 'The profile source is not importable.');

        return DB::transaction(function () use ($preview, $profile, $source, $actor): DataExchangeImportPreview {
            $summary = $source->commitImportRows($profile, (array) $preview->rows, $actor);

            $run = DataExchangeRun::query()->create([
                'profile_id' => $profile->id,
                'direction' => $profile->direction,
                'status' => DataExchangeRun::STATUS_SUCCEEDED,
                'trigger_type' => 'manual_commit',
                'triggered_by' => $actor?->id,
                'started_at' => now(),
                'finished_at' => now(),
                'summary' => array_merge(['preview_id' => $preview->id], $summary),
            ]);

            $preview->forceFill([
                'status' => DataExchangeImportPreview::STATUS_COMMITTED,
                'committed_at' => now(),
                'committed_by' => $actor?->id,
                'summary' => array_merge((array) $preview->summary, ['commit' => $summary]),
            ])->save();

            DataExchangeAuditEvent::query()->create([
                'profile_id' => $profile->id,
                'run_id' => $run->id,
                'event_type' => 'import_committed',
                'outcome' => 'succeeded',
                'actor_id' => $actor?->id,
                'metadata' => ['preview_id' => $preview->id, 'summary' => $summary],
                'occurred_at' => now(),
            ]);

            return $preview->refresh();
        });
    }
}
