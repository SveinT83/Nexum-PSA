<?php

namespace App\Modules\DataExchange\Actions;

use App\Modules\DataExchange\Models\DataExchangeProfile;
use App\Modules\DataExchange\Support\DataExchangeRevision as Revision;
use App\Modules\Economy\Actions\EconomyExportWorkflow;

/** Read-only export review binds the complete profile configuration and actual selected rows. */
class DataExchangeExportWorkflow
{
    public function preview(DataExchangeProfile $profile): array
    {
        abort_unless($profile->status === 'active' && $profile->direction === 'export', 422, 'Use an active export profile.');
        if ($profile->key === 'economy_orders_export') {
            return app(EconomyExportWorkflow::class)->preview();
        }
        $rows = app(RunDataExchangeExport::class)->mappedRows($profile);

        return ['profile_id' => $profile->id, 'profile_revision' => Revision::of($profile), 'format' => $profile->format, 'row_count' => count($rows), 'preview_hash' => Revision::hash([Revision::of($profile), $rows]), 'delivery_will_be_dispatched' => false];
    }

    /** Persist a failed owner run as an exact durable result; never repeat it during command replay. */
    public function execute(DataExchangeProfile $profile, \App\Models\Core\User $actor): \App\Modules\DataExchange\Models\DataExchangeRun
    {
        $before = $profile->runs()->max('id') ?? 0;
        try {
            return app(RunDataExchangeExport::class)->handle($profile, $actor, 'api');
        } catch (\Throwable $error) {
            $run = $profile->runs()->where('triggered_by', $actor->id)->where('id', '>', $before)->latest('id')->first();
            if (! $run || $run->status !== 'failed') {
                throw $error;
            }

            return $run;
        }
    }
}
