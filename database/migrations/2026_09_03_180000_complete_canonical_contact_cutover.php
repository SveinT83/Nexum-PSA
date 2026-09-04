<?php

use App\Modules\Contact\Actions\CompleteLegacyContactCutover;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(CompleteLegacyContactCutover::class)->handle();
    }

    public function down(): void
    {
        // Forward-only: copied Contact identities and compatibility evidence
        // may be referenced by production activity after deployment.
    }
};
