# Asset Management Module Documentation

## 1. User Manual (Brukermanual)

### Overview
Asset Management-modulen brukes til å registrere og spore teknisk utstyr (assets) tilknyttet klienter. Systemet støtter både manuelt opprettede enheter og fremtidig integrasjon mot RMM-systemer.

### Creating an Asset
1. Naviger til **Work > Assets** i hovedmenyen.
2. Klikk på **Create Asset**-knappen.
3. Velg en **Client**. Dette vil automatisk laste inn tilgjengelige **Sites** (lokasjoner) og **Users** (eiere) for den valgte klienten.
4. Fyll ut nødvendig informasjon:
   - **Name**: Et gjenkjennelig navn på enheten.
   - **Type**: Velg mellom Server, PC, Laptop, Switch, etc.
   - **Vendor**: Velg produsent fra systemets felles register.
   - **Network Info**: Angi IP-adresse og om den bruker DHCP eller Fast IP (Fixed).
5. Klikk **Create Asset** for å lagre.

### Viewing and Editing
- Fra oversiktslisten kan du filtrere på Klient, Type og Status.
- Klikk på navnet eller **View**-knappen for å se detaljer.
- Bruk **Edit**-knappen for å oppdatere informasjon om en eksisterende asset.

---

### API Integrasjon
Det er utviklet et API for å integrere med Assets fra eksterne systemer. API-et er versjonert (V1) og krever autentisering via Bearer Token.

#### Tilgjengelige endepunkter:
- `GET /api/v1/assets`: Henter en liste over alle assets (støtter paginering og filtrering på `client_id`).
- `GET /api/v1/assets/{id}`: Henter detaljert informasjon om en spesifikk asset.
- `GET /api/v1/clients/{client_id}/assets`: Henter alle assets tilhørende en spesifikk kunde.

Du kan generere API-nøkler og se fullstendig interaktiv dokumentasjon under **Integrations -> API Management**.

---

## 2. Technical Documentation (Teknisk Dokumentasjon)

### Architecture
Modulen er bygget med Laravel og Livewire for en dynamisk brukeropplevelse uten full side-refresh ved valg av avhengige data.

- **Controller**: `App\Http\Controllers\Tech\Work\Assets\AssetController`
- **Model**: `App\Models\Tech\Work\Assets\Asset`
- **Livewire Component**: `App\Livewire\Tech\Assets\AssetForm`
- **Views**: Plassert i `resources/views/tech/assets/`

### Database Schema
Tabellen `assets` inneholder følgende nøkkelfelt:
- `client_id`, `site_id`, `user_id`: Relasjoner til klient-strukturen.
- `vendor_id`: Relasjon til `vendors`-tabellen.
- `type`: Enum (server, pc, laptop, switch, ap, firewall, other).
- `ip_type`: Enum (dhcp, fixed).
- `source`: Indikerer om asset er opprettet manuelt eller via RMM (f.eks. 'manual', 'nable').
- `metadata`: JSON-felt for lagring av rådata fra integrasjoner.

### Relationships
- `Asset -> Client`: Tilhører en klient (BelongsTo).
- `Asset -> ClientSite`: Tilhører en spesifikk lokasjon (BelongsTo, optional).
- `Asset -> ClientUser`: Tilordnet en bruker/eier (BelongsTo, optional).
- `Asset -> Vendor`: Knyttet til en produsent (BelongsTo, optional).

---

## 3. Future Roadmap (Veien videre)

Følgende funksjonalitet er planlagt, men ikke implementert enda:

### RMM Sync & Matching
- **Automatisert Synkronisering**: Systemet støtter nå automatisert bakgrunns-synkronisering fra N-able RMM. Dette kan aktiveres i **Integrations -> N-able RMM Settings**.
  - **Auto Import**: Når aktivert, vil systemet periodisk hente nye assets og oppdatere eksisterende.
  - **Intelligent Oppdatering**: Assets matches basert på deres unike RMM ID. Hvis en enhet allerede eksisterer (f.eks. manuelt opprettet eller fra forrige sync), vil systemet oppdatere Navn, Site, IP-adresse og annen teknisk info i stedet for å lage duplikater.
  - **Målrettet Synkronisering**: Du kan starte synkronisering for hele klienten, eller for en spesifikk Site. Ved synkronisering av en Site vil systemet hente alle enheter for klienten fra RMM, men kun oppdatere de som tilhører den valgte siten.
- **N-able RMM Spesifikasjoner**:
    - Servere (`server` i RMM)
    - Arbeidsstasjoner (`workstation` eller `workstation_node` i RMM)
    - Mobil-enheter (`mobile_device` i RMM)
  - *Merk: Assets synkroniseres kun dersom deres RMM Site ID samsvarer med en lokal lokasjon (Site) som har tilsvarende RMM ID registrert.*
  - Følgende data hentes automatisk: Navn, Operativsystem, IP-adresse, MAC-adresse, Serienummer, Produsent og Modell.
- **Tactical RMM & UniFi/Omada**: Planlagt for fremtidige oppdateringer.
- Matching-logikk som kobler manuelt opprettede assets mot RMM-data basert på **Serial Number**, **MAC Address** eller **Hostname**.
- Oppdatering av **Status** (Online/Offline) i sanntid via RMM-signaler (Under utvikling).

### Ticket Linkage
- Mulighet til å knytte en eller flere assets direkte til en ticket.
- Vise historikk over alle tickets tilknyttet en spesifikk enhet i Asset Detail-visningen.

### Service Tracking
- Spore enheter som er inne til reparasjon (drop-in repair), inkludert status på serviceforløp.
- Historikk over komponentbytter og utført vedlikehold.

### Advanced Metadata
- Utvidelse av metadata-visningen til å presentere systemspesifikk info (CPU, RAM, Disk) i et strukturert format i stedet for rå JSON.
