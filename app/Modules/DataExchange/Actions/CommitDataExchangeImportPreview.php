<?php

namespace App\Modules\DataExchange\Actions;

use App\Models\Core\User;
use App\Modules\DataExchange\Models\DataExchangeAuditEvent;
use App\Modules\DataExchange\Models\DataExchangeImportPreview;
use App\Modules\DataExchange\Models\DataExchangeRun;
use App\Modules\DataExchange\Support\DataExchangeSourceRegistry;

class CommitDataExchangeImportPreview
{
    public function __construct(private readonly DataExchangeSourceRegistry $sources) {}

    public function handle(DataExchangeImportPreview $preview, ?User $actor = null): DataExchangeImportPreview
    {
        return app(\App\Modules\DataExchange\Support\DataExchangeMutex::class)->run(function () use ($preview, $actor) {
            $preview = DataExchangeImportPreview::query()->lockForUpdate()->findOrFail($preview->id);
            $profile = $preview->profile()->with(['sources', 'fields', 'mappings'])->firstOrFail();
            \App\Modules\DataExchange\Support\DataExchangeAuthority::runtime($profile, $actor, 'approve_import');
            $source = $this->sources->assertProfile($profile, 'import');
            if ($preview->status === DataExchangeImportPreview::STATUS_COMMITTED) {
                return $preview;
            }
            abort_unless($profile->status === 'active' && $preview->status === DataExchangeImportPreview::STATUS_PREVIEWED && $preview->invalid_count === 0, 422, 'Import preview is not commit-ready.');
            $review = data_get($preview->summary, 'review', []);
            abort_unless(isset($review['profile_revision']) && hash_equals($review['profile_revision'], \App\Modules\DataExchange\Support\DataExchangeRevision::of($profile)), 409, 'The profile changed; create a new preview.');
            abort_unless(($review['source_revision'] ?? null) === $source->importRevision(), 409, 'The target data or provider authority changed; create a new preview.');
            abort_unless(hash_equals($review['rows_hash'] ?? '', \App\Modules\DataExchange\Support\DataExchangeRevision::hash([$preview->rows, $preview->mapping])), 409, 'The reviewed import content changed.');
            $revalidated = $source->previewImportRows($profile, array_column($preview->rows, 'input'), $preview->mapping, $actor);
            abort_unless(($revalidated['errors'] ?? []) === [] && \App\Modules\DataExchange\Support\DataExchangeRevision::hash($revalidated['rows']) === \App\Modules\DataExchange\Support\DataExchangeRevision::hash($preview->rows), 409, 'The target validation changed; create a new preview.');
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
                'summary' => array_merge((array) $preview->summary, ['commit' => $summary, 'commit_run_id' => $run->id]),
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
