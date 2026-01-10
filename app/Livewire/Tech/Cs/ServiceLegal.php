<?php

namespace App\Livewire\Tech\Cs;

use App\Models\CS\Services\Services;
use App\Models\CS\Terms\terms;
use Livewire\Component;
use Livewire\Attributes\Computed;

/**
 * ServiceLegal er en Livewire-komponent som håndterer valg og visning av juridiske betingelser (Terms)
 * knyttet til en tjeneste (Service). Den fungerer som en "picker" hvor man kan legge til
 * eller fjerne betingelser fra en liste.
 */
class ServiceLegal extends Component
{
    // Egenskapen $service holder på den nåværende tjenesten hvis den eksisterer.
    public ?Services $service = null;

    // En liste over ID-ene til de valgte betingelsene. Lagres som strenger for konsistens.
    public array $selectedTermIds = [];

    // En status-streng som kan brukes til å styre om komponenten er aktiv eller ikke (standard: 'enabled').
    public string $enabled = 'enabled';

    /**
     * mount-metoden kjøres når komponenten initialiseres for første gang.
     * Den tar inn en valgfri tjeneste og en status.
     */
    public function mount(?Services $service = null, string $enabled = 'enabled'): void
    {
        $this->service = $service;
        $this->enabled = $enabled;

        // Hvis en tjeneste er sendt med og den eksisterer i databasen,
        // henter we ut ID-ene til betingelsene som allerede er knyttet til denne tjenesten.
        if ($this->service && $this->service->exists) {
            // Vi antar at Services modellen har en relasjon 'terms' eller vi bruker pivot direkte
            // Basert på terms.php, så har terms en 'services' relasjon.
            // Vi sjekker om Services har en 'terms' relasjon, hvis ikke henter vi manuelt.
            if (method_exists($this->service, 'terms')) {
                 $this->selectedTermIds = $this->service->terms()
                    ->pluck('terms.id')
                    ->map(fn($id) => (string) $id)
                    ->toArray();
            }
        }
    }

    /**
     * Henter alle tilgjengelige betingelser fra databasen, sortert etter navn.
     * #[Computed] gjør at resultatet caches i samme forespørsel, slik at databasen ikke spørres unødig.
     */
    #[Computed]
    public function allTerms()
    {
        return terms::orderBy('name')->get();
    }

    /**
     * Henter de faktiske modell-objektene for de betingelsene som er valgt.
     * Resultatet sorteres i samme rekkefølge som ID-ene ligger i $selectedTermIds arrayen.
     */
    #[Computed]
    public function selectedTerms()
    {
        return terms::whereIn('id', $this->selectedTermIds)
            ->get()
            ->sortBy(function($term) {
                // Sørger for at rekkefølgen i visningen matcher rekkefølgen de ble valgt i.
                return array_search((string)$term->id, $this->selectedTermIds);
            });
    }

    /**
     * Legger til eller fjerner en betingelse fra listen over valgte ID-er.
     * Hvis den finnes fra før fjernes den, hvis ikke legges den til.
     */
    public function toggleTerm($termId): void
    {
        $termId = (string) $termId;
        if (in_array($termId, $this->selectedTermIds)) {
            // Hvis ID-en finnes, fjern den ved å filtrere arrayen.
            $this->selectedTermIds = array_values(array_diff($this->selectedTermIds, [$termId]));
        } else {
            // Hvis ID-en ikke finnes, legg den til i slutten av listen.
            $this->selectedTermIds[] = $termId;
        }
    }

    /**
     * Fjerner en spesifikk betingelse fra listen over valgte ID-er.
     */
    public function removeTerm($termId): void
    {
        $this->selectedTermIds = array_values(array_diff($this->selectedTermIds, [(string) $termId]));
    }

    /**
     * Definerer hvilken Blade-visning (view) som skal brukes for denne komponenten.
     */
    public function render()
    {
        return view('livewire.tech.cs.service-legal');
    }
}
