<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Actions\PerformEmailRemoteOperation;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailRuleExecutionAttempt;
use App\Modules\Taxonomy\Models\Tag;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class EmailRuleReversalService
{
    public function __construct(
        private readonly PerformEmailRemoteOperation $performRemoteOperation
    ) {
    }

    /**
     * Revert the effects of a rule execution attempt.
     */
    public function revert(EmailRuleExecutionAttempt $attempt): array
    {
        if ($attempt->status !== EmailRuleExecutionAttempt::STATUS_SUCCEEDED) {
            throw new RuntimeException('Only successful rule executions can be reverted.');
        }

        return DB::transaction(function () use ($attempt) {
            $results = $attempt->action_results_json ?? [];
            $reversalLogs = [];

            foreach (array_reverse($results) as $result) {
                if (($result['status'] ?? '') !== EmailRuleExecutionAttempt::STATUS_SUCCEEDED) {
                    continue;
                }

                $action = $result['action'] ?? [];
                $type = $action['type'] ?? '';

                $reversalResult = match ($type) {
                    'tag' => $this->revertTag($attempt->message, $action),
                    'move_to_folder', 'archive', 'trash' => $this->revertPlacementChange($attempt->message, $result),
                    default => ['status' => 'skipped', 'reason' => 'action_not_reversible'],
                };

                $reversalLogs[] = [
                    'action_type' => $type,
                    'result' => $reversalResult,
                ];
            }

            $attempt->update([
                'status' => 'reverted',
                'action_results_json' => array_merge($results, ['reversal' => $reversalLogs]),
            ]);

            return $reversalLogs;
        });
    }

    private function revertTag(EmailMessage $message, array $action): array
    {
        $tagName = $action['tag'] ?? null;
        if (!$tagName) return ['status' => 'skipped'];

        $tag = Tag::where('name', $tagName)->first();
        if ($tag) {
            $message->tags()->detach($tag->id);
            return ['status' => 'succeeded', 'tag' => $tagName];
        }

        return ['status' => 'failed', 'reason' => 'tag_not_found'];
    }

    private function revertPlacementChange(EmailMessage $message, array $result): array
    {
        $before = $result['before'] ?? null;
        if (!$before || !isset($before['folder_id'])) {
            return ['status' => 'failed', 'reason' => 'no_before_state_recorded'];
        }

        $placement = EmailMailboxPlacement::where('email_message_id', $message->id)
            ->where('email_account_id', $message->account_id)
            ->first();

        if (!$placement) {
            return ['status' => 'failed', 'reason' => 'placement_not_found'];
        }

        // We use the performRemoteOperation to move it back if possible,
        // but that might create a new remote operation.
        // For a true "Undo", we might just want to update local state if it's a read-only reconciliation domain.
        // However, standard rules often move mail on the provider too.

        $targetFolderId = (int) $before['folder_id'];

        // This is a simplified reversal. A robust one would check if the folder still exists.
        $placement->update(['email_folder_id' => $targetFolderId]);

        return ['status' => 'succeeded', 'target_folder_id' => $targetFolderId];
    }
}
