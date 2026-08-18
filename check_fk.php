<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$results = Illuminate\Support\Facades\DB::select("
    SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_COLUMN_NAME
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE REFERENCED_TABLE_NAME = 'email_message_user_states'
    AND TABLE_SCHEMA = DATABASE()
");
foreach ($results as $row) {
    echo "Table: {$row->TABLE_NAME}, Constraint: {$row->CONSTRAINT_NAME}, Column: {$row->COLUMN_NAME} -> {$row->REFERENCED_COLUMN_NAME}\n";
}
