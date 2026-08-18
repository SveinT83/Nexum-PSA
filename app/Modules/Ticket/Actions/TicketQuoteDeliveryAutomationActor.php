<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\UserManagement\Actions\EnsureSystemActor;

/**
 * Resolve the protected identity used for accepted Ticket quote delivery work.
 */
class TicketQuoteDeliveryAutomationActor
{
    public const KEY = 'ticket_quote_delivery_automation';

    private const PERMISSIONS = [
        'ticket.update',
        'storage.reserve',
        'storage.purchase_manage',
    ];

    public function __construct(private readonly EnsureSystemActor $ensureSystemActor) {}

    public function resolve(): User
    {
        return $this->ensureSystemActor->handle(
            key: self::KEY,
            name: 'Nexum Ticket Quote Delivery Automation',
            email: 'ticket-quote-delivery@system.nexum.invalid',
            permissions: self::PERMISSIONS,
        );
    }
}
