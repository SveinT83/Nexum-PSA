<?php

namespace App\Modules\Notification\Contracts;

/** Exact existing default-queue authority/evidence; observing this contract never enables or starts anything. */
interface WebPushQueueEvidence
{
    public function get(): array;
}
