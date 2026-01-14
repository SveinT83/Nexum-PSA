<?php

namespace App\Livewire\Tech\Cs;

use App\Models\CS\Packages\Package;
use App\Models\CS\Services\Services;
use App\Models\CS\Terms\terms;
use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;

class PackageLegal extends Component
{
    public ?Package $package = null;
    public array $selectedTermIds = [];
    public array $currentServiceIds = [];
    public string $enabled = 'enabled';

    public function mount(?Package $package = null, string $enabled = 'enabled'): void
    {
        $this->package = $package;
        $this->enabled = $enabled;

        if ($this->package && $this->package->exists) {
            $this->selectedTermIds = $this->package->terms()
                ->pluck('terms.id')
                ->map(fn($id) => (string) $id)
                ->toArray();

            $this->currentServiceIds = $this->package->services()
                ->pluck('services.id')
                ->map(fn($id) => (string) $id)
                ->toArray();

            // Sørg for at alle terms fra aktive tjenester er inkludert når vi laster pakken
            $termsFromServices = terms::whereHas('services', function($query) {
                $query->whereIn('services.id', $this->currentServiceIds);
            })->pluck('id')->map(fn($id) => (string)$id)->toArray();

            foreach ($termsFromServices as $termId) {
                if (!in_array($termId, $this->selectedTermIds)) {
                    $this->selectedTermIds[] = $termId;
                }
            }
        }
    }

    #[On('servicesUpdated')]
    public function updateServices($serviceIds): void
    {
        $this->currentServiceIds = array_map('strval', $serviceIds);

        // Når tjenester oppdateres, ønsker vi kanskje å automatisk legge til betingelser fra disse tjenestene
        // hvis de ikke allerede er i listen? Brukeren sa "legge til og fjerne uavhengig",
        // så vi bør kanskje bare gi forslag eller la dem se hva som er inkludert.

        $newTermsFromServices = terms::whereHas('services', function($query) {
            $query->whereIn('services.id', $this->currentServiceIds);
        })->pluck('id')->map(fn($id) => (string)$id)->toArray();

        // Legg til nye betingelser fra tjenester som ikke allerede er valgt
        foreach ($newTermsFromServices as $termId) {
            if (!in_array($termId, $this->selectedTermIds)) {
                $this->selectedTermIds[] = $termId;
            }
        }
    }

    #[Computed]
    public function allTerms()
    {
        return terms::orderBy('name')->get();
    }

    #[Computed]
    public function selectedTerms()
    {
        return terms::whereIn('id', $this->selectedTermIds)
            ->get()
            ->sortBy(function($term) {
                return array_search((string)$term->id, $this->selectedTermIds);
            });
    }

    #[Computed]
    public function termsFromServices()
    {
        return terms::whereHas('services', function($query) {
            $query->whereIn('services.id', $this->currentServiceIds);
        })->pluck('id')->map(fn($id) => (string)$id)->toArray();
    }

    public function toggleTerm($termId): void
    {
        $termId = (string) $termId;
        if (in_array($termId, $this->selectedTermIds)) {
            $this->selectedTermIds = array_values(array_diff($this->selectedTermIds, [$termId]));
        } else {
            $this->selectedTermIds[] = $termId;
        }
    }

    public function removeTerm($termId): void
    {
        $this->selectedTermIds = array_values(array_diff($this->selectedTermIds, [(string) $termId]));
    }

    public function render()
    {
        return view('livewire.tech.cs.package-legal');
    }
}
