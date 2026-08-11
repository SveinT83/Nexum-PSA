<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Services\TrustedSenderAuthenticationFacts;
use App\Modules\Signal\Models\Signal;
use App\Modules\Signal\Models\SignalRule;
use App\Modules\Storage\Jobs\ProcessSupplierOrderImport;
use App\Modules\Storage\Models\PurchaseOrderAutomationPolicy;
use App\Modules\Storage\Models\PurchaseOrderImport;
use App\Modules\Storage\Models\PurchaseOrderImportProfile;
use App\Modules\Storage\Support\SupplierOrderSourceSnapshot;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;

class QueueSupplierOrderImport
{
    public function __construct(
        private readonly GetCurrentPurchaseOrderAutomationPolicy $currentPolicy,
        private readonly CreatePurchaseOrderImport $createImport,
        private readonly SupplierOrderSourceSnapshot $sourceSnapshot,
        private readonly TrustedSenderAuthenticationFacts $trustedAuthentication,
    ) {}

    /**
     * Signal-owned orchestration ends at this idempotent Storage boundary.
     *
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    public function handle(
        Signal $signal,
        SignalRule $rule,
        array $action,
        string $idempotencyKey,
    ): array {
        if ($signal->source_domain !== 'email') {
            return [
                'type' => 'storage_supplier_order_import',
                'status' => 'skipped',
                'message' => 'Supplier-order imports require a durable Email source.',
                'idempotency_key' => $idempotencyKey,
            ];
        }

        $messageId = (int) (data_get($signal->payload, 'email_message_id') ?: $signal->source_id);
        $message = $messageId > 0 ? EmailMessage::withTrashed()->find($messageId) : null;
        if (! $message) {
            return [
                'type' => 'storage_supplier_order_import',
                'status' => 'skipped',
                'message' => 'The source Email message is unavailable.',
                'idempotency_key' => $idempotencyKey,
            ];
        }

        $profile = $this->resolveProfile($action['profile_id'] ?? null);
        $effectivePolicy = $this->currentPolicy->handle();
        $policy = $effectivePolicy['policy'];
        // Signal payloads are routing hints, never an authentication authority.
        $source = $this->sourceSnapshot->fromEmailMessage(
            $message,
            $this->trustedAuthentication->forMessage($message),
        );
        $actor = $this->resolveRequestActor($action, $rule);
        $disabled = $policy->runtime_mode === PurchaseOrderAutomationPolicy::MODE_OFF;

        $result = $this->createImport->handle([
            'source_domain' => 'email',
            'source_type' => $message->getMorphClass(),
            'source_id' => (string) $message->id,
            'email_message_id' => $message->id,
            'signal_id' => $signal->id,
            'signal_rule_id' => $rule->id,
            'signal_action_key' => $idempotencyKey,
            'source_fingerprint' => $source['fingerprint'],
            'safe_source_snapshot' => $source['snapshot'],
            'trusted_auth_snapshot' => $source['snapshot']['trusted_auth'],
            'profile_id' => $profile?->id,
            'profile_version_id' => $profile?->active_version_id,
            'policy_revision_id' => $effectivePolicy['revision']->id,
            'status' => $disabled
                ? PurchaseOrderImport::STATUS_NEEDS_ATTENTION
                : PurchaseOrderImport::STATUS_PENDING,
            'stage' => PurchaseOrderImport::STAGE_DETECT,
            'reason_code' => $disabled ? 'automation_disabled' : null,
            'requested_by' => $actor?->id,
        ]);

        $import = $result['import'];
        if (! $result['created']) {
            return [
                'type' => 'storage_supplier_order_import',
                'status' => $import->purchase_order_id ? 'done' : 'skipped',
                'message' => 'The stable Signal action was already handed to Storage.',
                'import_id' => $import->id,
                'purchase_order_id' => $import->purchase_order_id,
                'idempotency_key' => $idempotencyKey,
            ];
        }

        if (! $disabled) {
            $job = new ProcessSupplierOrderImport($import->id);
            if (($action['queue'] ?? true) === true) {
                Bus::dispatch($job);
            } else {
                app()->call([$job, 'handle']);
            }
        }

        return [
            'type' => 'storage_supplier_order_import',
            'status' => $disabled ? 'recorded' : (($action['queue'] ?? true) ? 'queued' : 'done'),
            'message' => $disabled ? 'Storage automation is disabled; the source was recorded for review.' : null,
            'import_id' => $import->id,
            'idempotency_key' => $idempotencyKey,
        ];
    }

    private function resolveProfile(mixed $profileId): ?PurchaseOrderImportProfile
    {
        if (blank($profileId)) {
            return null;
        }

        $profile = PurchaseOrderImportProfile::query()->find((int) $profileId);
        if (! $profile || in_array($profile->lifecycle_state, ['retired', 'paused'], true)) {
            throw ValidationException::withMessages(['profile_id' => 'The configured supplier profile is unavailable.']);
        }

        return $profile;
    }

    private function resolveRequestActor(array $action, SignalRule $rule): ?User
    {
        $actorId = (int) ($action['actor_id'] ?? $rule->updated_by ?? $rule->created_by ?? 0);

        return $actorId > 0 ? User::query()->find($actorId) : null;
    }
}
