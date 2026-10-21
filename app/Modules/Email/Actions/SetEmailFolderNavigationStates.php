<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailFolderNavigationPreference;
use App\Modules\Email\Services\MailboxAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Persist only the actor's explicitly selected visible folder preferences. */
final class SetEmailFolderNavigationStates
{
    public function handle(User $actor, Collection $folderIds, bool $expanded): void
    {
        $ids = $folderIds->filter(fn ($id) => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id) => (int) $id)->unique()->take(500)->values();
        $folders = EmailFolder::query()->whereIn('id', $ids)->with('account')->get();
        abort_unless($folders->count() === $ids->count(), 404);
        foreach ($folders as $folder) {
            abort_unless($folder->account && app(MailboxAccess::class)->canAccessAccount($actor, $folder->account, MailboxAccess::VIEW), 404);
        }
        if ($ids->isEmpty()) {
            return;
        }
        DB::transaction(function () use ($actor, $ids, $expanded): void {
            $now = now();
            EmailFolderNavigationPreference::query()->upsert($ids->map(fn ($id) => [
                'user_id' => $actor->id, 'email_folder_id' => $id, 'is_expanded' => $expanded,
                'created_at' => $now, 'updated_at' => $now,
            ])->all(), ['user_id', 'email_folder_id'], ['is_expanded', 'updated_at']);
        });
    }
}
