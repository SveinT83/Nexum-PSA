<?php

namespace App\Modules\DataExchange\Actions;

use App\Models\Core\User;
use App\Modules\DataExchange\Models\DataExchangeDeliveryAttempt;
use App\Modules\DataExchange\Models\DataExchangeDeliveryTarget;
use App\Modules\DataExchange\Models\DataExchangeFile;
use App\Modules\DataExchange\Models\DataExchangeSchedule;
use App\Modules\DataExchange\Support\DataExchangeAuthority;

/** Existing approved schedule/job delivery shares the verified file transport without enabling API delivery. */
class DeliverDataExchangeFile
{
    public function handle(DataExchangeFile $file, DataExchangeDeliveryTarget $target, ?DataExchangeSchedule $schedule = null): DataExchangeDeliveryAttempt
    {
        $actor = User::find($schedule?->created_by ?: $file->generated_by);
        DataExchangeAuthority::runtime($file->profile, $actor, 'delivery');
        $delivery = app(VerifiedDataExchangeDelivery::class);

        return $delivery->perform($delivery->prepare($file, $target, $schedule, false));
    }
}
