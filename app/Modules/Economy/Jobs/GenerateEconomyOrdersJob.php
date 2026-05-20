<?php

namespace App\Modules\Economy\Jobs;

use App\Modules\Economy\Actions\GenerateOrders;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class GenerateEconomyOrdersJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly ?string $periodStart = null,
        private readonly ?string $periodEnd = null,
        private readonly ?int $actorId = null,
    ) {
    }

    public function handle(GenerateOrders $generateOrders): void
    {
        $actor = $this->actorId ? \App\Models\Core\User::find($this->actorId) : null;

        $generateOrders->handle(
            $this->periodStart ? Carbon::parse($this->periodStart) : null,
            $this->periodEnd ? Carbon::parse($this->periodEnd) : null,
            $actor
        );
    }
}
