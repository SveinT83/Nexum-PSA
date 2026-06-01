# Dokumentasjon for Malbehandling (Documentation Templates)

Denne modulen håndterer administrasjon av dokumentasjonsmaler. Maler lagres med en dynamisk JSON-struktur for feltdefinisjoner.

## Komponenter

### Livewire-skjema
Vi bruker en Livewire-komponent for å håndtere det dynamiske skjemaet for oppretting og redigering av maler.

- **Klasse:** `App\Modules\Documentation\Livewire\Admin\TemplateForm`
- **Utsikt:** `app/Modules/Documentation/Views/Livewire/Admin/TemplateManagement/Doc/template-form.blade.php`

### Funksjonalitet i skjemaet:
- **Seksjoner (Rows):** Bruker `layout => rowStart` for å starte en ny rad/seksjon i dokumentasjonen.
- **Felt:** Støtter ulike felt-typer (text, textarea, checkbox, select, date).
- **Dynamisk:** Mulighet til å legge til, fjerne og flytte rader og felter opp/ned.
- **JSON-lagring:** Alle feltdefinisjoner lagres i `fields`-kolonnen i databasen som JSON.

## Bruk
Skjemaet nås via:
- **Opprett:** `/admin/system/templatesManagement/doc/create`
- **Rediger:** `/admin/system/templatesManagement/doc/edit/{id}`
