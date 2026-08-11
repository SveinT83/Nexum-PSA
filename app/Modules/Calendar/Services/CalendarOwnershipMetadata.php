<?php

namespace App\Modules\Calendar\Services;

use App\Models\Core\User;
use App\Modules\Calendar\Models\Calendar;
use Spatie\Permission\Models\Role;

class CalendarOwnershipMetadata
{
    private const TYPE_LABELS = [
        'personal' => 'Personal',
        'shared' => 'Shared',
        'team' => 'Team',
        'company' => 'Company',
        'absence' => 'Absence',
        'shift' => 'Shift',
        'resource' => 'Resource',
        'system' => 'System',
        'external' => 'External',
        'other' => 'Other',
    ];

    private const TYPE_BADGES = [
        'personal' => 'PER',
        'shared' => 'SHR',
        'team' => 'TEAM',
        'company' => 'ALL',
        'absence' => 'ABS',
        'shift' => 'SHFT',
        'resource' => 'RES',
        'system' => 'SYS',
        'external' => 'EXT',
        'other' => 'CAL',
    ];

    private const GROUP_LABELS = [
        'mine' => 'Mine',
        'people' => 'People',
        'team' => 'Team',
        'shared' => 'Shared/global',
        'resources' => 'Resources',
        'external' => 'External/system',
    ];

    /**
     * Build one privacy-safe ownership contract for Calendar views and APIs.
     *
     * Event creator data is intentionally not accepted here: ownership in the
     * approved first rollout always comes from the calendar container.
     *
     * @return array{
     *     owner_kind: string,
     *     owner_id: int|null,
     *     owner_label: string,
     *     owner_initials: string,
     *     owner_badge: string,
     *     calendar_color: string,
     *     calendar_type: string,
     *     calendar_type_label: string,
     *     ownership_group: string,
     *     is_owned_by_viewer: bool
     * }
     */
    public function forCalendar(?Calendar $calendar, ?User $viewer): array
    {
        if (! $calendar) {
            return [
                'owner_kind' => 'none',
                'owner_id' => null,
                'owner_label' => 'Calendar',
                'owner_initials' => 'CAL',
                'owner_badge' => 'CAL',
                'calendar_color' => '#6c757d',
                'calendar_type' => 'other',
                'calendar_type_label' => self::TYPE_LABELS['other'],
                'ownership_group' => 'shared',
                'is_owned_by_viewer' => false,
            ];
        }

        $calendar->loadMissing('owner');

        $type = array_key_exists((string) $calendar->type, self::TYPE_LABELS)
            ? (string) $calendar->type
            : 'other';
        $owner = $calendar->owner;
        $isOwnedByViewer = $viewer !== null
            && $calendar->owner_type === $viewer::class
            && (int) $calendar->owner_id === (int) $viewer->id;
        $ownerLabel = $this->ownerLabel($calendar, $owner);
        $ownerInitials = $owner instanceof User || $owner instanceof Role
            ? $this->initials($ownerLabel, self::TYPE_BADGES[$type])
            : self::TYPE_BADGES[$type];

        return [
            'owner_kind' => $this->ownerKind($calendar, $owner),
            'owner_id' => $calendar->owner_id !== null ? (int) $calendar->owner_id : null,
            'owner_label' => $ownerLabel,
            'owner_initials' => $ownerInitials,
            'owner_badge' => $ownerInitials,
            'calendar_color' => (string) ($calendar->color ?: '#6c757d'),
            'calendar_type' => $type,
            'calendar_type_label' => self::TYPE_LABELS[$type],
            'ownership_group' => $this->ownershipGroup($calendar, $type, $isOwnedByViewer),
            'is_owned_by_viewer' => $isOwnedByViewer,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function groupLabels(): array
    {
        return self::GROUP_LABELS;
    }

    private function ownerKind(Calendar $calendar, mixed $owner): string
    {
        if ($owner instanceof User || $calendar->owner_type === User::class) {
            return 'user';
        }

        if ($owner instanceof Role || $calendar->owner_type === Role::class) {
            return 'role';
        }

        return $calendar->owner_type ? 'entity' : 'none';
    }

    private function ownerLabel(Calendar $calendar, mixed $owner): string
    {
        if (($owner instanceof User || $owner instanceof Role) && trim((string) $owner->name) !== '') {
            return trim((string) $owner->name);
        }

        $typeLabel = self::TYPE_LABELS[(string) $calendar->type] ?? 'Calendar';

        return trim((string) $calendar->name) ?: $typeLabel;
    }

    private function ownershipGroup(Calendar $calendar, string $type, bool $isOwnedByViewer): string
    {
        if ($isOwnedByViewer) {
            return 'mine';
        }

        if ($calendar->owner_type === User::class || $type === 'personal') {
            return 'people';
        }

        return match ($type) {
            'team', 'absence', 'shift' => 'team',
            'resource' => 'resources',
            'system', 'external' => 'external',
            default => 'shared',
        };
    }

    private function initials(string $label, string $fallback): string
    {
        $parts = preg_split('/\s+/u', trim($label)) ?: [];
        $initials = collect($parts)
            ->filter()
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
            ->take(2)
            ->implode('');

        return $initials !== '' ? $initials : $fallback;
    }
}
