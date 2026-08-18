<?php

namespace App\Modules\Email\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Models\Core\User;
use App\Modules\Email\Actions\ApplyEmailUnreadHandover;
use App\Modules\Email\Actions\ExpireEmailUnreadHandoverPreviews;
use App\Modules\Email\Actions\PreviewEmailUnreadHandover;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxDelegation;
use App\Modules\Email\Models\EmailUnreadHandoverRun;
use App\Modules\Email\Services\EmailOrdinaryMailboxEntitlementResolver;
use App\Modules\Email\Services\EmailUnreadHandoverAuthorization;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

class UnreadHandoverController extends Controller
{
    public function __construct(
        private readonly EmailUnreadHandoverAuthorization $authorization,
        private readonly EmailOrdinaryMailboxEntitlementResolver $entitlements,
    ) {}

    public function index(
        EmailAccount $account,
        Request $request,
        ExpireEmailUnreadHandoverPreviews $expirePreviews,
    ): View {
        /** @var User $actor */
        $actor = $request->user();
        $this->authorizeManagerOrNotFound($actor, $account);
        $expirePreviews->handle($account);

        $runs = EmailUnreadHandoverRun::query()
            ->where('email_account_id', $account->id)
            ->with([
                'requestedBy:id,name',
                'targetUser:id,name',
            ])
            ->withCount('items')
            ->latest('id')
            ->limit(50)
            ->get();
        $scopeFolderIds = $runs
            ->flatMap(fn (EmailUnreadHandoverRun $run): array => (array) $run->folder_scope_json)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique();
        $folderLabels = EmailFolder::query()
            ->where('account_id', $account->id)
            ->whereIn('id', $scopeFolderIds)
            ->get(['id', 'path'])
            ->mapWithKeys(fn (EmailFolder $folder): array => [$folder->id => $folder->path]);

        return view('email::Tech.unread-handover.index', [
            'account' => $account,
            'actor' => $actor,
            'folders' => EmailFolder::query()
                ->where('account_id', $account->id)
                ->where('is_selectable', true)
                ->where('sync_enabled', true)
                ->orderBy('path')
                ->get(['id', 'name', 'path', 'role']),
            'folderLabels' => $folderLabels,
            'runs' => $runs,
            'targets' => $this->currentTargets($account),
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function preview(
        EmailAccount $account,
        Request $request,
        PreviewEmailUnreadHandover $preview,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();
        $this->authorizeManagerOrNotFound($actor, $account);
        $data = $request->validate([
            'target_user_id' => ['required', 'integer', 'exists:user_management,id'],
            'folder_ids' => ['required', 'array', 'min:1'],
            'folder_ids.*' => ['required', 'integer', 'distinct'],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'maximum' => ['required', 'integer', 'min:1', 'max:'.EmailUnreadHandoverRun::MAX_CAP],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:191'],
        ]);
        $target = User::query()->findOrFail((int) $data['target_user_id']);
        $run = $preview->handle(
            $actor,
            $account,
            $target,
            $data['folder_ids'],
            CarbonImmutable::parse($data['date_from']),
            CarbonImmutable::parse($data['date_to']),
            $data['reason'],
            (int) $data['maximum'],
            $data['idempotency_key'],
        );

        return redirect()
            ->route('tech.mail.unread-handover.index', $account)
            ->with(
                'status',
                "Unread handover preview #{$run->id} selected {$run->selected_count} message(s).",
            );
    }

    public function apply(
        EmailAccount $account,
        EmailUnreadHandoverRun $run,
        Request $request,
        ApplyEmailUnreadHandover $apply,
    ): RedirectResponse {
        abort_unless((int) $run->email_account_id === (int) $account->id, 404);

        /** @var User $actor */
        $actor = $request->user();
        $this->authorizeManagerOrNotFound($actor, $account);
        $result = $apply->handle($actor, $run);
        $message = $result->status === EmailUnreadHandoverRun::STATUS_APPLIED
            ? "Unread handover #{$result->id} applied {$result->applied_count} new personal unread state(s); {$result->already_unread_count} were already unread."
            : "Unread handover #{$result->id} is {$result->status}; no personal state was changed.";

        return redirect()
            ->route('tech.mail.unread-handover.index', $account)
            ->with($result->status === EmailUnreadHandoverRun::STATUS_APPLIED ? 'status' : 'warning', $message);
    }

    /** @return Collection<int, User> */
    private function currentTargets(EmailAccount $account): Collection
    {
        $candidateIds = $account->isPersonal()
            ? collect([$account->owner_id])->merge(
                EmailMailboxDelegation::query()
                    ->where('email_account_id', $account->id)
                    ->where('owner_id', $account->owner_id)
                    ->where('can_view', true)
                    ->effective()
                    ->pluck('delegate_id'),
            )
            : $account->userGrants()->where('can_view', true)->pluck('user_id');

        return User::query()
            ->whereIn('id', $candidateIds->filter()->unique())
            ->where('status', User::STATUS_ACTIVE)
            ->orderBy('name')
            ->orderBy('email')
            ->get(['id', 'name', 'email', 'status', 'is_system_actor'])
            ->filter(fn (User $user): bool => ! $user->isSystemActor()
                && $user->can('email.inbox_view')
                && $this->entitlements->hasCurrentViewAccess($account, $user))
            ->values();
    }

    private function authorizeManagerOrNotFound(User $actor, EmailAccount $account): void
    {
        try {
            $this->authorization->authorizeManager($actor, $account);
        } catch (AuthorizationException) {
            // Existing and nonexistent personal mailboxes must have the same
            // externally visible response for unauthorized technicians.
            abort(404);
        }
    }
}
