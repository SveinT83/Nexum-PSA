<?php

namespace App\Modules\Email\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Settings\CommonSetting;
use App\Modules\Email\Actions\MarkEmailAsSpam;
use App\Modules\Email\Jobs\FetchImapAccount;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Resources\Api\V1\EmailMessageResource;
use App\Modules\Email\Services\EmailCanonicalContentResolver;
use App\Modules\Email\Services\MailboxAccess;
use App\Modules\Email\Services\MailboxAccessUseGuard;
use App\Modules\Email\Services\ResolveMailboxAccessDecision;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Email',
    description: 'API endpoints for inbox messages and email operations.'
)]
class InboxController extends Controller
{
    public function messages(
        Request $request,
        MailboxAccess $mailboxAccess,
        MailboxAccessUseGuard $access,
        EmailCanonicalContentResolver $canonicalContent,
    ) {
        $term = trim((string) $request->input('q'));
        $operation = $term !== ''
            ? ResolveMailboxAccessDecision::SEARCH
            : ResolveMailboxAccessDecision::CONTENT_VIEW;
        $accounts = $mailboxAccess->scopeContentAccounts(
            EmailAccount::query()->where('is_active', true),
            $request->user(),
            $operation,
        )->get();

        if ($request->filled('account_id')) {
            $accounts = $accounts->where('id', $request->integer('account_id'))->values();
        }

        $accountIds = $this->authorizedAccountIds(
            $request,
            $accounts,
            $mailboxAccess,
            $access,
            $operation,
        );
        $query = $mailboxAccess->scopeContentMessages(
            EmailMessage::query()
                ->with([
                    'account',
                    'tags',
                    'placements' => fn ($placements) => $placements
                        ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
                        ->whereNull('provider_missing_at'),
                ])
                ->providerInbox()
                ->whereNull('ticket_id'),
            $request->user(),
            $operation,
        )
            ->whereIn('account_id', $accountIds)
            ->orderByDesc('received_at')
            ->orderByDesc('id');

        if ($term !== '') {
            $query->searchText($term);
        }

        foreach (['state', 'from_email'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }

        if ($request->filled('account_id')) {
            $query->where('account_id', $request->integer('account_id'));
        }

        $paginator = $query->paginate($request->integer('per_page') ?: 25);
        $canonicalContent->prime($paginator->getCollection()->flatMap->placements);
        $paginator->setCollection($paginator->getCollection()->map(
            fn (EmailMessage $message): EmailMessage => $this->loadMessage($message, $canonicalContent),
        ));

        return EmailMessageResource::collection($paginator);
    }

    public function show(
        EmailMessage $message,
        MailboxAccess $mailboxAccess,
        MailboxAccessUseGuard $access,
        EmailCanonicalContentResolver $canonicalContent,
    ) {
        abort_if(! $this->isInboxMessage($message) || $message->ticket_id !== null, 404);
        $message->loadMissing('account');

        try {
            $decision = $message->account
                ? $access->authorize(
                    request()->user(),
                    $message->account,
                    ResolveMailboxAccessDecision::CONTENT_VIEW,
                    'message',
                    (int) $message->id,
                )
                : null;
        } catch (AuthorizationException) {
            $decision = null;
        }

        abort_if(
            ! $decision
                || (! $decision->usesBreakGlass()
                    && ! $mailboxAccess->canAccessAccount(
                        request()->user(),
                        $message->account,
                        MailboxAccess::VIEW,
                    )),
            404,
        );

        return new EmailMessageResource($this->loadMessage($message, $canonicalContent));
    }

    public function markSpam(
        Request $request,
        EmailMessage $message,
        MarkEmailAsSpam $markEmailAsSpam,
        MailboxAccess $mailboxAccess,
        EmailCanonicalContentResolver $canonicalContent,
    ) {
        abort_if(! $this->isInboxMessage($message) || $message->ticket_id !== null || ! $mailboxAccess->canOrganizeMessage($request->user(), $message), 404);

        $rule = $markEmailAsSpam->handle($message, $request->user());

        return response()->json([
            'data' => [
                'message' => new EmailMessageResource($this->loadMessage($message->fresh(), $canonicalContent)),
                'rule' => [
                    'id' => $rule->id,
                    'name' => $rule->name,
                    'is_active' => $rule->is_active,
                    'stop_processing' => $rule->stop_processing,
                ],
            ],
        ]);
    }

    public function poll(Request $request, MailboxAccess $mailboxAccess)
    {
        $settings = CommonSetting::where('type', 'emailhub')
            ->get()
            ->pluck('value', 'name')
            ->toArray();
        $batchSize = (int) ($settings['batch_size'] ?? 20);
        $accounts = $mailboxAccess->scopeAccounts(
            EmailAccount::query()->where('is_active', true),
            $request->user(),
            MailboxAccess::ORGANIZE,
        )->get();

        foreach ($accounts as $account) {
            FetchImapAccount::dispatch($account->id, $batchSize);
        }

        return response()->json([
            'data' => [
                'queued_accounts' => $accounts->count(),
                'batch_size' => $batchSize,
            ],
        ], 202);
    }

    private function loadMessage(
        EmailMessage $message,
        EmailCanonicalContentResolver $canonicalContent,
    ): EmailMessage {
        $message->load([
            'account',
            'attachments',
            'tags',
            'placements' => fn ($placements) => $placements
                ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
                ->whereNull('provider_missing_at'),
        ]);
        $placement = $message->placements
            ->first(fn (EmailMailboxPlacement $placement): bool => (int) $placement->account_id === (int) $message->account_id);

        return $placement
            ? $canonicalContent->resolve($placement, $message)->message
            : $message;
    }

    private function isInboxMessage(EmailMessage $message): bool
    {
        return $message->isActiveProviderInboxMessage();
    }

    /**
     * @param  Collection<int, EmailAccount>  $accounts
     * @return array<int>
     */
    private function authorizedAccountIds(
        Request $request,
        Collection $accounts,
        MailboxAccess $mailboxAccess,
        MailboxAccessUseGuard $access,
        string $operation,
    ): array {
        $resourceType = $operation === ResolveMailboxAccessDecision::SEARCH ? 'search' : 'mailbox';

        return $accounts
            ->filter(function (EmailAccount $account) use (
                $access,
                $mailboxAccess,
                $operation,
                $request,
                $resourceType,
            ): bool {
                try {
                    $decision = $access->authorize(
                        $request->user(),
                        $account,
                        $operation,
                        $resourceType,
                        (int) $account->id,
                    );
                } catch (AuthorizationException) {
                    return false;
                }

                if ($operation === ResolveMailboxAccessDecision::SEARCH
                    && ! app(ResolveMailboxAccessDecision::class)
                        ->resolve(
                            $request->user(),
                            $account,
                            ResolveMailboxAccessDecision::CONTENT_VIEW,
                        )
                        ->allowed) {
                    return false;
                }

                return $decision->usesBreakGlass()
                    || $mailboxAccess->canAccessAccount($request->user(), $account, MailboxAccess::VIEW);
            })
            ->pluck('id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();
    }
}
