<?php

namespace App\Modules\Email\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Settings\CommonSetting;
use App\Modules\Email\Services\EmailRetentionEligibilityService;
use App\Modules\Email\Services\EmailSystemHealth;
use App\Modules\Email\Services\MailAiAgentRuntime;
use App\Modules\Email\Support\InboundAttachmentPolicy;
use App\Modules\Integration\Models\AiAgent;
use App\Modules\Integration\Services\AiAgentResolver;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ConfigController extends Controller
{
    private const MAIL_AI_DEFAULT_AGENT_FIELD = 'mail_ai_default_agent_id';

    private const LEGACY_MAIL_AI_WORKLOAD_SETTING = 'mail_ai_workload_profile_id';

    public function index(
        Request $request,
        EmailSystemHealth $health,
        EmailRetentionEligibilityService $retentionEligibility,
        AiAgentResolver $agentResolver,
        MailAiAgentRuntime $mailAiRuntime,
    ) {
        $settings = CommonSetting::where('type', 'emailhub')->get()->pluck('value', 'name')->toArray();
        $mailAiAgents = $this->mailAiAgents();
        $mailAiGlobalFallbackAgent = $mailAiAgents->firstWhere('is_default', true);
        $mailAiDefaultAgent = $request->user() ? $agentResolver->defaultAgent($request->user(), 'email') : null;
        $mailAiRuntimeAvailability = $request->user()
            ? $mailAiRuntime->availability($request->user())
            : ['available' => false, 'reason' => 'user_missing', 'agent' => null, 'model' => null];

        // Merge with defaults
        $config = array_merge([
            'poll_interval' => 1,
            'concurrency' => 2,
            'batch_size' => 20,
            'delete_on_success' => '0', // Legacy Ticket-ingest cleanup; normal Mail client sync keeps provider mail.
            'size_limit_mb' => 25,
            'retention_months' => 24,
            // Full provider inventories are an explicit rollout decision. Keep
            // reconciliation inert until an administrator enables it after review.
            'provider_deletion_reconciliation_enabled' => '0',
            'pause_ingest' => '0',
            'attachment_max_count' => InboundAttachmentPolicy::DEFAULT_MAX_COUNT,
            'attachment_max_size_mb' => InboundAttachmentPolicy::DEFAULT_MAX_SIZE_MB,
            'attachment_allowed_mime_types' => implode("\n", InboundAttachmentPolicy::DEFAULT_ALLOWED_MIME_TYPES),
            'trusted_authserv_ids' => '',
            'trusted_receiving_hops' => '',
            'max_failures' => 3,
            self::LEGACY_MAIL_AI_WORKLOAD_SETTING => '',
        ], $settings);

        return view('email::Admin.Config.index', [
            'config' => $config,
            'health' => $health->snapshot($config),
            'retentionPreview' => $retentionEligibility->preview((int) $config['retention_months']),
            'mailAiAgents' => $mailAiAgents,
            'mailAiDomainAgent' => $mailAiAgents->first(fn (AiAgent $agent): bool => in_array('email', $agent->default_domains ?? [], true)),
            'mailAiGlobalFallbackAgent' => $mailAiGlobalFallbackAgent,
            'mailAiDefaultAgent' => $mailAiDefaultAgent,
            'mailAiRuntimeAvailability' => $mailAiRuntimeAvailability,
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'poll_interval' => 'required|integer|min:1',
            'concurrency' => 'required|integer|min:1',
            'batch_size' => 'required|integer|min:1',
            'delete_on_success' => 'sometimes|boolean',
            'size_limit_mb' => 'required|integer|min:1',
            'retention_months' => 'required|integer|min:1',
            'provider_deletion_reconciliation_enabled' => 'sometimes|boolean',
            'pause_ingest' => 'sometimes|boolean',
            'attachment_max_count' => 'sometimes|required|integer|min:1|max:100',
            'attachment_max_size_mb' => 'sometimes|required|integer|min:1|max:1024',
            'attachment_allowed_mime_types' => 'sometimes|required|string|max:5000',
            'trusted_authserv_ids' => 'sometimes|nullable|string|max:5000',
            'trusted_receiving_hops' => 'sometimes|nullable|string|max:5000',
            'max_failures' => 'required|integer|min:1',
            self::MAIL_AI_DEFAULT_AGENT_FIELD => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('ai_agents', 'id')->where('is_active', true),
            ],
        ]);

        if (array_key_exists(self::MAIL_AI_DEFAULT_AGENT_FIELD, $data)) {
            $this->updateDefaultEmailAgent((int) ($data[self::MAIL_AI_DEFAULT_AGENT_FIELD] ?? 0));
            unset($data[self::MAIL_AI_DEFAULT_AGENT_FIELD]);
        }
        $data[self::LEGACY_MAIL_AI_WORKLOAD_SETTING] = '';

        if (array_key_exists('attachment_allowed_mime_types', $data)) {
            $data['attachment_allowed_mime_types'] = collect(
                preg_split('/[\s,;]+/', mb_strtolower($data['attachment_allowed_mime_types'])) ?: []
            )
                ->map(fn (string $mime): string => trim($mime))
                ->filter(fn (string $mime): bool => $mime !== '' && str_contains($mime, '/'))
                ->unique()
                ->values()
                ->implode("\n");
        }

        foreach (['trusted_authserv_ids', 'trusted_receiving_hops'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = $this->normalizeTrustedIdentifiers($data[$field], $field);
            }
        }

        $this->validateTrustedAuthenticationPair($data);

        foreach ($data as $key => $value) {
            CommonSetting::updateOrCreate(
                ['type' => 'emailhub', 'name' => $key],
                ['value' => (string) $value]
            );
        }

        // Handle booleans not present in request
        if (! $request->has('delete_on_success')) {
            CommonSetting::updateOrCreate(['type' => 'emailhub', 'name' => 'delete_on_success'], ['value' => '0']);
        }
        if (! $request->has('pause_ingest')) {
            CommonSetting::updateOrCreate(['type' => 'emailhub', 'name' => 'pause_ingest'], ['value' => '0']);
        }
        if (! $request->has('provider_deletion_reconciliation_enabled')) {
            CommonSetting::updateOrCreate(
                ['type' => 'emailhub', 'name' => 'provider_deletion_reconciliation_enabled'],
                ['value' => '0'],
            );
        }

        return redirect()->route('tech.admin.settings.email.config')
            ->with('status', 'Email configuration updated successfully.');
    }

    /**
     * @return \Illuminate\Support\Collection<int, AiAgent>
     */
    private function mailAiAgents(): \Illuminate\Support\Collection
    {
        return AiAgent::query()
            ->with('provider')
            ->where('is_active', true)
            ->whereHas('provider', fn ($query) => $query->where('status', 'active'))
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }

    private function updateDefaultEmailAgent(int $agentId): void
    {
        if ($agentId > 0) {
            $agentExists = $this->mailAiAgents()->contains(fn (AiAgent $agent): bool => (int) $agent->id === $agentId);

            if (! $agentExists) {
                throw ValidationException::withMessages([
                    self::MAIL_AI_DEFAULT_AGENT_FIELD => 'Select an active AI agent with an active provider.',
                ]);
            }
        }

        AiAgent::query()->get()->each(function (AiAgent $agent) use ($agentId): void {
            $domains = collect($agent->default_domains ?? [])
                ->reject(fn (mixed $domain): bool => $domain === 'email')
                ->values()
                ->all();

            if ($agentId > 0 && (int) $agent->id === $agentId) {
                $domains[] = 'email';
            }

            if (($agent->default_domains ?? []) !== $domains) {
                $agent->forceFill(['default_domains' => $domains])->save();
            }
        });
    }

    private function normalizeTrustedIdentifiers(mixed $value, string $field): string
    {
        $identifiers = collect(preg_split('/[\s,;]+/', mb_strtolower((string) $value)) ?: [])
            ->map(fn (string $identifier): string => rtrim(trim($identifier), '.'))
            ->filter()
            ->values();

        $invalid = $identifiers->first(
            fn (string $identifier): bool => strlen($identifier) > 253
                || ! preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/', $identifier),
        );

        if ($invalid !== null) {
            throw ValidationException::withMessages([
                $field => 'Trusted identifiers must be valid host or authserv names.',
            ]);
        }

        return $identifiers->unique()->implode("\n");
    }

    /**
     * Authentication-Results is usable only when both sides of the local trust boundary exist.
     *
     * Missing request fields retain their current value, so partial updates cannot accidentally
     * create or preserve an authserv-only configuration.
     *
     * @param  array<string, mixed>  $data
     */
    private function validateTrustedAuthenticationPair(array $data): void
    {
        $current = CommonSetting::query()
            ->where('type', 'emailhub')
            ->whereIn('name', ['trusted_authserv_ids', 'trusted_receiving_hops'])
            ->pluck('value', 'name')
            ->all();

        $authservIds = array_key_exists('trusted_authserv_ids', $data)
            ? trim((string) $data['trusted_authserv_ids'])
            : trim((string) ($current['trusted_authserv_ids'] ?? ''));
        $receivingHops = array_key_exists('trusted_receiving_hops', $data)
            ? trim((string) $data['trusted_receiving_hops'])
            : trim((string) ($current['trusted_receiving_hops'] ?? ''));

        if (($authservIds !== '') === ($receivingHops !== '')) {
            return;
        }

        $missingField = $authservIds !== ''
            ? 'trusted_receiving_hops'
            : 'trusted_authserv_ids';

        throw ValidationException::withMessages([
            $missingField => 'Trusted authserv IDs and trusted receiving hops must both be configured, or both left empty.',
        ]);
    }
}
