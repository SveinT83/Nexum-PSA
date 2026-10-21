<?php

namespace App\Modules\Email\Support;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Services\MailboxAccess;
use App\Modules\Email\Services\MailboxAccessUseGuard;
use App\Modules\Email\Services\ResolveMailboxAccessDecision;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/** Token ceilings do not substitute for active human and current mailbox authority. */
final class EmailApiAccess
{
    public function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->isActive() && ! $actor->isSystemActor(), 403);

        return $actor;
    }

    public function reader(Request $request): User
    {
        $actor = $this->actor($request);
        abort_unless($actor->can('email.inbox_view') || $actor->can('email.break_glass_activate'), 403);

        return $actor;
    }

    public function accounts(Request $request, string $operation = ResolveMailboxAccessDecision::CONTENT_VIEW): Collection
    {
        $actor = $this->reader($request);

        return app(MailboxAccess::class)->scopeContentAccounts(
            EmailAccount::query()->where('is_active', true)->orderBy('address'), $actor, $operation,
        )->get()->filter(function (EmailAccount $account) use ($actor, $operation): bool {
            try {
                if ($operation === ResolveMailboxAccessDecision::SEARCH
                    && ! app(ResolveMailboxAccessDecision::class)->resolve($actor, $account, ResolveMailboxAccessDecision::CONTENT_VIEW)->allowed) {
                    return false;
                }
                app(MailboxAccessUseGuard::class)->authorize($actor, $account, $operation, 'mailbox', (int) $account->id);

                return true;
            } catch (AuthorizationException) {
                return false;
            }
        })->values();
    }

    public function placement(Request $request, EmailMailboxPlacement $placement): EmailMailboxPlacement
    {
        $actor = $this->reader($request);
        $placement->loadMissing(['account', 'folder', 'message.attachments', 'conversation']);
        abort_unless($placement->account && $placement->message?->hasActiveProviderPlacement($placement), 404);
        try {
            app(MailboxAccessUseGuard::class)->authorize($actor, $placement->account,
                ResolveMailboxAccessDecision::CONTENT_VIEW, 'message', (int) $placement->email_message_id);
        } catch (AuthorizationException) {
            abort(404);
        }

        return $placement;
    }

    public function ordinary(Request $request, EmailAccount $account, string $grant): User
    {
        $actor = $this->actor($request);
        abort_unless(app(MailboxAccess::class)->canAccessAccount($actor, $account, $grant), 404);

        return $actor;
    }
}
