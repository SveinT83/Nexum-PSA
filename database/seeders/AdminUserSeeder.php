<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeder.
     */
    public function run(): void
    {
        // Opprett Superuser rolle
        $superuserRole = Role::firstOrCreate([
            'name' => 'Superuser',
            'guard_name' => 'web'
        ]);

        // Opprett admin bruker
        $adminUser = User::firstOrCreate(
            ['email' => 'admin@tdpsa.com'],
            [
                'name' => 'Admin User',
                'email' => 'admin@tdpsa.com',
                'password' => Hash::make('JEStayeqU8h'),
                'email_verified_at' => now(),
            ]
        );

        // Tildel Superuser rolle til admin
        if (!$adminUser->hasRole($superuserRole)) {
            $adminUser->assignRole($superuserRole);
        }

        $this->command->info('Admin bruker opprettet eller oppdatert:');
        $this->command->info('E-post: admin@tdpsa.com');
        $this->command->info('Passord: JEStayeqU8h');
        $this->command->info('Rolle: Superuser');
    }
}
