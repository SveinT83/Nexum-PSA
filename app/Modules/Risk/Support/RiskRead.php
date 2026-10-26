<?php

namespace App\Modules\Risk\Support;

use App\Http\Middleware\EnforceTechRoutePermission;
use App\Models\Risk\RiskAssessment;
use App\Models\Risk\RiskItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class RiskRead
{
    public static function assessment(RiskAssessment $r): RiskAssessment
    {
        return $r->fresh()->load(['client', 'workContext', 'items.category', 'items.updates', 'approver']);
    }

    public static function item(RiskItem $i): RiskItem
    {
        return $i->fresh()->load(['assessment', 'category', 'updates']);
    }

    public static function size(Request $r): int
    {
        $v = $r->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);

        return (int) ($v['per_page'] ?? 15);
    }

    public static function can(string $permission): bool
    {
        $actor = Auth::user();

        return $actor && $actor->isActive() && app(EnforceTechRoutePermission::class)->allowsActor($actor, $permission);
    }

    public static function approvalValid(RiskAssessment $r): bool
    {
        return $r->status === 'approved' && $r->approved_at !== null && $r->approved_by !== null && $r->is_approvable;
    }
}
