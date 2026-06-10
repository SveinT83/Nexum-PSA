<?php

namespace Database\Seeders;

use App\Modules\Marketing\Actions\EnsureMarketingDefaults;
use Illuminate\Database\Seeder;

class MarketingSeeder extends Seeder
{
    public function run(EnsureMarketingDefaults $defaults): void
    {
        $defaults->handle();
    }
}
