<?php

namespace App\Modules\Email\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Core\User;
use App\Modules\Email\Actions\ActivateEmailBreakGlassAccess;
use App\Modules\Email\Actions\ApplyEmailUnreadHandover;
use App\Modules\Email\Actions\CreateEmailMailboxDelegation;
use App\Modules\Email\Actions\PreviewEmailUnreadHandover;
use App\Modules\Email\Actions\RevokeEmailBreakGlassAccess;
use App\Modules\Email\Actions\RevokeEmailMailboxDelegation;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailBreakGlassAccess;
use App\Modules\Email\Models\EmailMailboxDelegation;
use App\Modules\Email\Models\EmailUnreadHandoverRun;
use App\Modules\Email\Queries\EmailMailboxAccessContext;
use App\Modules\Email\Queries\EmailMailboxAccessHistoryQuery;
use App\Modules\Email\Services\EmailUnreadHandoverAuthorization;
use App\Modules\Email\Services\EmailUnreadHandoverContext;
use App\Modules\Email\Support\EmailApiAccess;
use App\Modules\Email\Support\EmailApiProjection;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** Owner delegation, audited emergency access and explicit backlog handover remain independent grants. */
final class MailboxAccessApiController extends Controller
{
    public function context(Request $request, EmailApiAccess $access, EmailMailboxAccessContext $context, EmailApiProjection $project)
    {
        $access->actor($request);

        return response()->json(['data' => $project->value($context->handle($request))]);
    }

