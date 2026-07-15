<?php

namespace App\Modules\DataExchange\Jobs;

use App\Modules\DataExchange\Actions\DeliverDataExchangeFile;
use App\Modules\DataExchange\Models\DataExchangeDeliveryTarget;
use App\Modules\DataExchange\Models\DataExchangeFile;
use App\Modules\DataExchange\Models\DataExchangeSchedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverDataExchangeFileJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $fileId,
        public int $targetId,
        public ?int $scheduleId = null,
    ) {}

    public function handle(DeliverDataExchangeFile $delivery): void
    {
        $file = DataExchangeFile::query()->find($this->fileId);
        $target = DataExchangeDeliveryTarget::query()->find($this->targetId);
        $schedule = $this->scheduleId ? DataExchangeSchedule::query()->find($this->scheduleId) : null;

        if ($file && $target) {
            $delivery->handle($file, $target, $schedule);
        }
    }
}
