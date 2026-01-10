<?php

namespace App\Livewire\Tech\Cs;

use App\Models\CS\Packages\Package;
use App\Models\CS\Services\Services;
use Livewire\Component;
use Livewire\Attributes\Computed;

class ServicePicker extends Component
{
    public ?Package $package = null;
    public array $selectedServiceIds = [];
    public string $enabled = 'enabled';

    public function mount(?Package $package = null, string $enabled = 'enabled'): void
    {
        $this->package = $package;
        $this->enabled = $enabled;

        if ($this->package && $this->package->exists) {
            $this->selectedServiceIds = $this->package->services()->pluck('services.id')->map(fn($id) => (string) $id)->toArray();
        }
    }

    #[Computed]
    public function allServices()
    {
        return Services::orderBy('name')->get();
    }

    #[Computed]
    public function selectedServices()
    {
        return Services::whereIn('id', $this->selectedServiceIds)
            ->get()
            ->sortBy(function($service) {
                return array_search((string)$service->id, $this->selectedServiceIds);
            });
    }

    public function toggleService($serviceId): void
    {
        $serviceId = (string) $serviceId;
        if (in_array($serviceId, $this->selectedServiceIds)) {
            $this->selectedServiceIds = array_values(array_diff($this->selectedServiceIds, [$serviceId]));
        } else {
            $this->selectedServiceIds[] = $serviceId;
        }
        $this->dispatch('servicesUpdated', $this->selectedServiceIds);
    }

    public function removeService($serviceId): void
    {
        $this->selectedServiceIds = array_values(array_diff($this->selectedServiceIds, [(string) $serviceId]));
        $this->dispatch('servicesUpdated', $this->selectedServiceIds);
    }

    public function render()
    {
        return view('livewire.tech.cs.service-picker');
    }
}
