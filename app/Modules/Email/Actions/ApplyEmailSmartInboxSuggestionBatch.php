<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Email\Models\EmailSmartInboxSuggestion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Throwable;

class ApplyEmailSmartInboxSuggestionBatch
{
    public const MAX_ITEMS = 50;

    private const CLEANUP_EFFECTS = [
        EmailSmartInboxSuggestion::EFFECT_ARCHIVE_MAIL,
        EmailSmartInboxSuggestion::EFFECT_MOVE_TO_FOLDER,
    ];

    public function __construct(
        private readonly ApplyEmailSmartInboxSuggestion $applySuggestion,
    ) {}

    /**
     * Snapshot the caller's exact IDs before processing. Each item enters the
     * ordinary single-item action and therefore receives fresh user, mailbox,
     * source, target, and agent authorization independently.
     *
     * @param  array<int, mixed>  $suggestionIds
     * @return array{snapshot_ids: array<int, int>, results: array<int, array<string, mixed>>}
     *
     * @throws ValidationException
     */
    public function handle(array $suggestionIds, User $actor): array
    {
        $snapshotIds = $this->snapshotIds($suggestionIds);
        $results = [];
        $reviewedSourcePlacements = [];

        foreach ($snapshotIds as $suggestionId) {
            $suggestion = EmailSmartInboxSuggestion::query()->find($suggestionId);

            if (! $suggestion) {
                $results[] = $this->failure($suggestionId, 'not_found');

                continue;
            }

            if ((int) $suggestion->user_id !== (int) $actor->id) {
                $results[] = $this->failure($suggestionId, 'not_authorized');

                continue;
            }

            // This batch endpoint is deliberately narrower than the general
            // single-suggestion action. It must never become a shortcut for
            // category, tag, Task, or future cross-domain writes.
            if (! in_array($suggestion->effect_type, self::CLEANUP_EFFECTS, true)) {
                $results[] = $this->failure($suggestionId, 'not_cleanup_effect');

                continue;
            }

            $selectedPlacementId = (int) $suggestion->selected_email_mailbox_placement_id;
            if ($selectedPlacementId > 0 && isset($reviewedSourcePlacements[$selectedPlacementId])) {
                $results[] = $this->failure($suggestionId, 'duplicate_source_placement');

                continue;
            }

            if ($selectedPlacementId > 0) {
                // Reserve the source for the entire fixed batch, including
                // when its first item fails. A later item must not retry or
                // chain-move that same reviewed provider placement implicitly.
                $reviewedSourcePlacements[$selectedPlacementId] = true;
            }

            try {
                $applied = $this->applySuggestion->handle($suggestion, $actor);
                $result = [
                    'suggestion_id' => $suggestionId,
                    'status' => 'succeeded',
                    'reason_code' => null,
                    'applied_reference_type' => $applied->applied_reference_type,
                    'applied_reference_id' => $applied->applied_reference_id,
                ];

                if ($applied->applied_reference_type === ApplyEmailSmartInboxSuggestion::REFERENCE_EMAIL_REMOTE_OPERATION) {
                    $remoteStatus = EmailRemoteOperation::query()
                        ->find((int) $applied->applied_reference_id)?->status;
                    $result['remote_operation_status'] = $remoteStatus;

                    if ($remoteStatus !== EmailRemoteOperation::STATUS_SUCCEEDED) {
                        $result['status'] = 'failed';
                        $result['reason_code'] = 'remote_operation_'.($remoteStatus ?: 'missing');
                    }
                }

                $results[] = $result;
            } catch (AuthorizationException) {
                $results[] = $this->failure($suggestionId, 'not_authorized');
            } catch (ValidationException) {
                $results[] = $this->failure(
                    $suggestionId,
                    match ($suggestion->fresh()?->status) {
                        EmailSmartInboxSuggestion::STATUS_STALE => 'stale',
                        EmailSmartInboxSuggestion::STATUS_REVOKED => 'not_authorized',
                        EmailSmartInboxSuggestion::STATUS_DISMISSED => 'dismissed',
                        default => 'validation_failed',
                    },
                );
            } catch (Throwable) {
                // Batch callers receive stable, non-sensitive per-item
                // evidence while unrelated snapshot items continue.
                $results[] = $this->failure($suggestionId, 'operation_failed');
            }
        }

        return [
            'snapshot_ids' => $snapshotIds,
            'results' => $results,
        ];
    }

    /** @param array<int, mixed> $suggestionIds */
    private function snapshotIds(array $suggestionIds): array
    {
        if (count($suggestionIds) > self::MAX_ITEMS) {
            throw ValidationException::withMessages([
                'suggestion_ids' => 'A Smart Inbox cleanup batch may contain at most '.self::MAX_ITEMS.' items.',
            ]);
        }

        $ids = [];
        foreach ($suggestionIds as $value) {
            if (! is_int($value) && (! is_string($value) || ! ctype_digit($value))) {
                throw ValidationException::withMessages([
                    'suggestion_ids' => 'Every Smart Inbox batch item must be a positive suggestion ID.',
                ]);
            }

            $id = (int) $value;
            if ($id < 1) {
                throw ValidationException::withMessages([
                    'suggestion_ids' => 'Every Smart Inbox batch item must be a positive suggestion ID.',
                ]);
            }

            $ids[$id] = $id;
        }

        return array_values($ids);
    }

    /** @return array{suggestion_id: int, status: string, reason_code: string, applied_reference_type: null, applied_reference_id: null} */
    private function failure(int $suggestionId, string $reasonCode): array
    {
        return [
            'suggestion_id' => $suggestionId,
            'status' => 'failed',
            'reason_code' => $reasonCode,
            'applied_reference_type' => null,
            'applied_reference_id' => null,
        ];
    }
}
