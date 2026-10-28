<?php

namespace App\Modules\Ticket\Support;

use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Tech\Work\Assets\Asset;
use App\Modules\Taxonomy\Models\Tag;
use Illuminate\Support\Str;

/** Shared Ticket tag normalization and browser-equivalent site selection. */
class TicketFormValues
{
    public function resolveTagIds(array $tagNames): array
    {
        return collect($tagNames)
            ->map(fn (?string $name) => trim((string) $name))
            ->filter()
            ->unique(fn (string $name) => Str::lower($name))
            ->map(function (string $name) {
                return Tag::query()->firstOrCreate(
                    ['slug' => Str::slug($name)],
                    [
                        'name' => $name,
                        'active' => true,
                    ],
                )->id;
            })
            ->values()
            ->all();
    }

    public function resolveSiteId(?int $clientId, ?int $siteId = null, ?int $contactId = null, ?int $assetId = null): ?int
    {
        if (! $clientId) {
            return null;
        }

        if ($contactId) {
            return ClientUser::whereKey($contactId)
                ->whereHas('site', fn ($query) => $query->where('client_id', $clientId))
                ->value('client_site_id');
        }

        if ($siteId) {
            return ClientSite::where('client_id', $clientId)->findOrFail($siteId)->id;
        }

        if ($assetId) {
            return Asset::where('client_id', $clientId)->findOrFail($assetId)->site_id;
        }

        $sites = ClientSite::where('client_id', $clientId)->get(['id', 'is_default']);

        if ($sites->count() === 1) {
            return $sites->first()->id;
        }

        return $sites->firstWhere('is_default', true)?->id;
    }
}
