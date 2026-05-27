<?php

namespace Database\Seeders;

use App\Modules\Email\Actions\EnsureDefaultEmailTemplates;
use Illuminate\Database\Seeder;

class EmailTemplateSeeder extends Seeder
{
    /*
    |--------------------------------------------------------------------------
    | Default outbound email templates
    |--------------------------------------------------------------------------
    |
    | New installations should be able to send practical ticket and system
    | emails immediately. Admins can later edit these templates from the
    | Templates hub without changing application code.
    |
    | Keep these keys stable. Outbound flows should reference keys such as
    | tickets/ticket_reply instead of hard-coded template IDs.
    |
    */
    public function run(EnsureDefaultEmailTemplates $templates): void
    {
        $templates->handle();
    }
}
