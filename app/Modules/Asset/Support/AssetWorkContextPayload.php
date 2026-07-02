<?php

namespace App\Modules\Asset\Support;

use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Tech\Work\Assets\Asset;
use App\Modules\WorkContext\Actions\ResolveWorkContext;
use Illuminate\Validation\ValidationException;

class AssetWorkContextPayload
{
    public function __construct(private readonly ResolveWorkContext $workContexts)
    {
    }

    /**
     * Normalize Asset ownership fields and assign the matching Work Context.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function normalize(array $data, ?Asset $asset = null, bool $creating = false): array
    {
        $clientId = $this->selectedClientId($data, $asset, $creating);

        if (! $clientId) {
            if (! empty($data['site_id'])) {
                throw ValidationException::withMessages([
                    'site_id' => 'A Site can only be selected when the Asset belongs to a Client.',
                ]);
            }

            if (! empty($data['user_id'])) {
                throw ValidationException::withMessages([
                    'user_id' => 'An owner can only be selected when the Asset belongs to a Client.',
                ]);
            }

            $data['client_id'] = null;
            $data['site_id'] = null;
            $data['user_id'] = null;
            $data['work_context_id'] = $this->workContexts->internal()->id;

            return $data;
        }

        $data['client_id'] = $clientId;
        $data['work_context_id'] = $this->workContexts->fromClientId($clientId)->id;

        $clientChanged = array_key_exists('client_id', $data)
            && $asset
            && (int) $asset->client_id !== (int) $clientId;

        if ($clientChanged) {
            $data['site_id'] = $data['site_id'] ?? null;
            $data['user_id'] = $data['user_id'] ?? null;
        }

        $this->ensureSiteBelongsToClient($data, $clientId);
        $this->ensureUserBelongsToClient($data, $clientId);

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function selectedClientId(array $data, ?Asset $asset, bool $creating): ?int
    {
        if (array_key_exists('client_id', $data)) {
            return filled($data['client_id']) ? (int) $data['client_id'] : null;
        }

        if ($creating) {
            return null;
        }

        return $asset?->client_id ? (int) $asset->client_id : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function ensureSiteBelongsToClient(array $data, int $clientId): void
    {
        if (empty($data['site_id'])) {
            return;
        }

        $siteBelongsToClient = ClientSite::query()
            ->whereKey($data['site_id'])
            ->where('client_id', $clientId)
            ->exists();

        if (! $siteBelongsToClient) {
            throw ValidationException::withMessages([
                'site_id' => 'The selected site does not belong to the selected client.',
            ]);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function ensureUserBelongsToClient(array $data, int $clientId): void
    {
        if (empty($data['user_id'])) {
            return;
        }

        $userBelongsToClient = ClientUser::query()
            ->whereKey($data['user_id'])
            ->whereHas('site', fn ($site) => $site->where('client_id', $clientId))
            ->exists();

        if (! $userBelongsToClient) {
            throw ValidationException::withMessages([
                'user_id' => 'The selected owner does not belong to the selected client.',
            ]);
        }
    }
}
