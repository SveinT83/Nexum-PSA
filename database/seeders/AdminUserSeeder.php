<?php

namespace Database\Seeders;

use App\Models\Core\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    /**
     * Preserve compatibility without ever manufacturing a reusable credential.
     *
     * Initial administrator creation is deliberately isolated in the
     * interactive nexum:bootstrap-admin command. Re-running database seeders
     * must not create, reset, re-role, or print credentials.
     */
    public function run(): void
    {
        $hasHumanSuperuser = User::query()
            ->where('is_system_actor', false)
            ->whereHas('roles', static fn ($query) => $query
                ->where('name', 'Superuser')
                ->where('guard_name', 'web'))
            ->exists();

        if (! $hasHumanSuperuser) {
            $this->command?->warn(
                'No human Superuser exists. Run php artisan nexum:bootstrap-admin '
                .'from an interactive local console.',
            );
        }
    }
}
