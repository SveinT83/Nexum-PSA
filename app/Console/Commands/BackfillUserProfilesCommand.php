<?php

namespace App\Console\Commands;

use App\Modules\UserManagement\Actions\BackfillUserProfiles;
use Illuminate\Console\Command;

class BackfillUserProfilesCommand extends Command
{
    protected $signature = 'user-profiles:backfill {--force : Overwrite existing profile fields with migrated source data}';

    protected $description = 'Create or repair canonical User Management profile rows for existing users';

    public function handle(BackfillUserProfiles $backfill): int
    {
        $summary = $backfill->handle((bool) $this->option('force'));

        foreach ($summary as $key => $value) {
            $this->line(str_replace('_', ' ', $key).': '.$value);
        }

        return self::SUCCESS;
    }
}
