<?php

namespace App\Modules\Calendar\Support;

use App\Models\Core\User;
use App\Modules\Calendar\Models\CalendarEvent;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Token abilities are a ceiling; the current human and domain rights still apply. */
class CalendarApiContract
{
    public static function actor(Request $request, string $permission = 'calendar.view', bool $admin = false): User
    {
        $actor = $request->user() ? User::query()->find($request->user()->id) : null;
        abort_unless($actor && $actor->status === User::STATUS_ACTIVE, 403, 'An active actor is required.');
        abort_unless($actor->can($permission), 403, 'Missing domain permission.');
        abort_if($admin && ! $actor->hasAnyRole(['Admin', 'Superuser']), 403, 'Administrator access is required.');

        return $actor;
    }

    public static function fields(Request $request, array $allowed): void
    {
        $extra = array_diff(array_keys($request->all()), $allowed);
        if ($extra !== []) {
            throw ValidationException::withMessages(array_fill_keys($extra, 'Unsupported field.'));
        }
    }

    /** Include actual attributes/children, including same-second edits. Never return this for masked records. */
    public static function revision(CalendarEvent $event): string
    {
        $event = $event->fresh(['series']) ?? $event;

        return hash('sha256', json_encode([
            $event->getRawOriginal(),
            $event->participants()->orderBy('id')->get()->map->getRawOriginal()->all(),
            $event->series?->getRawOriginal(),
            $event->series?->exceptions()->orderBy('id')->get()->map->getRawOriginal()->all(),
        ], JSON_THROW_ON_ERROR));
    }
}
