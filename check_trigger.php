<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$res = DB::select('SHOW CREATE TRIGGER em_live_stream_contract_update');
if ($res) {
    echo $res[0]->{'SQL Original Statement'} . "\n";
} else {
    echo "Trigger not found\n";
}
