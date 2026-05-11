<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class UnitSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $units = [
            ['name' => 'Stykk', 'short' => 'stk'],
            ['name' => 'Time', 'short' => 't'],
            ['name' => 'Måned', 'short' => 'mnd'],
            ['name' => 'År', 'short' => 'år'],
            ['name' => 'Lisens', 'short' => 'lis'],
            ['name' => 'Bruker', 'short' => 'bruker'],
        ];

        foreach ($units as $unit) {
            \App\Modules\Commercial\Models\Economy\Units::updateOrCreate(['name' => $unit['name']], $unit);
        }
    }
}
