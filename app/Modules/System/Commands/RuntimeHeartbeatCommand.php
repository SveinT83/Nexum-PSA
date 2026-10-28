<?php

namespace App\Modules\System\Commands;

use App\Modules\System\Services\RuntimeHeartbeat;
use Illuminate\Console\Command;

final class RuntimeHeartbeatCommand extends Command
{
    protected $signature = 'system:runtime-heartbeat';

    protected $description = 'Record scheduler evidence and publish only explicitly allowlisted bounded queue probes';

    public function handle(RuntimeHeartbeat $heartbeat): int
    {
        $result = $heartbeat->tick();
        $this->line(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $result['status'] === 'recorded' ? self::SUCCESS : self::FAILURE;
    }
}
