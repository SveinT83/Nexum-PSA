<?php

namespace App\Console\Commands;

use App\Modules\Integration\Models\AiWorkloadProfile;
use App\Modules\Integration\Models\AiWorkloadTokenBinding;
use App\Modules\Integration\Services\IntegrationHub\CapabilityRegistry;
use App\Modules\UserManagement\Actions\EnsureSystemActor;
use Illuminate\Console\Command;

class IssueIntegrationHubServiceToken extends Command
{
    protected $signature = 'integration-hub:issue-service-token
        {workload : Approved coordinator workload slug}
        {--name=Nexum MCP service : Token name}
        {--days=30 : Token and binding lifetime, 1-30 days}
        {--network=* : Allowed IP/CIDR, repeatable}
        {--allow-any-network : Explicitly allow the token from any source network}
        {--rpm=30 : Workload requests per minute, 1-300}';

    protected $description = 'Issue a one-time-displayed scoped service token bound to an approved Integration Hub workload.';

    public function handle(CapabilityRegistry $registry, EnsureSystemActor $ensureSystemActor): int
    {
        $workload = AiWorkloadProfile::query()->where('slug', (string) $this->argument('workload'))->first();
        if (! $workload || ! $workload->supportsCoordinatorTokens() || ! $workload->is_approved || ! $workload->is_active || $workload->expires_at?->isPast()) {
            $this->error('The workload must be an active, approved, unexpired coordinator workload.');

            return self::FAILURE;
        }

        $knownReadAbilities = collect($registry->definitions())->pluck('required_ability')->unique();
        $workloadAbilities = collect($workload->abilities ?? [])->map('strval')->intersect($knownReadAbilities)->values();
        if ($workloadAbilities->isEmpty()) {
            $this->error('The workload has no Integration Hub read abilities.');

            return self::FAILURE;
        }

        $actor = $ensureSystemActor->handle(
            (string) config('integration-hub.service_actor_key'),
            'Nexum Integration Hub MCP',
            'integration-hub-mcp@system.invalid',
            [],
        );

        $networks = array_values(array_filter(array_map(fn ($network): string => trim((string) $network), (array) $this->option('network'))));
        if ($networks === [] && ! $this->option('allow-any-network')) {
            $this->error('At least one --network is required, or use --allow-any-network explicitly.');

            return self::FAILURE;
        }
        if ($networks !== [] && $this->option('allow-any-network')) {
            $this->error('--network and --allow-any-network cannot be combined.');

            return self::FAILURE;
        }
        if (collect($networks)->contains(fn (string $network): bool => ! $this->isValidNetwork($network))) {
            $this->error('One or more --network values are invalid.');

            return self::FAILURE;
        }

        $days = min(30, max(1, (int) $this->option('days')));
        $requestedExpiry = now()->addDays($days);
        $expiresAt = $workload->expires_at && $workload->expires_at->lessThan($requestedExpiry)
            ? $workload->expires_at->copy()
            : $requestedExpiry;
        if ($expiresAt->lessThanOrEqualTo(now()->addMinute())) {
            $this->error('The workload expires too soon to issue a service token.');

            return self::FAILURE;
        }
        $requestsPerMinute = min(300, max(1, (int) $this->option('rpm')));
        $token = $actor->createToken((string) $this->option('name'), ['integration-hub.service'], $expiresAt);
        AiWorkloadTokenBinding::query()->create([
            'personal_access_token_id' => $token->accessToken->id,
            'ai_workload_profile_id' => $workload->id,
            'expires_at' => $expiresAt,
            'allowed_networks' => $networks,
            'requests_per_minute' => $requestsPerMinute,
            'created_by' => $actor->id,
        ]);

        $this->line('Token record ID: '.$token->accessToken->id);
        $this->warn('Save this token now. It will not be shown again:');
        $this->line($token->plainTextToken);

        return self::SUCCESS;
    }

    private function isValidNetwork(string $network): bool
    {
        $parts = explode('/', $network, 2);
        $ip = $parts[0];
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if (count($parts) === 1) {
            return true;
        }
        if ($parts[1] === '' || ! ctype_digit($parts[1])) {
            return false;
        }

        $maximumPrefix = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 128 : 32;

        return (int) $parts[1] <= $maximumPrefix;
    }
}
