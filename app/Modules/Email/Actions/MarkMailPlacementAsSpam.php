<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Services\MailboxAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Preserve rule/tag success separately from the optional provider Archive outcome. */
final class MarkMailPlacementAsSpam
{
    public function handle(EmailMailboxPlacement $placement, User $actor): array
    {
        $placement->loadMissing(['account', 'folder', 'message']);
        abort_unless($actor->isActive() && ! $actor->isSystemActor(), 403);
        abort_unless($placement->account && $placement->message?->hasActiveProviderPlacement($placement)
            && $placement->message->ticket_id === null && ! $placement->provider_draft
            && $placement->folder?->role !== EmailFolder::ROLE_DRAFTS
            && app(MailboxAccess::class)->canAccessAccount($actor, $placement->account, MailboxAccess::ORGANIZE), 404);
        $rule = app(MarkEmailAsSpam::class)->handle($placement->message, $actor);
        $result = ['rule' => $rule, 'operation' => null, 'archive_status' => 'not_requested', 'archive_error' => null];
        if (! EmailFolder::query()->where('account_id', $placement->account_id)
            ->where('role', EmailFolder::ROLE_ARCHIVE)->where('is_selectable', true)->exists()) {
            return $result;
        }
        try {
            $result['operation'] = app(PerformEmailRemoteOperation::class)->handle($placement, PerformEmailRemoteOperation::ARCHIVE, $actor);
            $result['archive_status'] = $result['operation']->status;
        } catch (ValidationException $exception) {
            $result['archive_status'] = 'failed';
            $result['archive_error'] = collect($exception->errors())->flatten()->first() ?: 'Archive target is unavailable.';
        } catch (AuthorizationException|RuntimeException) {
            $result['archive_status'] = 'failed';
            $result['archive_error'] = 'The provider Archive action could not be completed.';
        }

        return $result;
    }
}
