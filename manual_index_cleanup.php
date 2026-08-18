<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

try {
    Illuminate\Support\Facades\DB::statement("ALTER TABLE email_messages DROP INDEX tmp_acct_fk_idx");
} catch (\Exception $e) {}

try {
    Illuminate\Support\Facades\DB::statement("ALTER TABLE email_provider_inventory_folders DROP INDEX tmp_inv_run_fk_idx");
} catch (\Exception $e) {}

echo "Manual index cleanup done.\n";
