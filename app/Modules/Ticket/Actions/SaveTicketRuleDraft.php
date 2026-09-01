<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Models\TicketRule;
use App\Modules\Ticket\Models\TicketRuleAuthorityFence;
use App\Modules\Ticket\Services\TicketRuleCatalogFingerprint;
use App\Modules\Ticket\Support\TicketRuleActionProviderRegistry;
use App\Modules\Ticket\Support\TicketRuleDefinitionRegistry;
use App\Modules\Ticket\Support\TicketRuleStableJson;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class SaveTicketRuleDraft
{
    private const MAX_BYTES = 262144;

    public function __construct(
        private readonly TicketRuleCatalogFingerprint $fingerprint,
        private readonly TicketRuleActionProviderRegistry $providers,
    ) {}

    /**
     * Draft storage is deliberately isolated from the published definition and
     * every legacy runtime column.
     *
     * @param  array{name: string, description: string|null, definition: array<string, mixed>}  $payload
     */
    public function handle(
        ?TicketRule $rule,
        array $payload,
        User $operator,
        ?string $expectedChecksum = null,
        bool $expectedNoDraft = false,
        ?string $creationToken = null,
    ): TicketRule {
        $this->authorize($operator);
        $payload = $this->validatePayload($payload);
        $checksum = TicketRuleStableJson::checksum($payload);
        $creationToken = $rule === null
            ? $this->validateCreationToken($creationToken)
            : null;

        try {
            return DB::transaction(function () use (
                $rule,
                $payload,
                $operator,
                $expectedChecksum,
                $expectedNoDraft,
                $checksum,
                $creationToken,
            ): TicketRule {
                $fence = TicketRuleAuthorityFence::query()
                    ->whereKey(TicketRuleAuthorityFence::SCOPE)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($rule !== null) {
                    $rule = TicketRule::withTrashed()->lockForUpdate()->findOrFail($rule->getKey());

                    if ($rule->trashed()) {
                        throw new RuntimeException('Deleted Ticket Rules cannot receive drafts.');
                    }
                    if (($expectedNoDraft && $rule->draft_checksum !== null)
                        || ($expectedChecksum !== null
                            && ($rule->draft_checksum === null
                                || ! hash_equals((string) $rule->draft_checksum, $expectedChecksum)))) {
                        throw ValidationException::withMessages([
                            'draft' => 'This draft changed in another session. Reload before saving.',
                        ]);
                    }
                } else {
                    $existing = TicketRule::withTrashed()
                        ->where('draft_creation_token', $creationToken)
                        ->lockForUpdate()
                        ->first();
                    if ($existing !== null) {
                        if ($existing->trashed()
                            || (int) $existing->created_by !== (int) $operator->id
                            || ! is_string($existing->draft_checksum)
                            || ! hash_equals($existing->draft_checksum, $checksum)) {
                            throw ValidationException::withMessages([
                                'draft' => 'This draft creation request was already used. Reload before continuing.',
                            ]);
                        }

                        // A transport retry returns the first durable result without a second write.
                        return $existing->fresh();
                    }

                    $before = $this->fingerprint->checksum();
                    if (! hash_equals((string) $fence->catalog_checksum, $before)) {
                        throw new RuntimeException('Ticket Rule catalog drift must be reconciled before creating a draft.');
                    }

                    $rule = TicketRule::query()->create([
                        'name' => '[Draft] '.$payload['name'],
                        'description' => null,
                        'trigger' => TicketRule::TRIGGER_CREATE,
                        'weight' => 100,
                        'is_active' => false,
                        'stop_processing' => false,
                        'conditions_json' => [],
                        'actions_json' => [],
                        'created_by' => $operator->id,
                        'updated_by' => $operator->id,
                        'lifecycle_status' => TicketRule::LIFECYCLE_DISABLED,
                        'definition_schema_version' => TicketRuleDefinitionRegistry::SCHEMA_VERSION,
                        'compatibility_status' => TicketRule::COMPATIBILITY_UNVERSIONED,
                        'draft_creation_token' => $creationToken,
                    ]);
                    $after = $this->fingerprint->checksum();

                    if (! hash_equals($before, $after)) {
                        $fence->forceFill([
                            'catalog_generation' => (int) $fence->catalog_generation + 1,
                            'catalog_checksum' => $after,
                        ])->save();
                    }
                }

                $rule->forceFill([
                    'draft_payload_json' => $payload,
                    'draft_checksum' => $checksum,
                    'draft_updated_by' => $operator->id,
                    'draft_updated_at' => now(),
                ])->save();

                return $rule->fresh();
            }, 3);
        } catch (QueryException $exception) {
            if ($rule !== null || $creationToken === null) {
                throw $exception;
            }

            $existing = TicketRule::withTrashed()
                ->where('draft_creation_token', $creationToken)
                ->first();
            if ($existing === null) {
                throw $exception;
            }
            if ($existing->trashed()
                || (int) $existing->created_by !== (int) $operator->id
                || ! is_string($existing->draft_checksum)
                || ! hash_equals($existing->draft_checksum, $checksum)) {
                throw ValidationException::withMessages([
                    'draft' => 'This draft creation request was already used. Reload before continuing.',
                ]);
            }

            return $existing->fresh();
        }
    }

    private function authorize(User $operator): void
    {
        if (! $operator->isActive() || ! $operator->can('ticket.manage_rules')) {
            throw new RuntimeException('Ticket Rule management permission is required.');
        }
    }

    private function validateCreationToken(?string $creationToken): string
    {
        $creationToken = strtolower(trim((string) $creationToken));
        if (! Str::isUuid($creationToken)) {
            throw ValidationException::withMessages([
                'draft' => 'The draft creation token is invalid. Reload before saving.',
            ]);
        }

        return $creationToken;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{name: string, description: string|null, definition: array<string, mixed>}
     */
    private function validatePayload(array $payload): array
    {
        if (array_diff(array_keys($payload), ['name', 'description', 'definition']) !== []
            || ! is_string($payload['name'] ?? null)
            || trim($payload['name']) === ''
            || mb_strlen(trim($payload['name'])) > 150
            || (! is_null($payload['description'] ?? null) && ! is_string($payload['description']))
            || ! is_array($payload['definition'] ?? null)) {
            throw ValidationException::withMessages(['draft' => 'The Ticket Rule draft is malformed.']);
        }

        if ($this->providers->containsForbiddenExecutableKey($payload)) {
            throw ValidationException::withMessages([
                'draft' => 'Executable or arbitrary draft fields are not allowed.',
            ]);
        }

        if (strlen(TicketRuleStableJson::encode($payload)) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['draft' => 'The Ticket Rule draft is too large.']);
        }

        return [
            'name' => trim($payload['name']),
            'description' => filled($payload['description'] ?? null)
                ? trim((string) $payload['description'])
                : null,
            'definition' => $payload['definition'],
        ];
    }
}
