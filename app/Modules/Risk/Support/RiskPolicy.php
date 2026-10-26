<?php

namespace App\Modules\Risk\Support;

use App\Http\Middleware\EnforceTechRoutePermission;
use App\Models\Core\User;
use App\Models\Risk\RiskAssessment;
use App\Models\Risk\RiskItem;
use App\Models\Risk\RiskItemUpdate;
use Illuminate\Support\Facades\Auth;

/** Current browser rights and creator rules; receipts never confer ownership. */
final class RiskPolicy
{
    public static function actor(?string $permission = null): User
    {
        $old = Auth::user();
        $actor = $old ? User::find($old->id) : null;
        abort_unless($actor && $actor->isActive(), 403, 'An active Risk actor is required.');
        if (method_exists($old, 'currentAccessToken')) {
            $actor->withAccessToken($old->currentAccessToken());
        }
        Auth::setUser($actor);
        if ($permission) {
            abort_unless(app(EnforceTechRoutePermission::class)->allowsActor($actor, $permission), 403, 'The active user lacks the Risk permission.');
        }

        return $actor;
    }

    public static function assessment(int $id): RiskAssessment
    {
        return RiskAssessment::query()->findOrFail($id);
    }

    public static function item(int $id): RiskItem
    {
        $item = RiskItem::query()->findOrFail($id);
        self::assessment($item->risk_assessment_id);

        return $item;
    }

    public static function update(int $id): RiskItemUpdate
    {
        $update = RiskItemUpdate::query()->findOrFail($id);
        self::item($update->risk_item_id);

        return $update;
    }

    public static function editItem(RiskItem $item): void
    {
        $actor = self::actor('risk.update');
        $creator = $item->updates()->reorder()->orderBy('created_at')->orderBy('id')->value('created_by');
        abort_unless($actor->hasRole('Superuser') || ($creator && (int) $creator === (int) $actor->id), 403, 'Only the original-history creator or Superuser may edit this item.');
    }

    public static function deleteUpdate(?int $creator): void
    {
        $actor = self::actor('risk.delete');
        abort_unless($actor->hasRole('Superuser') || ($creator && (int) $creator === (int) $actor->id), 403, 'Only the update creator or Superuser may delete this history.');
    }

    public static function superuser(): void
    {
        abort_unless(self::actor('risk.delete')->hasRole('Superuser'), 403, 'Only Superuser may delete this Risk record.');
    }

    public static function context(array $context, ?array $stored = null): mixed
    {
        if (isset($context['scope'])) {
            abort_unless(self::actor()->tokenCan($context['scope']), 403, 'The token no longer grants the original Risk operation.');
        }
        foreach ($context['permissions'] ?? [] as $permission) {
            self::actor($permission);
        }
        $kind = $context['kind'];
        $id = (int) ($context['id'] ?? 0);
        $rule = $context['rule'] ?? 'read';
        if ($rule === 'delete_assessment' || $rule === 'delete_item') {
            self::superuser();
        }
        if ($kind === 'settings') {
            return null;
        }
        if ($kind === 'assessment') {
            if ($stored && ($stored['deleted'] ?? false)) {
                return null;
            }

            return self::assessment($id);
        }
        if ($kind === 'item') {
            if ($stored && ($stored['deleted'] ?? false)) {
                return null;
            }
            $item = self::item($id);
            if ($rule === 'edit_item') {
                self::editItem($item);
            }

            return $item;
        }
        if ($kind === 'update') {
            if ($stored && ($stored['deleted'] ?? false)) {
                self::deleteUpdate($stored['update_creator'] ?? null);

                return null;
            }
            $update = self::update($id);
            if ($rule === 'delete_update') {
                self::deleteUpdate($update->created_by);
            }

            return $update;
        }
        abort(422, 'Unsupported Risk command target.');
    }
}
