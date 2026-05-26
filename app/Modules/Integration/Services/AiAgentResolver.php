<?php

namespace App\Modules\Integration\Services;

use App\Models\Core\User;
use App\Modules\Integration\Models\AiAgent;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AiAgentResolver
{
    /**
     * Return active agents that the user's roles are allowed to use.
     */
    public function availableAgents(User $user): Collection
    {
        $roleIds = $user->roles()->pluck('roles.id')->all();

        return AiAgent::query()
            ->with(['provider', 'roles'])
            ->where('is_active', true)
            ->whereHas('provider', fn ($providerQuery) => $providerQuery->where('status', 'active'))
            ->where(function ($query) use ($roleIds) {
                $query->whereDoesntHave('roles');

                if ($roleIds !== []) {
                    $query->orWhereHas('roles', fn ($roleQuery) => $roleQuery->whereIn('roles.id', $roleIds));
                }
            })
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }

    /**
     * Pick a domain default for the user, then fall back to the global default.
     */
    public function defaultAgent(User $user, ?string $domain = null): ?AiAgent
    {
        $agents = $this->availableAgents($user);
        $domain = $domain ? Str::snake($domain) : null;

        if ($domain) {
            $domainAgent = $agents->first(fn (AiAgent $agent) => in_array($domain, $agent->default_domains ?? [], true));

            if ($domainAgent) {
                return $domainAgent;
            }
        }

        return $agents->firstWhere('is_default', true) ?? $agents->first();
    }

    public function domainOptions(): array
    {
        return [
            'tickets' => 'Tickets',
            'sales' => 'Sales',
            'clients' => 'Clients',
            'assets' => 'Assets',
            'knowledge' => 'Knowledge',
            'documentation' => 'Documentation',
            'email' => 'Email',
            'commercial' => 'Commercial',
            'risk' => 'Risk',
            'storage' => 'Storage',
            'tasks' => 'Tasks',
            'system' => 'System/Admin',
        ];
    }

    public function domainFromRoute(?string $routeName, ?string $path = null): ?string
    {
        $source = trim(($routeName ?? '').' '.($path ?? ''));

        foreach (array_keys($this->domainOptions()) as $domain) {
            if (Str::contains($source, [$domain.'.', '/'.$domain, $domain.'/'])) {
                return $domain;
            }
        }

        if (Str::contains($source, ['admin.system', '/admin/system'])) {
            return 'system';
        }

        if (Str::contains($source, ['cs.', '/cs/'])) {
            return 'commercial';
        }

        return null;
    }
}
