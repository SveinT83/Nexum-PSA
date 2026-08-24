<?php

namespace App\Console\Commands;

use App\Modules\Marketing\Actions\InspectMarketingCampaignDeliveryHistory;
use Illuminate\Console\Command;
use Throwable;

class PreflightMarketingCampaignDeliveryHistoryCommand extends Command
{
    protected $signature = 'marketing:delivery-preflight';

    protected $description = 'Read-only preflight for lifetime Marketing campaign delivery guards';

    public function handle(InspectMarketingCampaignDeliveryHistory $preflight): int
    {
        try {
            $summary = $preflight->handle();
            $this->line((string) json_encode(
                $summary,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));
            $this->info('Read-only preflight complete. No recipient, campaign, queue, or provider state was changed.');

            return ($summary['status'] ?? null) === 'not_installed'
                ? self::FAILURE
                : self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Marketing delivery preflight could not be completed. No write was requested.');

            return self::FAILURE;
        }
    }
}
