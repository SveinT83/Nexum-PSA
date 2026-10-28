<?php

namespace App\Modules\System\Commands;

use App\Modules\System\Queries\RuntimeHealthQuery;
use Illuminate\Console\Command;

final class RuntimeHealthCommand extends Command
{
    protected $signature = 'system:runtime-health';

    protected $description = 'Read metadata-only scheduler and queue freshness without writing or running jobs';

    public function handle(RuntimeHealthQuery $health): int
    {
        $result = $health->get();
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return match ($result['status']) {
            'healthy' => self::SUCCESS,
            'unknown' => self::INVALID,
            default => self::FAILURE,
        };
    }
}
