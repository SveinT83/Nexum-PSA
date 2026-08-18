<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailCanonicalCutoverItem;
use App\Modules\Email\Models\EmailCanonicalCutoverRun;
use App\Modules\Email\Models\EmailCanonicalMessage;
use App\Modules\Email\Models\EmailCanonicalMessageSource;
use App\Modules\Email\Models\EmailCanonicalReadMode;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Services\EmailCanonicalCutoverAuthorization;
use App\Modules\Email\Services\EmailCanonicalCutoverEvidence;
use App\Modules\Email\Services\EmailCanonicalProjectionWriter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RollbackEmailCanonicalCutover
{
    public function __construct(
        private readonly EmailCanonicalCutoverAuthorization $authorization,
        private readonly EmailCanonicalCutoverEvidence $evidence,
        private readonly EmailCanonicalProjectionWriter $writer,
    ) {}

    public function handle(EmailCanonicalCutoverRun $run, User $actor): EmailCanonicalCutoverRun
    {
        return DB::transaction(function () use ($run, $actor): EmailCanonicalCutoverRun {
            $run = EmailCanonicalCutoverRun::query()->lockForUpdate()->find($run->id);
            if (! $run) {
                throw ValidationException::withMessages(['run' => 'The cutover run is unavailable.']);
            }
            if ($run->status === EmailCanonicalCutoverRun::STATUS_ROLLED_BACK) {
                return $run;
            }
            if ($run->status !== EmailCanonicalCutoverRun::STATUS_APPLIED) {
                throw ValidationException::withMessages(['run' => 'Only an applied cutover can be rolled back.']);
            }

            $accountIds = collect($run->account_scope_json)
                ->map(fn ($id): int => (int) $id)
                ->sort()
                ->values()
                ->all();
            $authorized = $this->authorization->authorize($actor, $accountIds, true);
            $actor = $authorized['actor'];
            $items = $run->items()->orderBy('item_key')->lockForUpdate()->get();
            if ($items->count() !== (int) $run->item_count
                || $items->contains(fn ($item): bool => $item->status !== EmailCanonicalCutoverItem::STATUS_APPLIED)) {
                throw ValidationException::withMessages(['run' => 'The applied item audit is incomplete.']);
            }

            $this->assertNoLaterAppliedRun($run, $items);
            if ($run->operation === EmailCanonicalCutoverRun::OPERATION_MODE) {
                $this->rollbackModes($items);
            } else {
                $this->rollbackSources($items);
            }

            $items->each(function (EmailCanonicalCutoverItem $item): void {
                $item->forceFill([
                    'status' => EmailCanonicalCutoverItem::STATUS_ROLLED_BACK,
                    'rolled_back_at' => now(),
                ])->save();
            });
            $run->forceFill([
                'status' => EmailCanonicalCutoverRun::STATUS_ROLLED_BACK,
                'rolled_back_by' => $actor->id,
                'rolled_back_count' => $items->count(),
                'rolled_back_at' => now(),
            ])->save();

            return $run->refresh();
        }, 3);
    }

    private function rollbackSources(Collection $items): void
    {
        $sourceIds = $items->pluck('source_email_message_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->all();
        $mappings = EmailCanonicalMessageSource::query()
            ->whereIn('source_email_message_id', $sourceIds)
            ->orderBy('source_email_message_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('source_email_message_id');
        $placements = EmailMailboxPlacement::query()
            ->whereIn('email_message_id', $sourceIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->groupBy('email_message_id');

        $this->assertPreviousComponentsAreSafe($items);

        foreach ($items as $item) {
            $mapping = $mappings->get($item->source_email_message_id);
            if (! $mapping
                || (int) $mapping->canonical_email_message_id !== (int) $item->applied_canonical_email_message_id) {
                throw ValidationException::withMessages(['run' => 'A source mapping changed after this cutover was applied.']);
            }

            $sourcePlacements = $placements->get($item->source_email_message_id, collect());
            $currentKeys = $sourcePlacements->pluck('id')->map(fn ($id): string => (string) $id)->sort()->values()->all();
            $previousPointers = collect($item->previous_placement_pointers_json ?? [])
                ->mapWithKeys(fn ($value, $key): array => [(string) $key => $value === null ? null : (int) $value]);
            $previousKeys = $previousPointers->keys()->sort()->values()->all();
            $previousKeys = collect($previousKeys)->map(fn ($id): string => (string) $id)->all();
            if ($currentKeys !== $previousKeys
                || $sourcePlacements->contains(fn ($placement): bool => (int) $placement->canonical_email_message_id
                    !== (int) $item->applied_canonical_email_message_id)) {
                throw ValidationException::withMessages(['run' => 'Placement identity or pointers changed after this cutover.']);
            }
        }

        $touchedCanonicalIds = [];
        foreach ($items as $item) {
            $mapping = $mappings->get($item->source_email_message_id);
            $touchedCanonicalIds[] = (int) $mapping->canonical_email_message_id;

            if ($item->item_kind !== EmailCanonicalCutoverItem::KIND_POINTER_REPAIR) {
                if ($item->previous_canonical_email_message_id === null) {
                    $mapping->delete();
                } else {
                    if ($item->previous_mapped_by !== null
                        && ! User::query()->whereKey($item->previous_mapped_by)->exists()) {
                        throw ValidationException::withMessages(['run' => 'The prior mapping actor no longer exists.']);
                    }
                    $mapping->forceFill([
                        'canonical_email_message_id' => $item->previous_canonical_email_message_id,
                        'mapping_kind' => $item->previous_mapping_kind,
                        'strict_evidence_hash' => $item->previous_evidence_hash,
                        'source_state_hash' => $item->previous_source_state_hash,
                        'evidence_complete' => $item->previous_evidence_complete,
                        'mapped_by' => $item->previous_mapped_by,
                        'mapped_at' => $item->previous_mapped_at,
                    ])->save();
                    $touchedCanonicalIds[] = (int) $item->previous_canonical_email_message_id;
                }
            }

            foreach ($item->previous_placement_pointers_json ?? [] as $placementId => $pointer) {
                EmailMailboxPlacement::query()
                    ->whereKey((int) $placementId)
                    ->where('email_message_id', $item->source_email_message_id)
                    ->update(['canonical_email_message_id' => $pointer]);
            }
        }

        $this->writer->refreshComponentCounts($touchedCanonicalIds);
    }

    private function assertPreviousComponentsAreSafe(Collection $items): void
    {
        $restoredItems = $items->filter(
            fn (EmailCanonicalCutoverItem $item): bool => $item->item_kind !== EmailCanonicalCutoverItem::KIND_POINTER_REPAIR
                && $item->previous_canonical_email_message_id !== null,
        );
        if ($restoredItems->isEmpty()) {
            return;
        }

        $messages = EmailMessage::query()
            ->whereKey($restoredItems->pluck('source_email_message_id'))
            ->with(['account:id,address', 'attachments'])
            ->get()
            ->keyBy('id');
        $canonicals = EmailCanonicalMessage::query()
            ->whereKey($restoredItems->pluck('previous_canonical_email_message_id'))
            ->with(['attachments', 'rootSource.account:id,address', 'rootSource.attachments'])
            ->get()
            ->keyBy('id');
        $bytes = 0;
        foreach ($restoredItems as $item) {
            $message = $messages->get($item->source_email_message_id);
            $canonical = $canonicals->get($item->previous_canonical_email_message_id);
            if (! $message || ! $canonical) {
                throw ValidationException::withMessages(['run' => 'The prior canonical evidence is unavailable.']);
            }
            $snapshot = $this->evidence->forMessage($message);
            $bytes += (int) $snapshot['evidence_bytes'];
            if ($bytes > PreviewEmailCanonicalCutover::MAX_EVIDENCE_BYTES
                || ! hash_equals((string) $canonical->strict_evidence_hash, (string) $snapshot['strict_evidence_hash'])
                || ! hash_equals((string) $canonical->root_projection_hash, $this->evidence->storedProjectionHash($canonical))) {
                throw ValidationException::withMessages([
                    'run' => 'Restoring the prior component would reintroduce divergent content.',
                ]);
            }
        }

        foreach ($canonicals as $canonical) {
            $root = $canonical->rootSource;
            if (! $root) {
                throw ValidationException::withMessages(['run' => 'The prior canonical root is unavailable.']);
            }
            $rootSnapshot = $this->evidence->forMessage($root);
            $bytes += (int) $rootSnapshot['evidence_bytes'];
            if ($bytes > PreviewEmailCanonicalCutover::MAX_EVIDENCE_BYTES
                || ! hash_equals((string) $canonical->strict_evidence_hash, (string) $rootSnapshot['strict_evidence_hash'])
                || ! hash_equals((string) $canonical->root_projection_hash, (string) $rootSnapshot['root_projection_hash'])) {
                throw ValidationException::withMessages([
                    'run' => 'The prior canonical root no longer matches its retained projection.',
                ]);
            }
        }
    }

    private function rollbackModes(Collection $items): void
    {
        foreach ($items as $item) {
            $mode = EmailCanonicalReadMode::query()
                ->where('email_account_id', $item->email_account_id)
                ->lockForUpdate()
                ->first();
            $expectedLockVersion = (int) ($item->previous_read_mode_lock_version ?? 0) + 1;
            if (! $mode
                || $mode->mode !== $item->proposed_read_mode
                || (int) $mode->lock_version !== $expectedLockVersion) {
                throw ValidationException::withMessages(['run' => 'An account read mode changed after this cutover.']);
            }

            if (! $item->previous_read_mode_row_exists) {
                $mode->delete();

                continue;
            }
            if ($item->previous_read_mode_updated_by !== null
                && ! User::query()->whereKey($item->previous_read_mode_updated_by)->exists()) {
                throw ValidationException::withMessages(['run' => 'The prior mode actor no longer exists.']);
            }
            $mode->forceFill([
                'mode' => $item->previous_read_mode,
                'updated_by' => $item->previous_read_mode_updated_by,
                'lock_version' => $item->previous_read_mode_lock_version,
            ])->save();
        }
    }

    private function assertNoLaterAppliedRun(
        EmailCanonicalCutoverRun $run,
        Collection $items,
    ): void {
        $sourceIds = $items->pluck('source_email_message_id')->filter()->all();
        $accountIds = $items->pluck('email_account_id')->unique()->all();
        $later = EmailCanonicalCutoverRun::query()
            ->whereKeyNot($run->id)
            ->where('status', EmailCanonicalCutoverRun::STATUS_APPLIED)
            ->where('applied_at', '>', $run->applied_at)
            ->whereHas('items', function ($query) use ($accountIds, $sourceIds): void {
                $query->where(function ($items) use ($accountIds, $sourceIds): void {
                    if ($sourceIds !== []) {
                        $items->whereIn('source_email_message_id', $sourceIds);
                    }
                    if ($accountIds !== []) {
                        $sourceIds !== []
                            ? $items->orWhereIn('email_account_id', $accountIds)
                            : $items->whereIn('email_account_id', $accountIds);
                    }
                });
            })
            ->exists();
        if ($later) {
            throw ValidationException::withMessages([
                'run' => 'Roll back later overlapping cutovers first.',
            ]);
        }
    }
}
