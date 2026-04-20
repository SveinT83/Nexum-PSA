<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\CS\Sla\Sla;

class SlaSeeder extends Seeder
{
    /**
     * Seed the application's database with a default SLA policy.
     * This provides a balanced baseline for Low, Medium, and High priority tickets.
     */
    public function run(): void
    {
        Sla::updateOrCreate(
            ['name' => 'Default'],
            [
                'description' => 'Default SLA policy with balanced response and onsite times for different priorities.',

                // Low Priority: 8 hours response, 40 hours onsite (5 business days)
                'low_firstResponse' => 8,
                'low_firstResponse_type' => 'hours',
                'low_onsite' => 40,
                'low_onsite_type' => 'hours',

                // Medium Priority: 4 hours response, 16 hours onsite (2 business days)
                'medium_firstResponse' => 4,
                'medium_firstResponse_type' => 'hours',
                'medium_onsite' => 16,
                'medium_onsite_type' => 'hours',

                // High Priority: 1 hour response, 4 hours onsite
                'high_firstResponse' => 1,
                'high_firstResponse_type' => 'hours',
                'high_onsite' => 4,
                'high_onsite_type' => 'hours',

                'created_by_user_id' => 1, // Assuming admin user ID is 1
            ]
        );
    }
}
