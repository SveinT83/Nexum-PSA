<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\CS\Terms\terms;

class LegalTermsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Default Terms of Service
        terms::updateOrCreate(
            ['name' => 'General Terms of Service'],
            [
                'term' => "1. Acceptance of Terms\nBy accessing and using our services, you agree to be bound by these General Terms of Service.\n\n2. Service Description\nWe provide IT professional services, including but not limited to support, maintenance, and consulting.\n\n3. User Obligations\nYou agree to provide accurate information and maintain the security of your account.\n\n4. Limitation of Liability\nOur liability is limited to the maximum extent permitted by law. We are not liable for indirect or consequential damages.",
                'legal' => "This document constitutes a legally binding agreement between the Client and the Provider. Governed by the laws of Norway."
            ]
        );

        // Privacy Policy / Data Processing Agreement (DPA)
        terms::updateOrCreate(
            ['name' => 'Data Processing Agreement (DPA)'],
            [
                'term' => "1. Purpose of Processing\nWe process personal data solely for the purpose of providing the agreed services.\n\n2. Security Measures\nWe implement appropriate technical and organizational measures to ensure a level of security appropriate to the risk.\n\n3. Sub-processors\nA list of current sub-processors is available upon request.\n\n4. Data Subject Rights\nWe will assist the Client in fulfilling their obligations to respond to requests from data subjects.",
                'legal' => "Compliant with GDPR (General Data Protection Regulation). All data remains within the EEA unless otherwise specified."
            ]
        );

        // Acceptable Use Policy
        terms::updateOrCreate(
            ['name' => 'Acceptable Use Policy'],
            [
                'term' => "1. Prohibited Activities\nYou may not use our services for any illegal activities, including but not limited to unauthorized access, distribution of malware, or harassment.\n\n2. Resource Usage\nUsers must not engage in activities that degrade the performance or security of our systems.",
                'legal' => "Violations of this policy may result in immediate suspension or termination of services."
            ]
        );
    }
}
