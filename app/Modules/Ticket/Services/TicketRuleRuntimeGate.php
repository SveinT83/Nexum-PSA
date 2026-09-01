<?php

namespace App\Modules\Ticket\Services;

use App\Models\Core\User;
use App\Modules\Ticket\Actions\TicketRuleAutomationActor;
use App\Modules\Ticket\Models\TicketRuleAuthorityFence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Spatie\Permission\Models\Permission;

final class TicketRuleRuntimeGate
{
    public function enabled(): bool
    {
        if (! (bool) config('ticket_rules.v2_enabled', false)
            || ! Schema::hasTable('ticket_rule_authority_fences')
            || ! Schema::hasTable('ticket_rule_runs')) {
            return false;
        }

        return TicketRuleAuthorityFence::query()
            ->whereKey(TicketRuleAuthorityFence::SCOPE)
            ->where('runtime_authority', TicketRuleAuthorityFence::AUTHORITY_V2)
            ->exists();
    }

    /**
     * Resolve the creation authority while holding the catalog fence until the
     * caller's outer Ticket transaction commits.
     */
    public function creationUsesV2(): bool
    {
        return $this->authorityUsesV2('creation');
    }

    /**
     * Resolve update/message/tag/assignment authority in the same transaction
     * as the original Ticket mutation. An authoritative stale worker fails
     * closed instead of silently omitting automation.
     */
    public function mutationUsesV2(): bool
    {
        return $this->authorityUsesV2('mutation');
    }

    private function authorityUsesV2(string $operation): bool
    {
        if (! Schema::hasTable('ticket_rule_authority_fences')) {
            return false;
        }

        if (DB::connection()->transactionLevel() < 1) {
            throw new RuntimeException(
                "Ticket Rule {$operation} authority must be resolved inside the Ticket transaction.",
            );
        }

        $fence = TicketRuleAuthorityFence::query()
            ->whereKey(TicketRuleAuthorityFence::SCOPE)
            ->sharedLock()
            ->firstOrFail();

        if ($fence->runtime_authority === TicketRuleAuthorityFence::AUTHORITY_V2) {
            if (! (bool) config('ticket_rules.v2_enabled', false)
                || ! Schema::hasTable('ticket_rule_runs')) {
                throw new RuntimeException('Ticket Rule v2 is authoritative but this worker lacks the required capability or schema.');
            }

            return true;
        }

        if ($fence->runtime_authority !== TicketRuleAuthorityFence::AUTHORITY_LEGACY) {
            throw new RuntimeException("Ticket Rule {$operation} authority is unknown.");
        }

        return false;
    }

    public function assertMutationCapabilities(bool $allowExplicitSqliteTestOverride = false): void
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        $sqliteTestOverride = $allowExplicitSqliteTestOverride
            && app()->environment('testing')
            && (bool) config('ticket_rules.allow_sqlite_mutations_for_tests', false);
        // SQLite savepoints do not make lockForUpdate() an authoritative row lock.
        // Permit SQLite only for explicitly opted-in, isolated transaction tests.
        if (($driver !== 'mysql' && ! ($driver === 'sqlite' && $sqliteTestOverride))
            || ! $connection->getQueryGrammar()->supportsSavepoints()) {
            throw new RuntimeException("Ticket Rule mutations require row-lock and savepoint support; [{$driver}] is unsupported.");
        }

        if ($connection->transactionLevel() < 1) {
            throw new RuntimeException('Ticket Rule mutations must run inside the authoritative Ticket transaction.');
        }
    }

    public function requireExistingActor(): User
    {
        $actor = User::query()
            ->where('system_actor_key', TicketRuleAutomationActor::KEY)
            ->first();

        if (! $actor || ! $actor->isSystemActor() || $actor->isActive() || $actor->roles()->exists()) {
            throw new RuntimeException('The protected Ticket Rule automation actor is unavailable or invalid.');
        }

        $required = collect(TicketRuleAutomationActor::PERMISSIONS)->sort()->values();
        $permissionRows = Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $required)
            ->pluck('name')
            ->sort()
            ->values();

        if ($permissionRows->all() !== $required->all()) {
            throw new RuntimeException('A required Ticket Rule automation permission is not deployed.');
        }

        $actual = $actor->getDirectPermissions()->pluck('name')->sort()->values();
        if ($actual->all() !== $required->all()) {
            throw new RuntimeException('The Ticket Rule automation actor permission set has drifted.');
        }

        return $actor;
    }
}
