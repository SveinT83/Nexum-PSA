<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

Schema::table('email_mailbox_placements', function (Blueprint $table) {
    if (Schema::hasColumn('email_mailbox_placements', 'uid_namespace_id')) {
        $table->dropForeign('em_place_uid_ns_fk');
        $table->dropIndex('em_place_uid_ns_uid_ix');
        $table->dropColumn('uid_namespace_id');
    }
});

Schema::table('email_folders', function (Blueprint $table) {
    if (Schema::hasColumn('email_folders', 'active_uid_namespace_id')) {
        $table->dropForeign('em_folder_active_uid_ns_fk');
        $table->dropColumn('active_uid_namespace_id');
    }
});

Schema::dropIfExists('email_folder_uid_namespaces');

Schema::table('email_messages', function (Blueprint $table) {
    if (Schema::hasColumn('email_messages', 'imap_uid_validity')) {
        $table->dropColumn('imap_uid_validity');
    }
});
echo "Cleanup done.\n";
