<?php

namespace App\Modules\UserManagement\Queries;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Builder;

/** Same native internal identity rule as SsoAccess; disabled staff remain selectable for history. */
class ReportEmployees
{
    public function query(): Builder
    {
        return User::query()->where('is_system_actor', false)
            ->where(fn (Builder $q) => $q->whereHas('roles')->orWhereHas('permissions'));
    }

    public function page(string $search, int $page = 1): array
    {
        // PHP Unicode folding also works on SQLite, whose LOWER does not fold Norwegian letters.
        $needle = mb_strtolower(trim($search), 'UTF-8');
        $all = $this->query()->orderBy('name')->orderBy('id')->get(['id', 'name', 'status'])
            ->filter(fn ($user) => $needle === '' || str_contains(mb_strtolower($user->name, 'UTF-8'), $needle))
            ->map(fn ($user) => ['id' => $user->id, 'label' => $user->name,
                'hint' => $user->status === User::STATUS_ACTIVE ? '' : 'Inactive / former employee'])->values();

        return ['data' => $all->forPage($page, 50)->values()->all(),
            'meta' => ['total' => $all->count(), 'page' => $page, 'next_page' => $page * 50 < $all->count() ? $page + 1 : null]];
    }

    public function label(?int $id): string
    {
        return $id ? (string) $this->query()->whereKey($id)->value('name') : '';
    }
}
