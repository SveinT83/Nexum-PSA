<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\UserManagement\Actions\EnsureSystemActor;

/**
 * Resolve the stable audit identity used by unattended supplier-order actions.
 */
class SupplierOrderAutomationActor
{
    public const KEY = 'storage_supplier_order_automation';

    private const PERMISSIONS = [
        'storage.purchase_manage',
        'storage.purchase_import_profile_manage',
        'documentation.create',
    ];

    public function __construct(private readonly EnsureSystemActor $ensureSystemActor) {}

    public function resolve(): User
    {
        return $this->ensureSystemActor->handle(
            key: self::KEY,
            name: 'Nexum Supplier Order Automation',
            email: 'supplier-order-automation@system.nexum.invalid',
            permissions: self::PERMISSIONS,
        );
    }

    public static function canAct(?User $actor, string $permission): bool
    {
        if (! $actor || (! $actor->isActive() && ! $actor->isSystemActor())) {
            return false;
        }

        return $actor->can($permission);
    }
}
