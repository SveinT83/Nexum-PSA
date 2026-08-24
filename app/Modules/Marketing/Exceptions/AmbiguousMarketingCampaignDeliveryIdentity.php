<?php

namespace App\Modules\Marketing\Exceptions;

use RuntimeException;

class AmbiguousMarketingCampaignDeliveryIdentity extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Marketing delivery identity matches multiple ledgers and requires manual review.'
        );
    }
}
