<?php

namespace App\Modules\Calendar\Support;

class CalendarPermission
{
    public const VIEW = 'calendar.view';
    public const CREATE = 'calendar.create';
    public const UPDATE = 'calendar.update';
    public const DELETE = 'calendar.delete';
    public const VIEW_ALL = 'calendar.view_all';
    public const MANAGE_ALL = 'calendar.manage_all';
    public const VIEW_PRIVATE = 'calendar.view_private';
    public const MANAGE_SHARED = 'calendar.manage_shared';
    public const MANAGE_ACCESS = 'calendar.manage_access';
    public const MANAGE_SETTINGS = 'calendar.manage_settings';
    public const VIEW_FREE_BUSY = 'calendar.view_free_busy';
    public const BOOK_RESOURCES = 'calendar.book_resources';
    public const MANAGE_SHIFT = 'calendar.manage_shift';
    public const MANAGE_ABSENCE = 'calendar.manage_absence';

    public static function all(): array
    {
        return [
            self::VIEW,
            self::CREATE,
            self::UPDATE,
            self::DELETE,
            self::VIEW_ALL,
            self::MANAGE_ALL,
            self::VIEW_PRIVATE,
            self::MANAGE_SHARED,
            self::MANAGE_ACCESS,
            self::MANAGE_SETTINGS,
            self::VIEW_FREE_BUSY,
            self::BOOK_RESOURCES,
            self::MANAGE_SHIFT,
            self::MANAGE_ABSENCE,
        ];
    }
}
