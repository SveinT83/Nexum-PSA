<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Email\Services\MailboxAccess;

/** One bounded provider batch; a partial batch never implies that the source folder is empty. */
final class MoveEmailFolderMessages
{
    public function handle(EmailFolder $source, EmailFolder $target, User $actor): array
    {
        abort_unless($source->account_id === $target->account_id
            && $source->id !== $target->id && $target->is_selectable && $target->sync_enabled, 422);
        abort_unless(app(MailboxAccess::class)->canAccessAccount($actor, $source->account, MailboxAccess::ORGANIZE), 403);
        $items = [];
        foreach (EmailMailboxPlacement::query()->where('email_folder_id', $source->id)
            ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)->orderBy('id')->limit(50)->get() as $placement) {
            try {
                $operation = app(PerformEmailRemoteOperation::class)->handle($placement,
                    PerformEmailRemoteOperation::MOVE, $actor, $target);
                $items[] = ['placement_id' => (int) $placement->id, 'operation_id' => (int) $operation->id,
                    'status' => $operation->status];
            } catch (\Throwable) {
                $items[] = ['placement_id' => (int) $placement->id, 'operation_id' => null, 'status' => 'failed'];
            }
        }

        return ['items' => $items,
            'moved' => count(array_filter($items, fn ($item) => $item['status'] === EmailRemoteOperation::STATUS_SUCCEEDED)),
            'failed' => count(array_filter($items, fn ($item) => $item['status'] !== EmailRemoteOperation::STATUS_SUCCEEDED)),
            'remaining' => app(ManageProviderEmailFolder::class)->activePlacementCount($source->fresh())];
    }
}
