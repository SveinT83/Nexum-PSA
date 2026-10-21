<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailRule;
use App\Modules\Integration\Support\DomainApiAudit;
use Illuminate\Support\Facades\DB;

/** The existing Admin delete requires publication authority through either interface. */
final class DeleteEmailAdminRule
{
    public function handle(User $actor, EmailRule $rule): void
    {
        abort_unless($actor->isActive() && ! $actor->isSystemActor()
            && $actor->can('email.rule_manage') && $actor->can('email.rule_publish'), 403);
        abort_unless($rule->isAdminManaged(), 404);
        DB::transaction(function () use ($actor, $rule): void {
            $before = $rule->only(['id', 'name', 'is_active', 'lifecycle_status']);
            app(DomainApiAudit::class)->record($actor, 'email', 'email.rules.delete', $rule, $before, []);
            $rule->delete();
        });
    }
}
