<?php

namespace App\Modules\Email\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Email\Actions\AnalyzeEmailConversationForSmartInbox;
use App\Modules\Email\Actions\ApplyEmailSmartInboxSuggestion;
use App\Modules\Email\Actions\CorrectEmailSmartInboxSuggestion;
use App\Modules\Email\Actions\DismissEmailSmartInboxSuggestion;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailConversation;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailSmartInboxSuggestion;
use App\Modules\Email\Resources\Api\V1\EmailSmartInboxSuggestionResource;
use App\Modules\Email\Services\EmailSmartInboxSuggestionStateService;
use App\Modules\Email\Services\MailboxAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Email', description: 'API endpoints for inbox messages and email operations.')]
class SmartInboxSuggestionsController extends Controller
{
    #[OA\Get(
        path: '/api/v1/email/smart-inbox/suggestions',
        operationId: 'listEmailSmartInboxSuggestions',
        summary: 'List the current user Smart Inbox review queue',
        security: [['bearerAuth' => []]],
        tags: ['Email'],
        responses: [
            new OA\Response(response: 200, description: 'User and mailbox scoped suggestion queue'),
            new OA\Response(response: 404, description: 'Requested mailbox is outside the caller scope'),
        ],
    )]
    public function index(
        Request $request,
        MailboxAccess $mailboxAccess,
        EmailSmartInboxSuggestionStateService $stateService,
    ) {
        $validated = $request->validate([
            'account_id' => ['nullable', 'integer'],
            'conversation_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', Rule::in([
                EmailSmartInboxSuggestion::STATUS_PENDING,
                EmailSmartInboxSuggestion::STATUS_DISMISSED,
                EmailSmartInboxSuggestion::STATUS_APPLIED,
                EmailSmartInboxSuggestion::STATUS_STALE,
            ])],
            'effect_type' => ['nullable', 'string', Rule::in([
                EmailSmartInboxSuggestion::EFFECT_REVIEW_SUMMARY,
                EmailSmartInboxSuggestion::EFFECT_APPLY_CATEGORY,
                EmailSmartInboxSuggestion::EFFECT_APPLY_TAG,
                EmailSmartInboxSuggestion::EFFECT_CREATE_TASK,
                EmailSmartInboxSuggestion::EFFECT_ARCHIVE_MAIL,
                EmailSmartInboxSuggestion::EFFECT_MOVE_TO_FOLDER,
            ])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $accountIds = $this->visibleAccountIds($request, $mailboxAccess);
        $this->refreshQueueState($request, $stateService);

        if (isset($validated['account_id']) && ! $accountIds->contains((int) $validated['account_id'])) {
            abort(404);
        }

        $suggestions = EmailSmartInboxSuggestion::query()
            ->with(['aiAgent:id,name'])
            ->where('user_id', $request->user()->id)
            ->whereIn('account_id', $accountIds)
            ->where('status', '!=', EmailSmartInboxSuggestion::STATUS_REVOKED)
            ->when(isset($validated['account_id']), fn ($query) => $query->where('account_id', $validated['account_id']))
            ->when(isset($validated['conversation_id']), fn ($query) => $query->where('email_conversation_id', $validated['conversation_id']))
            ->when(isset($validated['status']), fn ($query) => $query->where('status', $validated['status']))
            ->when(isset($validated['effect_type']), fn ($query) => $query->where('effect_type', $validated['effect_type']))
            ->latest('generated_at')
            ->latest('id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        // Re-evaluate only the bounded page being viewed. Revoked rows are
        // removed from this response and remain as inaccessible audit facts.
        $suggestions->setCollection(
            $suggestions->getCollection()
                ->map(fn (EmailSmartInboxSuggestion $suggestion) => $stateService
                    ->refresh($suggestion, $request->user())
                    ->load('aiAgent:id,name'))
                ->reject(fn (EmailSmartInboxSuggestion $suggestion): bool => $suggestion->status === EmailSmartInboxSuggestion::STATUS_REVOKED)
                ->values(),
        );

        return EmailSmartInboxSuggestionResource::collection($suggestions);
    }

    #[OA\Get(
        path: '/api/v1/email/smart-inbox/suggestions/count',
        operationId: 'countEmailSmartInboxSuggestions',
        summary: 'Count current user Smart Inbox suggestions by state',
        security: [['bearerAuth' => []]],
        tags: ['Email'],
        responses: [new OA\Response(response: 200, description: 'Suggestion counts')],
    )]
    public function count(
        Request $request,
        MailboxAccess $mailboxAccess,
        EmailSmartInboxSuggestionStateService $stateService,
    ): JsonResponse {
        $accountIds = $this->visibleAccountIds($request, $mailboxAccess);
        $this->refreshQueueState($request, $stateService);

        $counts = EmailSmartInboxSuggestion::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('account_id', $accountIds)
            ->where('status', '!=', EmailSmartInboxSuggestion::STATUS_REVOKED)
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn (mixed $count): int => (int) $count);

        return response()->json([
            'data' => [
                'pending' => $counts->get(EmailSmartInboxSuggestion::STATUS_PENDING, 0),
                'applied' => $counts->get(EmailSmartInboxSuggestion::STATUS_APPLIED, 0),
                'dismissed' => $counts->get(EmailSmartInboxSuggestion::STATUS_DISMISSED, 0),
                'stale' => $counts->get(EmailSmartInboxSuggestion::STATUS_STALE, 0),
                'total' => $counts->sum(),
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/v1/email/smart-inbox/suggestions/{suggestion}',
        operationId: 'showEmailSmartInboxSuggestion',
        summary: 'Show one current user Smart Inbox suggestion',
        security: [['bearerAuth' => []]],
        tags: ['Email'],
        responses: [
            new OA\Response(response: 200, description: 'Suggestion detail and audit events'),
            new OA\Response(response: 404, description: 'Suggestion not found in caller scope'),
        ],
    )]
    public function show(
        Request $request,
        int $suggestion,
        EmailSmartInboxSuggestionStateService $stateService,
    ): EmailSmartInboxSuggestionResource {
        $visible = $this->visibleSuggestion($request, $suggestion, $stateService);

        return new EmailSmartInboxSuggestionResource($visible->load(['aiAgent:id,name', 'events']));
    }

    #[OA\Post(
        path: '/api/v1/email/mailbox/conversations/{conversation}/smart-inbox/analyze',
        operationId: 'analyzeEmailConversationForSmartInbox',
        summary: 'Explicitly analyze one authorized Mail conversation',
        security: [['bearerAuth' => []]],
        tags: ['Email'],
        responses: [
            new OA\Response(response: 200, description: 'Durable non-writing review suggestions'),
            new OA\Response(response: 404, description: 'Conversation or placement not found in caller scope'),
            new OA\Response(response: 422, description: 'AI governance or source state blocked analysis'),
        ],
    )]
    public function analyze(
        Request $request,
        int $conversation,
        MailboxAccess $mailboxAccess,
        AnalyzeEmailConversationForSmartInbox $analyze,
    ) {
        $validated = $request->validate([
            'placement_id' => ['required', 'integer'],
        ]);
        $accountIds = $this->visibleAccountIds($request, $mailboxAccess);
        $durableConversation = EmailConversation::query()
            ->whereKey($conversation)
            ->whereIn('account_id', $accountIds)
            ->where('status', EmailConversation::STATUS_ACTIVE)
            ->firstOrFail();
        $placement = EmailMailboxPlacement::query()
            ->whereKey($validated['placement_id'])
            ->where('account_id', $durableConversation->account_id)
            ->where('email_conversation_id', $durableConversation->id)
            ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
            ->firstOrFail();

        $suggestions = $analyze->handle($durableConversation, $placement, $request->user())
            ->each->load('aiAgent:id,name');

        return EmailSmartInboxSuggestionResource::collection($suggestions);
    }

    #[OA\Post(
        path: '/api/v1/email/smart-inbox/suggestions/{suggestion}/dismiss',
        operationId: 'dismissEmailSmartInboxSuggestion',
        summary: 'Dismiss one current Smart Inbox suggestion',
        security: [['bearerAuth' => []]],
        tags: ['Email'],
        parameters: [
            new OA\Parameter(name: 'suggestion', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Dismissed suggestion and audit events'),
            new OA\Response(response: 404, description: 'Suggestion not found in caller scope'),
            new OA\Response(response: 422, description: 'Suggestion is stale or terminal'),
        ],
    )]
    public function dismiss(
        Request $request,
        int $suggestion,
        EmailSmartInboxSuggestionStateService $stateService,
        DismissEmailSmartInboxSuggestion $dismiss,
    ): EmailSmartInboxSuggestionResource {
        $visible = $this->visibleSuggestion($request, $suggestion, $stateService);

        return new EmailSmartInboxSuggestionResource(
            $dismiss->handle($visible, $request->user())->load(['aiAgent:id,name', 'events']),
        );
    }

    #[OA\Patch(
        path: '/api/v1/email/smart-inbox/suggestions/{suggestion}',
        operationId: 'correctEmailSmartInboxSuggestion',
        summary: 'Correct one current Smart Inbox suggestion',
        security: [['bearerAuth' => []]],
        tags: ['Email'],
        parameters: [
            new OA\Parameter(name: 'suggestion', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Corrected bounded proposal and audit events'),
            new OA\Response(response: 404, description: 'Suggestion not found in caller scope'),
            new OA\Response(response: 422, description: 'Suggestion is stale, terminal, or invalid'),
        ],
    )]
    public function correct(
        Request $request,
        int $suggestion,
        EmailSmartInboxSuggestionStateService $stateService,
        CorrectEmailSmartInboxSuggestion $correct,
    ): EmailSmartInboxSuggestionResource {
        $validated = $request->validate([
            'proposal' => ['required', 'array'],
            'explanation' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'confidence' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
        ]);
        $visible = $this->visibleSuggestion($request, $suggestion, $stateService);

        return new EmailSmartInboxSuggestionResource(
            $correct->handle(
                $visible,
                $request->user(),
                $validated['proposal'],
                $validated['explanation'] ?? null,
                $validated['confidence'] ?? null,
            )->load(['aiAgent:id,name', 'events']),
        );
    }

    #[OA\Post(
        path: '/api/v1/email/smart-inbox/suggestions/{suggestion}/apply',
        operationId: 'applyEmailSmartInboxSuggestion',
        summary: 'Apply one reviewed and currently authorized Smart Inbox suggestion',
        security: [['bearerAuth' => []]],
        tags: ['Email'],
        parameters: [
            new OA\Parameter(name: 'suggestion', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Applied suggestion with durable domain reference'),
            new OA\Response(response: 403, description: 'Current target or agent authority is missing'),
            new OA\Response(response: 404, description: 'Suggestion not found in caller scope'),
            new OA\Response(response: 422, description: 'Suggestion is stale, terminal, conflicted, or invalid'),
        ],
    )]
    public function apply(
        Request $request,
        int $suggestion,
        EmailSmartInboxSuggestionStateService $stateService,
        ApplyEmailSmartInboxSuggestion $apply,
    ): EmailSmartInboxSuggestionResource {
        $visible = $this->visibleSuggestion($request, $suggestion, $stateService);
        abort_unless(
            $visible->effect_type !== EmailSmartInboxSuggestion::EFFECT_CREATE_TASK
                || $request->user()->tokenCan('tasks.create'),
            403,
            'The current API token cannot create Tasks.',
        );

        return new EmailSmartInboxSuggestionResource(
            $apply->handle($visible, $request->user())->load(['aiAgent:id,name', 'events']),
        );
    }

    private function visibleSuggestion(
        Request $request,
        int $suggestionId,
        EmailSmartInboxSuggestionStateService $stateService,
    ): EmailSmartInboxSuggestion {
        $suggestion = EmailSmartInboxSuggestion::query()
            ->whereKey($suggestionId)
            ->where('user_id', $request->user()->id)
            ->where('status', '!=', EmailSmartInboxSuggestion::STATUS_REVOKED)
            ->firstOrFail();
        $suggestion = $stateService->refresh($suggestion, $request->user());

        abort_if($suggestion->status === EmailSmartInboxSuggestion::STATUS_REVOKED, 404);

        return $suggestion;
    }

    /** @return Collection<int, int> */
    private function visibleAccountIds(Request $request, MailboxAccess $mailboxAccess): Collection
    {
        return $mailboxAccess->scopeAccounts(
            EmailAccount::query()->where('is_active', true),
            $request->user(),
            MailboxAccess::VIEW,
        )->pluck('id');
    }

    /**
     * Keep stale/revoked state current without scanning an unbounded
     * historical queue. The later scoped query still returns no data from an
     * inaccessible mailbox.
     */
    private function refreshQueueState(
        Request $request,
        EmailSmartInboxSuggestionStateService $stateService,
    ): void {
        EmailSmartInboxSuggestion::query()
            ->where('user_id', $request->user()->id)
            ->whereNotIn('status', [
                EmailSmartInboxSuggestion::STATUS_REVOKED,
                EmailSmartInboxSuggestion::STATUS_APPLIED,
            ])
            ->latest('generated_at')
            ->latest('id')
            ->limit(100)
            ->get()
            ->each(fn (EmailSmartInboxSuggestion $suggestion) => $stateService
                ->refresh($suggestion, $request->user()));
    }
}