    public function history(Request $request, EmailApiAccess $access, EmailMailboxAccessHistoryQuery $query, EmailApiProjection $project)
    {
        $actor = $access->actor($request);
        $data = $request->validate(['account_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        return response()->json($project->value($query->forActor($actor, $data['account_id'] ?? null, $data['per_page'] ?? 50)));
    }

    public function delegate(Request $request, EmailAccount $account, EmailApiAccess $access, CreateEmailMailboxDelegation $create, EmailApiProjection $project)
    {
        $actor = $access->actor($request);
        abort_unless($account->isPersonal() && (int) $account->owner_id === (int) $actor->id, 404);
        $data = $request->validate([
            'delegate_id' => ['required', 'integer', 'exists:user_management,id'],
            'can_view' => ['nullable', 'boolean'], 'can_organize' => ['nullable', 'boolean'],
            'can_send' => ['nullable', 'boolean'], 'can_view_raw_source' => ['nullable', 'boolean'],
            'reason' => ['required', 'string', 'max:2000'], 'starts_at' => ['required', 'date'], 'expires_at' => ['required', 'date'],
        ]);
        $delegation = $create->handle($account, $actor, User::query()->findOrFail($data['delegate_id']), $data);

        return response()->json(['data' => $project->value($delegation)], 201);
    }

    public function revokeDelegate(Request $request, EmailAccount $account, EmailMailboxDelegation $delegation, EmailApiAccess $access, RevokeEmailMailboxDelegation $revoke, EmailApiProjection $project)
    {
        $actor = $access->actor($request);
        abort_unless($account->isPersonal() && (int) $account->owner_id === (int) $actor->id
            && (int) $delegation->email_account_id === (int) $account->id, 404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $revoke->handle($delegation, $actor, $data['reason']);

        return response()->json(['data' => $project->value($delegation->fresh())]);
    }

    public function emergencyContext(Request $request, EmailApiAccess $access, EmailMailboxAccessHistoryQuery $query, EmailApiProjection $project)
    {
        $actor = $access->actor($request);
        abort_unless($actor->can('email.break_glass_activate') || $actor->can('email.break_glass_audit'), 403);

        return response()->json(['data' => [
            'active' => $project->value($query->activeBreakGlass()->limit(100)->get()),
            'history' => $project->value($query->forActor($actor)),
            'accounts' => $actor->can('email.break_glass_activate') ? $project->value(EmailAccount::query()
                ->where('account_kind', EmailAccount::KIND_PERSONAL)->where('is_active', true)->orderBy('address')->get()) : [],
        ]]);
    }

    public function emergency(Request $request, EmailApiAccess $access, ActivateEmailBreakGlassAccess $activate, EmailApiProjection $project)
    {
        $actor = $access->actor($request);
        abort_unless($actor->can('email.break_glass_activate'), 403);
        $data = $request->validate([
            'account_id' => ['required', 'integer'], 'account_confirmation' => ['required', 'string', 'max:255'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:120'],
            'can_view_content' => ['nullable', 'boolean'], 'can_search' => ['nullable', 'boolean'],
            'can_download_attachments' => ['nullable', 'boolean'], 'can_view_raw_source' => ['nullable', 'boolean'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $account = EmailAccount::query()->where('account_kind', EmailAccount::KIND_PERSONAL)
            ->where('is_active', true)->findOrFail($data['account_id']);

        return response()->json(['data' => $project->value($activate->handle($account, $actor, $data))], 201);
    }

    public function revokeEmergency(Request $request, EmailBreakGlassAccess $emergency, EmailApiAccess $access, RevokeEmailBreakGlassAccess $revoke, EmailApiProjection $project)
    {
        $actor = $access->actor($request);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $revoke->handle($emergency, $actor, $data['reason']);

        return response()->json(['data' => $project->value($emergency->fresh())]);
    }

    public function handoverContext(Request $request, EmailAccount $account, EmailApiAccess $access, EmailUnreadHandoverContext $context, EmailApiProjection $project)
    {
        $access->actor($request);

        return response()->json(['data' => $project->value(app()->call([$context, 'handle'], ['account' => $account, 'request' => $request]))]);
    }

    public function handoverShow(Request $request, EmailAccount $account, EmailUnreadHandoverRun $run, EmailApiAccess $access, EmailUnreadHandoverAuthorization $authorization, EmailApiProjection $project)
    {
        $authorization->authorizeManager($access->actor($request), $account);
        abort_unless((int) $run->email_account_id === (int) $account->id, 404);

        return response()->json(['data' => $project->value($run->load('items'))]);
    }

    public function handoverPreview(Request $request, EmailAccount $account, EmailApiAccess $access, PreviewEmailUnreadHandover $preview, EmailApiProjection $project)
    {
        $actor = $access->actor($request);
        $data = $request->validate([
            'target_user_id' => ['required', 'integer', 'exists:user_management,id'],
            'folder_ids' => ['required', 'array', 'min:1', 'max:500'], 'folder_ids.*' => ['integer', 'distinct', 'min:1'],
            'date_from' => ['required', 'date'], 'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'maximum' => ['required', 'integer', 'min:1', 'max:'.EmailUnreadHandoverRun::MAX_CAP],
            'reason' => ['required', 'string', 'min:10', 'max:2000'], 'idempotency_key' => ['required', 'string', 'max:191'],
        ]);
        try {
            $run = $preview->handle($actor, $account, User::query()->findOrFail($data['target_user_id']),
                $data['folder_ids'], CarbonImmutable::parse($data['date_from']), CarbonImmutable::parse($data['date_to']),
                $data['reason'], (int) $data['maximum'], $data['idempotency_key']);
        } catch (\InvalidArgumentException|\LogicException $exception) {
            throw \Illuminate\Validation\ValidationException::withMessages(['handover' => $exception->getMessage()]);
        }

        return response()->json(['data' => $project->value($run),
            'status_url' => route('api.v1.email.mailbox.handovers.show', [$account, $run])], 201);
    }

    public function handoverApply(Request $request, EmailAccount $account, EmailUnreadHandoverRun $run, EmailApiAccess $access, ApplyEmailUnreadHandover $apply, EmailApiProjection $project)
    {
        abort_unless((int) $run->email_account_id === (int) $account->id, 404);

        return response()->json(['data' => $project->value($apply->handle($access->actor($request), $run))]);
    }
}
