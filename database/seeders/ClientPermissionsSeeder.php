<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class ClientPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'client.view',
            'client.create',
            'client.edit',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }
    }
}
