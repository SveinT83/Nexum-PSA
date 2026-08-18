<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

Schema::table('email_mailbox_placements', function (Blueprint $table) {
    if (Schema::hasColumn('email_mailbox_placements', 'canonical_email_message_id')) {
        $table->dropForeign('em_placement_canonical_fk');
        $table->dropColumn('canonical_email_message_id');
    }
});

$durableTables = [
    'email_canonical_parity_attestation_items',
    'email_canonical_parity_attestations',
    'email_canonical_read_modes',
    'email_canonical_cutover_items',
    'email_canonical_cutover_runs',
    'email_canonical_message_sources',
    'email_canonical_message_attachments',
    'email_canonical_messages',
];

foreach ($durableTables as $table) {
    Schema::dropIfExists($table);
}

echo "Cleanup 111000 done.\n";
