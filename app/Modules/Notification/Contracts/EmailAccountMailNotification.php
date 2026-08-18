<?php

namespace App\Modules\Notification\Contracts;

/**
 * Marks a Laravel notification as frozen to one Email account provider binding.
 *
 * The snapshot is captured before a queued notification payload is created and
 * revalidated by the Notification-owned channel immediately before delivery.
 */
interface EmailAccountMailNotification
{
    /**
     * @return array{
     *     captured: bool,
     *     scope: string|null,
     *     account_id: int|null,
     *     provider_binding_version: int|null,
     *     failure_code: string|null
     * }
     */
    public function emailAccountMailSnapshot(): array;
}
