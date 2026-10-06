<?php

namespace App\Modules\Notification\Support;

use App\Models\Core\User;
use App\Models\Tech\Work\Assets\Asset;
use App\Modules\Ticket\Models\Ticket;

class InternalWebPushTargetAuthorizer
{
    public const TARGET_TICKET = 'ticket';

    public const TARGET_ASSET = 'asset';

    public const TARGET_STORAGE_IMPORTS = 'storage_imports';

    public function authorizedPath(User $user, string $type, int|string|null $targetId): ?string
    {
        if (! $user->isActive() || $user->isSystemActor()) {
            return null;
        }

        $definition = NotificationTypeRegistry::definition($type);
        $permission = $definition['web_push']['permission'];
        if (is_string($permission) && ! $user->can($permission)) {
            return null;
        }

        return match ($definition['web_push']['target_kind']) {
            'workday_reminder' => $this->workdayPath($user, $targetId),
            self::TARGET_TICKET => $this->ticketPath($targetId),
            self::TARGET_ASSET => $this->assetPath($targetId),
            self::TARGET_STORAGE_IMPORTS => route('tech.storage.purchase-order-imports.index', [], false),
            default => null,
        };
    }

    private function workdayPath(User $user, int|string|null $targetId): ?string
    {
        if (! app(\App\Modules\Workday\Support\ReminderEligibility::class)->allowed($user)) {
            return null;
        }
        $reminder = \App\Modules\Workday\Models\WorkdayReminder::where('uuid', $targetId)
            ->where('user_id', $user->id)->first();
        $service = app(\App\Modules\Workday\Actions\WorkdayReminders::class);

        return $reminder && $service->current($user, $reminder) ? $service->path($reminder) : null;
    }

    private function ticketPath(int|string|null $targetId): ?string
    {
        if (! is_numeric($targetId)) {
            return null;
        }

        $ticket = Ticket::query()->find((int) $targetId);

        return $ticket
            ? route('tech.tickets.show', $ticket->ticket_key, false)
            : null;
    }

    private function assetPath(int|string|null $targetId): ?string
    {
        if (! is_numeric($targetId)) {
            return null;
        }

        $asset = Asset::query()->find((int) $targetId);

        return $asset
            ? route('tech.assets.show', $asset->id, false)
            : null;
    }
}
