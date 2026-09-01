<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Models\TicketRuleAuthorityFence;
use App\Modules\Ticket\Services\TicketRuleCatalogFingerprint;
use App\Modules\Ticket\Services\TicketRuleRuntimeGate;
use App\Modules\Ticket\Support\TicketRuleStableJson;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class ActivateTicketRuleV2Authority
{
    public function __construct(
        private readonly TicketRuleCatalogFingerprint $fingerprint,
        private readonly InspectTicketRuleCompatibility $compatibility,
        private readonly TicketRuleRuntimeGate $runtimeGate,
    ) {}

    /** @return array<string, mixed> */
    public function handle(
        int $expectedGeneration,
        string $expectedChecksum,
        User $operator,
    ): array {
        $expectedChecksum = strtolower(trim($expectedChecksum));

        if (! preg_match('/\A[a-f0-9]{64}\z/', $expectedChecksum)) {
            throw new InvalidArgumentException('Expected checksum must be a lowercase SHA-256 value.');
        }
        if (! $operator->isActive() || ! $operator->can('ticket.rule_publish')) {
            throw new RuntimeException('Ticket Rule publication authority is required for activation.');
        }
        if (! (bool) config('ticket_rules.v2_enabled', false)) {
            throw new RuntimeException('The Ticket Rule v2 capability is disabled.');
        }

        return DB::transaction(function () use (
            $expectedGeneration,
            $expectedChecksum,
            $operator,
        ): array {
            $this->runtimeGate->assertMutationCapabilities();
            $this->runtimeGate->requireExistingActor();

            $fence = TicketRuleAuthorityFence::query()
                ->whereKey(TicketRuleAuthorityFence::SCOPE)
                ->lockForUpdate()
                ->firstOrFail();

            if ($fence->runtime_authority !== TicketRuleAuthorityFence::AUTHORITY_LEGACY) {
                throw new RuntimeException('Ticket Rule runtime authority is not legacy.');
            }

            if ($fence->runtime_activated_at !== null
                || $fence->runtime_activated_by !== null
                || $fence->runtime_activation_checksum !== null) {
                throw new RuntimeException('Prior Ticket Rule activation evidence cannot be overwritten.');
            }

            $catalogChecksum = $this->fingerprint->checksum();
            $evidence = $this->compatibility->handle();

            if ((int) $fence->catalog_generation !== $expectedGeneration
                || ! hash_equals($expectedChecksum, $catalogChecksum)
                || ! hash_equals((string) $fence->catalog_checksum, $catalogChecksum)
                || ($evidence['status'] ?? null) !== 'compatible'
                || ! ($evidence['mapping_complete'] ?? false)) {
                throw new RuntimeException('Ticket Rule activation evidence changed; run compatibility preflight again.');
            }

            $activationChecksum = TicketRuleStableJson::checksum([
                'catalog_generation' => $expectedGeneration,
                'catalog_checksum' => $catalogChecksum,
                'counts' => $evidence['counts'] ?? [],
                'runtime_authority' => TicketRuleAuthorityFence::AUTHORITY_V2,
            ]);

            $fence->forceFill([
                'runtime_authority' => TicketRuleAuthorityFence::AUTHORITY_V2,
                'runtime_activated_at' => now(),
                'runtime_activated_by' => $operator->id,
                'runtime_activation_checksum' => $activationChecksum,
            ])->save();

            return [
                'status' => 'activated',
                'runtime_authority' => $fence->runtime_authority,
                'catalog_generation' => (int) $fence->catalog_generation,
                'catalog_checksum' => $catalogChecksum,
                'activation_checksum' => $activationChecksum,
            ];
        }, 3);
    }
}
