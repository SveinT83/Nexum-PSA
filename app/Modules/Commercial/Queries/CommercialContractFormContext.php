<?php

namespace App\Modules\Commercial\Queries;

use App\Models\Core\User;
use App\Modules\Commercial\Models\Sla\Sla;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role;

/** Pure browser form choices/defaults; the legacy created_by column selects the Technician. */
class CommercialContractFormContext
{
    public function technicians(): Builder
    {
        $roles = Role::query()->whereIn('name', ['service.admin', 'tech.admin', 'Superuser', 'service.create', 'service.view'])->pluck('name')->all();

        return $roles ? User::role($roles) : User::query()->whereRaw('1 = 0');
    }

    public function defaults(): array
    {
        $start = now()->addMonths(1)->startOfMonth();
        if (now()->diffInDays($start, false) < 30) {
            $start->addMonths(1);
        }

        return ['start_date' => $start->toDateString(), 'end_date' => $start->copy()->addYear()->toDateString(), 'binding_end_date' => $start->copy()->addYear()->toDateString(), 'sla_id' => Sla::query()->where('is_default', true)->orderBy('id')->value('id')];
    }
}
