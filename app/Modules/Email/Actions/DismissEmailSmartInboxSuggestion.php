<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailSmartInboxSuggestion;
use App\Modules\Email\Models\EmailSmartInboxSuggestionEvent;
use App\Modules\Email\Services\EmailSmartInboxSuggestionEventRecorder;
use App\Modules\Email\Services\EmailSmartInboxSuggestionStateService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DismissEmailSmartInboxSuggestion
{
    public function __construct(
        private readonly EmailSmartInboxSuggestionStateService $stateService,
        private readonly EmailSmartInboxSuggestionEventRecorder $eventRecorder,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(
        EmailSmartInboxSuggestion $suggestion,
        User $actor,
    ): EmailSmartInboxSuggestion {
        $result = DB::transaction(function () use ($suggestion, $actor): array {
            $locked = EmailSmartInboxSuggestion::query()
                ->lockForUpdate()
                ->findOrFail($suggestion->id);
            $locked = $this->stateService->evaluateLocked($locked, $actor);

            if ($locked->status === EmailSmartInboxSuggestion::STATUS_REVOKED) {
                return ['suggestion' => $locked, 'error' => 'revoked'];
            }

            if ($locked->status === EmailSmartInboxSuggestion::STATUS_STALE) {
                return ['suggestion' => $locked, 'error' => 'stale'];
            }

            if ($locked->status === EmailSmartInboxSuggestion::STATUS_DISMISSED) {
                return ['suggestion' => $locked, 'error' => null];
            }

            if ($locked->status !== EmailSmartInboxSuggestion::STATUS_PENDING) {
                return ['suggestion' => $locked, 'error' => 'terminal'];
            }

            $before = $this->eventRecorder->snapshot($locked);
            $locked->forceFill([
                'status' => EmailSmartInboxSuggestion::STATUS_DISMISSED,
                'dismissed_by' => $actor->id,
                'dismissed_at' => now(),
            ])->save();
            $this->eventRecorder->record(
                $locked,
                EmailSmartInboxSuggestionEvent::TYPE_DISMISSED,
                $actor,
                $before,
                'user_dismissed',
            );

            return ['suggestion' => $locked->refresh(), 'error' => null];
        });

        if ($result['error'] === 'revoked') {
            throw new AuthorizationException('Smart Inbox suggestion not found.');
        }

        if ($result['error'] === 'stale') {
            throw ValidationException::withMessages([
                'suggestion' => 'This suggestion is stale because the Mail conversation changed.',
            ]);
        }

        if ($result['error'] === 'terminal') {
            throw ValidationException::withMessages([
                'suggestion' => 'This suggestion can no longer be dismissed.',
            ]);
        }

        return $result['suggestion'];
    }
}
