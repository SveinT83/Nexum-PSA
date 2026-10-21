<?php

namespace App\Modules\Email\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Email\Actions\CreateProviderEmailFolder;
use App\Modules\Email\Actions\ManageProviderEmailFolder;
use App\Modules\Email\Actions\MoveEmailFolderMessages;
use App\Modules\Email\Actions\PerformEmailRemoteOperation;
use App\Modules\Email\Actions\SetEmailFolderNavigationStates;
use App\Modules\Email\Actions\TrashInboxEmail;
use App\Modules\Email\Jobs\FetchImapAccount;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailFolderNavigationPreference;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Resources\Api\V1\EmailRemoteOperationResource;
use App\Modules\Email\Services\MailboxAccess;
use App\Modules\Email\Support\EmailApiAccess;
use Illuminate\Http\Request;

/** Provider folder mutations use the same operation ledger and blockers as Mail. */
final class MailboxFoldersController extends Controller
{
    public function store(Request $request, EmailAccount $account, EmailApiAccess $access, CreateProviderEmailFolder $create)
    {
        $actor = $access->ordinary($request, $account, MailboxAccess::ORGANIZE);
        $data = $request->validate(['path' => ['required', 'string', 'max:180']]);
        $folder = $create->handle($account, $actor, $data['path']);

        return response()->json(['data' => $folder->only(['id', 'account_id', 'path', 'name', 'role', 'sync_status'])], 201);
    }

    public function context(Request $request, EmailFolder $folder, EmailApiAccess $access, ManageProviderEmailFolder $manage)
    {
        $access->ordinary($request, $folder->account, MailboxAccess::ORGANIZE);

        return response()->json(['data' => ['folder' => $folder->only(['id', 'account_id', 'path', 'name', 'role']),
            'mutation_blockers' => $manage->mutationBlockers($folder),
            'delete_blockers' => $manage->mutationBlockers($folder, true),
            'active_placement_count' => $manage->activePlacementCount($folder)]]);
    }

    public function rename(Request $request, EmailFolder $folder, EmailApiAccess $access, ManageProviderEmailFolder $manage)
    {
        $actor = $access->ordinary($request, $folder->account, MailboxAccess::ORGANIZE);
        $data = $request->validate(['name' => ['required', 'string', 'max:180']]);

        return new EmailRemoteOperationResource($manage->rename($folder, $actor, $data['name']));
    }

    public function move(Request $request, EmailFolder $folder, EmailApiAccess $access, ManageProviderEmailFolder $manage)
    {
        $actor = $access->ordinary($request, $folder->account, MailboxAccess::ORGANIZE);
        $data = $request->validate(['parent_folder_id' => ['present', 'nullable', 'integer', 'min:1']]);
        $parent = isset($data['parent_folder_id'])
            ? EmailFolder::query()->where('account_id', $folder->account_id)->findOrFail($data['parent_folder_id']) : null;

        return new EmailRemoteOperationResource($manage->move($folder, $actor, $parent));
    }

    public function emptyInto(Request $request, EmailFolder $folder, EmailApiAccess $access, MoveEmailFolderMessages $move)
    {
        $actor = $access->ordinary($request, $folder->account, MailboxAccess::ORGANIZE);
        $data = $request->validate(['target_folder_id' => ['required', 'integer', 'min:1']]);
        $target = EmailFolder::query()->where('account_id', $folder->account_id)->findOrFail($data['target_folder_id']);

        return response()->json(['data' => $move->handle($folder, $target, $actor)]);
    }

    public function destroy(Request $request, EmailFolder $folder, EmailApiAccess $access, ManageProviderEmailFolder $manage)
    {
        $actor = $access->ordinary($request, $folder->account, MailboxAccess::ORGANIZE);
        $request->validate(['confirm' => ['required', 'accepted']]);

        return new EmailRemoteOperationResource($manage->delete($folder, $actor));
    }

    public function spam(Request $request, EmailMailboxPlacement $placement, EmailApiAccess $access,
        \App\Modules\Email\Actions\MarkMailPlacementAsSpam $spam)
    {
        $placement = $access->placement($request, $placement);
        $result = $spam->handle($placement, $request->user());

        return response()->json(['data' => [
            'placement_id' => $placement->id, 'rule_status' => 'succeeded',
            'rule' => $result['rule']->only(['id', 'name', 'is_active', 'stop_processing']),
            'archive_status' => $result['archive_status'], 'archive_error' => $result['archive_error'],
            'operation' => $result['operation'] ? new EmailRemoteOperationResource($result['operation']) : null,
        ]]);
    }

    public function trash(Request $request, EmailMailboxPlacement $placement, EmailApiAccess $access, PerformEmailRemoteOperation $perform)
    {
        $placement = $access->placement($request, $placement);
        $actor = $access->ordinary($request, $placement->account, MailboxAccess::ORGANIZE);

        return new EmailRemoteOperationResource($perform->handle($placement, PerformEmailRemoteOperation::TRASH, $actor));
    }

    public function legacyTrash(Request $request, EmailMessage $message, EmailApiAccess $access, TrashInboxEmail $trash)
    {
        $actor = $access->reader($request);

        return new EmailRemoteOperationResource($trash->handle($actor, $message));
    }

    public function refresh(Request $request, EmailAccount $account, EmailApiAccess $access)
    {
        $access->ordinary($request, $account, MailboxAccess::ORGANIZE);
        $data = $request->validate(['folder_id' => ['nullable', 'integer', 'min:1']]);
        if (isset($data['folder_id'])) {
            EmailFolder::query()->where('account_id', $account->id)->where('is_selectable', true)->where('sync_enabled', true)->findOrFail($data['folder_id']);
        }
        $batchSize = \App\Models\Settings\CommonSetting::query()->where('type', 'emailhub')->where('name', 'batch_size')->value('value');
        FetchImapAccount::dispatch($account->id, max(1, (int) ($batchSize ?? 20)));

        return response()->json(['data' => ['account_id' => $account->id, 'status' => 'queued',
            'effective_scope' => 'account', 'queued_at' => now(),
            'status_url' => route('api.v1.email.mailbox.refresh.status', $account)]], 202);
    }

    public function refreshStatus(Request $request, EmailAccount $account, EmailApiAccess $access)
    {
        $access->ordinary($request, $account, MailboxAccess::ORGANIZE);

        return response()->json(['data' => $account->only(['id', 'last_successful_fetch_at', 'last_test_at', 'last_test_result', 'last_error_code'])]);
    }

    public function preferences(Request $request, EmailApiAccess $access)
    {
        $ids = EmailFolder::query()->whereIn('account_id', $access->accounts($request)->pluck('id'))->pluck('id');

        return response()->json(['data' => EmailFolderNavigationPreference::query()->where('user_id', $request->user()->id)
            ->whereIn('email_folder_id', $ids)->orderBy('email_folder_id')->get(['email_folder_id', 'is_expanded'])]);
    }

    public function updatePreferences(Request $request, EmailApiAccess $access, SetEmailFolderNavigationStates $set)
    {
        $actor = $access->reader($request);
        $data = $request->validate(['folder_ids' => ['required', 'array', 'min:1', 'max:500'],
            'folder_ids.*' => ['integer', 'min:1', 'distinct'], 'expanded' => ['required', 'boolean']]);
        $set->handle($actor, collect($data['folder_ids']), (bool) $data['expanded']);

        return $this->preferences($request, $access);
    }
}
