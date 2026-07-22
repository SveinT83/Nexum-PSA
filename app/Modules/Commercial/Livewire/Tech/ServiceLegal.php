<?php

namespace App\Modules\Commercial\Livewire\Tech;

use App\Modules\Commercial\Models\Services\Services;
use App\Modules\Commercial\Models\Terms\terms;
use Livewire\Attributes\Computed;
use Livewire\Component;

class ServiceLegal extends Component
{
    public ?Services $service = null;

    public array $selectedTermIds = [];

    public string $enabled = 'enabled';

    public function mount(?Services $service = null, string $enabled = 'enabled'): void
    {
        $this->service = $service;
        $this->enabled = $enabled;

        if ($this->service?->exists) {
            $this->selectedTermIds = $this->service->serviceTerms()
                ->pluck('terms.id')
                ->map(fn ($id) => (string) $id)
                ->all();
        }
    }

    #[Computed]
    public function allTerms()
    {
        return terms::query()
            ->where('origin', 'nexum')
            ->where('managed_externally', false)
            ->with('currentVersion')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function selectedTerms()
    {
        return terms::query()
            ->whereIn('id', $this->selectedTermIds)
            ->with('currentVersion')
            ->get()
            ->sortBy(fn ($term) => array_search((string) $term->id, $this->selectedTermIds, true));
    }

    #[Computed]
    public function providerTerms()
    {
        return $this->selectedTerms
            ->filter(fn (terms $term): bool => $term->isProviderManaged());
    }

    #[Computed]
    public function selectedNexumTerms()
    {
        return $this->selectedTerms
            ->reject(fn (terms $term): bool => $term->isProviderManaged());
    }

    public function toggleTerm($termId): void
    {
        if ($this->enabled === 'disabled') {
            return;
        }

        $term = terms::query()->findOrFail($termId);
        if ($term->isProviderManaged()) {
            return;
        }

        $termId = (string) $term->id;
        $this->selectedTermIds = in_array($termId, $this->selectedTermIds, true)
            ? array_values(array_diff($this->selectedTermIds, [$termId]))
            : [...$this->selectedTermIds, $termId];

        $this->syncServiceTerms();
    }

    public function removeTerm($termId): void
    {
        if ($this->enabled === 'disabled') {
            return;
        }

        $term = terms::query()->findOrFail($termId);
        if ($term->isProviderManaged()) {
            return;
        }

        $this->selectedTermIds = array_values(array_diff($this->selectedTermIds, [(string) $term->id]));
        $this->syncServiceTerms();
    }

    private function syncServiceTerms(): void
    {
        if (! $this->service?->exists) {
            return;
        }

        $providerIds = $this->service->serviceTerms()
            ->where(fn ($query) => $query
                ->where('origin', 'provider')
                ->orWhere('managed_externally', true))
            ->pluck('terms.id')
            ->map(fn ($id) => (string) $id);

        $this->selectedTermIds = collect($this->selectedTermIds)
            ->merge($providerIds)
            ->unique()
            ->values()
            ->all();

        $this->service->serviceTerms()->sync($this->selectedTermIds);
    }

    public function render()
    {
        return view('commercial::Livewire.Tech.service-legal');
    }
}