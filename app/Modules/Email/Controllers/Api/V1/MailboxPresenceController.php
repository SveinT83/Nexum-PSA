<?php

namespace App\Modules\Email\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Core\User;
use App\Modules\Email\Models\EmailConversation;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Services\EmailCollaborationGate;
use App\Modules\Email\Services\EmailCollaborationPresenceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Email Mail collaboration', description: 'Default-off shared Mail coordination without content-bearing events.')]
class MailboxPresenceController extends Controller
{
    #[OA\Get(path: '/api/v1/email/mailbox/conversations/{conversation}/presence', operationId: 'showEmailMailboxPresence', summary: 'Show current authorized expiring reading/typing presence', security: [['bearerAuth' => []]], tags: ['Email Mail collaboration'], responses: [new OA\Response(response: 200, description: 'Current permission-filtered presence'), new OA\Response(response: 404, description: 'Conversation/source outside current scope'), new OA\Response(response: 503, description: 'Collaboration remains disabled')])]
    public function show(
        Request $request,
        string $conversation,
        EmailCollaborationGate $gate,
        EmailCollaborationPresenceService $presence,
    ): JsonResponse {
        $this->ensureAvailable($gate);
        $validated = $request->validate(['source_placement_id' => ['required', 'integer']]);
        [$actor, $conversation, $placement] = $this->context($request, $conversation, $validated['source_placement_id']);

        try {
            return response()->json(['data' => $presence->snapshot($actor, $conversation, $placement)]);
        } catch (AuthorizationException) {
            abort(404);
        }
    }

    #[OA\Post(path: '/api/v1/email/mailbox/conversations/{conversation}/presence', operationId: 'heartbeatEmailMailboxPresence', summary: 'Refresh one bounded per-tab reading or typing presence hint', security: [['bearerAuth' => []]], tags: ['Email Mail collaboration'], responses: [new OA\Response(response: 200, description: 'Heartbeat accepted or presence safely unavailable'), new OA\Response(response: 404, description: 'Conversation/source outside current scope'), new OA\Response(response: 503, description: 'Collaboration remains disabled')])]
    public function heartbeat(
        Request $request,
        string $conversation,
        EmailCollaborationGate $gate,
        EmailCollaborationPresenceService $presence,
    ): JsonResponse {
        $this->ensureAvailable($gate);
        $validated = $request->validate([
            'source_placement_id' => ['required', 'integer'],
            'activity' => ['required', Rule::in([
                EmailCollaborationPresenceService::ACTIVITY_READING,
                EmailCollaborationPresenceService::ACTIVITY_TYPING,
            ])],
            'tab_token' => ['required', 'string', 'min:24', 'max:160'],
        ]);
        [$actor, $conversation, $placement] = $this->context($request, $conversation, $validated['source_placement_id']);

        try {
            $accepted = $presence->heartbeat(
                $actor,
                $conversation,
                $placement,
                $validated['activity'],
                $validated['tab_token'],
            );
        } catch (AuthorizationException) {
            abort(404);
        }

        return response()->json(['data' => [
            'accepted' => $accepted !== null,
            'activity' => $validated['activity'],
            'expires_at' => $accepted
                ? now()->setTimestamp((int) $accepted['expires_at'])->toIso8601String()
                : null,
        ]]);
    }

    #[OA\Delete(path: '/api/v1/email/mailbox/conversations/{conversation}/presence', operationId: 'leaveEmailMailboxPresence', summary: 'Best-effort leave for one opaque tab presence hint', security: [['bearerAuth' => []]], tags: ['Email Mail collaboration'], responses: [new OA\Response(response: 204, description: 'Presence removed or left to expire'), new OA\Response(response: 503, description: 'Collaboration remains disabled')])]
    public function leave(
        Request $request,
        string $conversation,
        EmailCollaborationGate $gate,
        EmailCollaborationPresenceService $presence,
    ): JsonResponse {
        $this->ensureAvailable($gate);
        $validated = $request->validate([
            'source_placement_id' => ['required', 'integer'],
            'activity' => ['required', Rule::in([
                EmailCollaborationPresenceService::ACTIVITY_READING,
                EmailCollaborationPresenceService::ACTIVITY_TYPING,
            ])],
            'tab_token' => ['required', 'string', 'min:24', 'max:160'],
        ]);
        [$actor, $conversation, $placement] = $this->context($request, $conversation, $validated['source_placement_id']);

        try {
            $presence->leave($actor, $conversation, $placement, $validated['activity'], $validated['tab_token']);
        } catch (AuthorizationException) {
            abort(404);
        }

        return response()->json(null, 204);
    }

    /** @return array{User, EmailConversation, EmailMailboxPlacement} */
    private function context(Request $request, string $conversationId, int $placementId): array
    {
        $actor = $request->user();
        abort_if(! $actor?->isActive() || $actor->isSystemActor(), 403);
        $conversation = EmailConversation::query()->findOrFail($conversationId);
        $placement = EmailMailboxPlacement::query()
            ->with('message')
            ->whereKey($placementId)
            ->where('account_id', $conversation->account_id)
            ->where('email_conversation_id', $conversation->id)
            ->firstOrFail();

        return [$actor, $conversation, $placement];
    }

    private function ensureAvailable(EmailCollaborationGate $gate): void
    {
        abort_unless($gate->available(), 503, 'Mail collaboration is not available.');
    }
}
