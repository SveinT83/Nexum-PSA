<?php

namespace App\Modules\Relationship\Actions;

use App\Modules\Relationship\Models\NexumRelationship;
use App\Modules\Relationship\Support\RelationshipCapability;
use App\Modules\Relationship\Support\RelationshipDirection;
use App\Modules\Relationship\Support\RelationshipHealthStatus;
use App\Modules\Relationship\Support\RelationshipStatus;
use App\Modules\Relationship\Support\RelationshipType;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Shared form rules; API normalization preserves omitted settings. */
class RelationshipConfiguration
{
    public function validateForm(Request $request, NexumRelationship $relationship): array
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'direction' => ['required', Rule::in(RelationshipDirection::values())],
            'relationship_type' => ['required', Rule::in(RelationshipType::values())],
            'client_id' => ['nullable', Rule::exists('clients', 'id')],
            'vendor_id' => ['nullable', Rule::exists('vendors', 'id')],
            'remote_base_url' => ['nullable', 'url', 'max:255'],
            'remote_instance_id' => ['nullable', 'string', 'max:255'],
            'remote_organization_name' => ['nullable', 'string', 'max:255'],
            'remote_organization_identifier' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(RelationshipStatus::values())],
            'capabilities' => ['nullable', 'array'],
            'capabilities.*' => ['string', Rule::in(RelationshipCapability::values())],
            'ticket_queue_id' => ['nullable', Rule::exists('ticket_queues', 'id')->where('is_active', true)],
            'ticket_auto_create_queue' => ['nullable', 'boolean'],
            'documentation_two_way' => ['nullable', 'boolean'],
            'attachment_max_mb' => ['nullable', 'integer', 'min:1', 'max:100'],
            'attachment_allowed_content_types' => ['nullable', 'string', 'max:2000'],
            'service_areas' => ['nullable', 'string', 'max:2000'],
            'status_mapping' => ['nullable', 'string', 'max:4000'],
            'outbound_token' => ['nullable', 'string', 'max:2000'],
            'webhook_secret' => ['nullable', 'string', 'max:2000'],
            'inbound_token' => ['nullable', 'string', 'max:2000'],
        ]);

        $validator->after(function ($validator) use ($request, $relationship): void {
            $direction = $request->input('direction');
            $status = $request->input('status');
            $enabledCapabilities = collect($request->input('capabilities', []));

            if ($direction === RelationshipDirection::WE_ARE_PROVIDER && ! $request->filled('client_id')) {
                $validator->errors()->add('client_id', 'Provider relationships must be linked to a client.');
            }

            if ($direction === RelationshipDirection::WE_USE_PROVIDER && ! $request->filled('vendor_id')) {
                $validator->errors()->add('vendor_id', 'Upstream provider relationships must be linked to a vendor.');
            }

            if ($direction === RelationshipDirection::COLLABORATION && $status === RelationshipStatus::ACTIVE) {
                $validator->errors()->add('status', 'Collaboration relationships cannot be activated yet.');
            }

            if ($status === RelationshipStatus::ACTIVE) {
                if (! $request->filled('remote_base_url')) {
                    $validator->errors()->add('remote_base_url', 'Active relationships require a remote base URL.');
                }

                if (! $request->filled('remote_organization_name') && ! $request->filled('remote_organization_identifier')) {
                    $validator->errors()->add('remote_organization_name', 'Active relationships require remote organization identity.');
                }

                $hasInboundToken = $relationship->exists && filled($relationship->inbound_token_hash);
                if (! $hasInboundToken && ! $request->filled('inbound_token')) {
                    $validator->errors()->add('inbound_token', 'Active relationships require an inbound token.');
                }

                $hasWebhookSecret = $relationship->exists && filled($relationship->webhook_secret_encrypted);
                if (! $hasWebhookSecret && ! $request->filled('webhook_secret')) {
                    $validator->errors()->add('webhook_secret', 'Active relationships require a webhook signing secret.');
                }

                if ($enabledCapabilities->intersect([
                    RelationshipCapability::TICKET_SYNC,
                    RelationshipCapability::STATUS_SYNC,
                    RelationshipCapability::DOCUMENTATION_SYNC,
                    RelationshipCapability::KNOWLEDGE_SYNC,
                ])->isNotEmpty()) {
                    $hasOutboundToken = $relationship->exists && filled($relationship->outbound_token_encrypted);
                    if (! $hasOutboundToken && ! $request->filled('outbound_token')) {
                        $validator->errors()->add('outbound_token', 'Outbound sync capabilities require an outbound token.');
                    }
                }
            }
        });

        $validated = $validator->validate();
        $capabilities = RelationshipCapability::defaults();
        foreach (($validated['capabilities'] ?? []) as $capability) {
            $capabilities[$capability] = true;
        }

        return array_merge(Arr::only($validated, [
            'name',
            'direction',
            'relationship_type',
            'client_id',
            'vendor_id',
            'remote_base_url',
            'remote_instance_id',
            'remote_organization_name',
            'remote_organization_identifier',
            'status',
            'outbound_token',
            'webhook_secret',
            'inbound_token',
        ]), [
            'health_status' => $relationship->health_status ?: RelationshipHealthStatus::UNKNOWN,
            'capabilities' => $capabilities,
            'ticket_policy' => [
                'queue_id' => $validated['ticket_queue_id'] ?? null,
                'auto_create_queue' => (bool) ($validated['ticket_auto_create_queue'] ?? false),
            ],
            'documentation_policy' => [
                'two_way' => (bool) ($validated['documentation_two_way'] ?? false),
            ],
            'attachment_policy' => [
                'max_mb' => (int) ($validated['attachment_max_mb'] ?? 10),
                'allowed_content_types' => $this->lines($validated['attachment_allowed_content_types'] ?? ''),
            ],
            'status_mapping' => $this->mapping($validated['status_mapping'] ?? ''),
            'service_areas' => $this->lines($validated['service_areas'] ?? ''),
        ]);
    }

    private function lines(string $value): array
    {
        return collect(preg_split('/[\r\n,]+/', $value))
            ->map(fn ($line) => trim($line))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function mapping(string $value): array
    {
        return collect(preg_split('/[\r\n]+/', $value))
            ->map(fn ($line) => trim($line))
            ->filter(fn ($line) => str_contains($line, '='))
            ->mapWithKeys(function ($line): array {
                [$local, $remote] = array_map('trim', explode('=', $line, 2));

                return $local !== '' && $remote !== '' ? [$local => $remote] : [];
            })
            ->all();
    }
}
