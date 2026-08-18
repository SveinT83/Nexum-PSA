<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

$tables = [
    'email_live_projection_deliveries',
    'email_live_projection_publications',
    'email_live_projection_changes',
    'email_live_projection_streams',
    'email_live_account_authority',
    'email_live_global_authority',
    'email_live_user_access',
    'email_live_user_content_authority_paths',
];

foreach ($tables as $table) {
    Schema::dropIfExists($table);
}

// Drop added columns and indexes
try { DB::statement("ALTER TABLE user_management DROP INDEX em_live_user_status_cursor_ix"); } catch (\Exception $e) {}
try { DB::statement("ALTER TABLE user_management DROP COLUMN email_live_enable_generation"); } catch (\Exception $e) {}

try { DB::statement("ALTER TABLE email_accounts DROP INDEX em_live_account_owner_cursor_ix"); } catch (\Exception $e) {}
try { DB::statement("ALTER TABLE email_accounts DROP COLUMN email_live_owner_enable_generation"); } catch (\Exception $e) {}

try { DB::statement("ALTER TABLE email_account_user_grants DROP INDEX em_live_grant_account_cursor_ix"); } catch (\Exception $e) {}
try { DB::statement("ALTER TABLE email_account_user_grants DROP INDEX em_live_grant_user_cursor_ix"); } catch (\Exception $e) {}
try { DB::statement("ALTER TABLE email_account_user_grants DROP COLUMN email_live_enable_generation"); } catch (\Exception $e) {}

try { DB::statement("ALTER TABLE email_mailbox_delegations DROP INDEX em_live_delegate_account_cursor_ix"); } catch (\Exception $e) {}
try { DB::statement("ALTER TABLE email_mailbox_delegations DROP INDEX em_live_delegate_user_cursor_ix"); } catch (\Exception $e) {}
try { DB::statement("ALTER TABLE email_mailbox_delegations DROP INDEX em_live_delegate_start_boundary_ix"); } catch (\Exception $e) {}
try { DB::statement("ALTER TABLE email_mailbox_delegations DROP INDEX em_live_delegate_expiry_boundary_ix"); } catch (\Exception $e) {}
try { DB::statement("ALTER TABLE email_mailbox_delegations DROP COLUMN email_live_enable_generation, DROP COLUMN email_live_start_invalidated_at, DROP COLUMN email_live_expiry_invalidated_at"); } catch (\Exception $e) {}

try { DB::statement("ALTER TABLE email_break_glass_accesses DROP INDEX em_live_break_account_cursor_ix"); } catch (\Exception $e) {}
try { DB::statement("ALTER TABLE email_break_glass_accesses DROP INDEX em_live_break_actor_cursor_ix"); } catch (\Exception $e) {}
try { DB::statement("ALTER TABLE email_break_glass_accesses DROP INDEX em_live_break_start_boundary_ix"); } catch (\Exception $e) {}
try { DB::statement("ALTER TABLE email_break_glass_accesses DROP INDEX em_live_break_expiry_boundary_ix"); } catch (\Exception $e) {}
try { DB::statement("ALTER TABLE email_break_glass_accesses DROP COLUMN email_live_enable_generation, DROP COLUMN email_live_start_invalidated_at, DROP COLUMN email_live_expiry_invalidated_at"); } catch (\Exception $e) {}

try { DB::statement("ALTER TABLE email_folders DROP INDEX em_live_folder_account_cursor_ix"); } catch (\Exception $e) {}

// Drop triggers - this is complex, but I'll try to drop the ones that might have been created
$triggers = DB::select("SHOW TRIGGERS");
foreach ($triggers as $trigger) {
    if (str_starts_with($trigger->Trigger, 'em_live_')) {
        DB::statement("DROP TRIGGER IF EXISTS {$trigger->Trigger}");
    }
}

echo "Cleanup 130000 done.\n";
