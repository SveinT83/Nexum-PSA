<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Core\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeder.
     */
    public function run(): void
    {
        // Create the Superuser role before the default admin is assigned to it.
        $superuserRole = Role::firstOrCreate([
            'name' => 'Superuser',
            'guard_name' => 'web'
        ]);

        // The first seeded admin must be immediately usable on a fresh install.
        $adminUser = User::firstOrCreate(
            ['email' => 'admin@tdpsa.com'],
            [
                'name' => 'Admin User',
                'email' => 'admin@tdpsa.com',
                'password' => 'JEStayeqU8h',
                'status' => User::STATUS_ACTIVE,
                'email_verified_at' => now(),
            ]
        );

        $adminUser->forceFill([
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => $adminUser->email_verified_at ?? now(),
        ])->save();

        // Assign the Superuser role to the seeded admin account.
        if (!$adminUser->hasRole($superuserRole)) {
            $adminUser->assignRole($superuserRole);
        }

        $superuserRole->givePermissionTo(Permission::all());

        $this->command->info('Admin bruker opprettet eller oppdatert:');
        $this->command->info('E-post: admin@tdpsa.com');
        $this->command->info('Passord: JEStayeqU8h');
        $this->command->info('Rolle: Superuser');
    }
}
