<?php

namespace App\Modules\Notification\Contracts;

/** Internal installation capability; paths stay inside the server boundary and never enter a browser DTO. */
interface WebPushInstallationProfile
{
    public function resolve(): array;
}
