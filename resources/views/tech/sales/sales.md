# Sales System – Gennerell dokumentasjon (inkl. ordre-flyt)

**Dato:** 2025-10-26  
 **Status:** In progress  
 **Vanskelighetsgrad:** Medium  
 **Estimert tid:** 8 timer  
 **Tilgangsnivå:** `sales.admin`, `lead.view`, `lead.create`  
 **Controller path:** `App\\Http\\Controllers\\Tech\\Sales\\*`

---

## 1. Formål og ide

Sales-systemet håndterer all økonomisk aktivitet knyttet til salg, kontrakter og fysiske produkter i tdPSA.  
 Det fungerer som et forretnings- og økonomisk oversiktslag som samler all informasjon om pågående salg, tilbud, kontrakter og ordre i ett sted.

Kjernen i systemet er at **alle prosesser er tickets**. Køen bestemmer hva saken brukes til – salg, ordre, support, implementering osv.  
 Dette gir fleksibilitet og sporbarhet uten å duplisere logikk.

---

## 2. Grunnkonsept

### 2.1 Ticket som base

- Alt arbeid og alle prosesser i tdPSA er **tickets**.
- Køen (`queue`) definerer funksjon: `Sales`, `Orders`, `Support`, `Onboarding`, `Billing` osv.
- Ingen egne ticket-typer; én felles entitet `tickets` med fleksible relasjoner.

### 2.2 Ordre (varer)

- En **ordre** er en ticket som inneholder **produkter** (`order_items`).
- Produkter legges til direkte i saken, uansett kø.
- Når produkter legges til, settes `has_products = true`.
- Disse ordrelinjene vises i et eget panel i ticket-visningen, og håndteres via workflow.

### 2.3 Kontrakter og tjenester

- Kontrakter håndteres i kontraktssystemet og kan kobles til tickets.
- En kontrakt kan være et resultat av en salgsprosess eller eksistere separat.
- Ved aksept av kontrakt:
  - kontrakten aktiveres,
  - ticketen konverteres til Onboarding (eller ny Onboarding-ticket opprettes).

### 2.4 Sales-visningen

`tech.sales.index` viser alle tickets som har økonomisk relevans:

- Køer: `Sales`, `Orders`, `Onboarding`.
- Tickets i andre køer (f.eks. `Support`) som har produkter (`order_items`) eller kontrakter.
- Hver ticket er én rad i listen.

---

## 3. Datamodell

### 3.1 Tickets

| Felt                    | Beskrivelse                                                   |
|-------------------------|---------------------------------------------------------------|
| id                      | Primærnøkkel                                                  |
| queue_id                | Angir funksjon (Sales, Orders, Support, Onboarding osv.)      |
| client_id / site_id     | Kunde og lokasjon                                             |
| owner_id                | Ansvarlig bruker                                              |
| status                  | Workflowstatus (New, Negotiation, Won, Lost, Delivered, etc.) |
| value_estimate          | Estimert salgsverdi                                           |
| value_committed         | Faktisk verdi når kontrakt/ordre er klar                      |
| has_contract            | Boolean (true hvis kontrakt koblet)                           |
| has_products            | Boolean (true hvis ordrelinjer finnes)                        |
| last_activity_at        | Sist endret                                                   |
| created_at / updated_at | Tidsstempler                                                  |

### 3.2 Order_items

| Felt                    | Beskrivelse                                   |
|-------------------------|-----------------------------------------------|
| id                      | Primærnøkkel                                  |
| ticket_id               | Kobling til ticket                            |
| product_id              | Produkt fra katalogen                         |
| description             | Beskrivelse                                   |
| qty                     | Antall                                        |
| unit_price              | Pris per enhet                                |
| discount                | Rabatt i % eller beløp                        |
| warehouse_id            | Lager referanse                               |
| delivery_status         | Bestilt / Plukket / Sendt / Levert / Fullført |
| created_at / updated_at | Tidsstempler                                  |

### 3.3 Contracts

| Felt                  | Beskrivelse                                |
|-----------------------|--------------------------------------------|
| id                    | Primærnøkkel                               |
| ticket_id             | Kobling til salgs- eller onboarding-ticket |
| client_id             | Kunde                                      |
| total_value           | Kontraktsverdi                             |
| start_date / end_date | Kontraktsperiode                           |
| status                | Draft / Active / Terminated / Archived     |

---

## 4. Sales-visning (sales.index)

### 4.1 Innhold

- Viser alle tickets med økonomisk relevans.
- Kolonner: Klient • Kø • Status • Eier • Sist aktivitet • Neste handling / Callback • Verdi.
- Hurtigfiltre: Leads / Kunder / Mine prosesser / Callback forfalt / Vunnet / Tapt / Alle.
- Sortering: Sist aktivitet (default) / Kunde / Eier / Verdi / Kø.

### 4.2 Handlinger

- **Add Process:** legg til klient i oversikten uten å opprette ticket umiddelbart.
- **Add Contract:** åpner kontraktseditor med valgt klient.
- **Add Product:** legger til produkt i eksisterende ticket (gjør saken til ordre).
- **View Client:** åpner klientvisningen for detaljer som bransje, kilde osv.
- **Open Ticket:** åpner saken for kommunikasjon og oppfølging.

### 4.3 Faner

- **Leads:** kunder uten kontrakt.
- **Kunder:** eksisterende kontrakter.
- **Mine prosesser:** saker jeg eier.
- **Callback forfalt:** tapt salg som er klart for oppfølging.
- **Vunnet / Tapt / Alle:** rask filtrering etter resultat.

---

## 4b. View Spec — `tech.sales.index`

**URL:** `/tech/sales`  
 **Access & permissions:** `lead.view` (read), `lead.create` (New Order button), `sales.admin` (settings).  
 **Creation date:** 2025-10-28  
 **Controller:** `App\Http\Controllers\Tech\Sales\OrdersController@index`  
 **Status:** Not started  
 **Difficulty:** Medium  
 **Estimated time:** 3.0 hours  
 **Layout:** Top header / Main / Right slim rail (Bootstrap)

**Purpose:** Liste/operere **sales orders** (Quotes → Approved → Fulfillment → Invoiced → Archived). *Leads håndteres i* `tech.sales.leads.*`*.*

**Tabs (static):** Quotes (Draft/Sent), Approved, Fulfillment, Invoiced, Archived  
 **Saved filter:** *Mine only* (per bruker, local storage)  
 **Routing:** Radklikk åpner `tech.sales.show:{ticketId}`

**Filters & Search:** fritekst (Order #, Title, Customer), kunde, eier, dato (created/updated), verdiintervall, status (i All).  
 **List columns:** Order # · Customer · Title · Status · Value · Assigned To · Updated  
 **Row actions:** View, Edit, Assign, Send Quote, Approve, Move to Fulfillment, Mark as Invoiced, Archive, Delete (enkeltvis)  
 **Status tokens:** Quotes (Draft/Sent), Approved, Fulfillment, Invoiced, Archived  
 **Reusable components:** TabsWithCounts, SavedFiltersToggle, SearchBar, StatusBadge, RowActionsMenu, AutoRefreshChip  
 **Empty/Error:** tom visning m/ *New Order*; filtrert tom viser aktive filtre; feil → inline alert + retry  
 **Perf/UX:** server‑side sort (default `updated_at desc`), counts via aggregater, paginering (page controls), husk siste fane/filtre, real‑time klar

---

## 5. Workflower

Workflower

### 5.1 Sales

`New → Contacted → Negotiation → Won / Lost`

### 5.2 Onboarding

`New → Planning → Implementing → Completed`

### 5.3 Orders

`Bestilt → Under behandling → Sendt → Levert → Fullført`

Workflowene definerer status, automatiske handlinger og triggere som endrer systemets oppførsel.

---

## 6. Ordre-flyt

1. **Opprettelse:**
   - Produkter legges til i en eksisterende ticket, eller en ny ticket i kø `Orders` opprettes.
   - Lager sjekkes for tilgjengelighet (via Inventory-modulen når den er implementert).
2. **Reservasjon:**
   - Lagerreservering skjer ved opprettelse eller når status settes til “Under behandling”.
   - Hvis varen ikke finnes, opprettes automatisk innkjøpsordre (senere modul).
3. **Behandling:**
   - Ansvarlig oppdaterer linjer (antall, pris, leveringsinfo).
   - Kunde informeres via ticketens meldingssystem.
4. **Forsendelse:**
   - Når produkter sendes → `delivery_status = Sent`.
   - `billing_items`-linjer opprettes for produktsalg (per parti som sendes eller konsolidert, iht. settings/workflow).
5. **Levert:**
   - Når alle varer er levert → `delivery_status = Delivered`.
   - Workflow kan låse fakturagrunnlag og sette status til *Fullført*.
6. **Fullført:**
   - Ordre avsluttes, `billing_items` merkes klare for fakturering.

---

## 6b. View Spec — `tech.sales.show:{ticketId}` (Order Ticket)

**Creation date:** 2025-10-28  
 **URL:** `/tech/sales/{ticketId}`  
 **Access:** `sales.view`, `ticket.view`, `sales.manage` (edit/order-line changes), `ticket.reply`, `ticket.internal.note`  
 **Controller:** `App\Http\Controllers\Tech\Sales\ShowController@show`  
 **Livewire/Components:** `App\Livewire\Tech\Sales\Show\*`  
 **Status:** In progress  
 **Difficulty:** Medium–High  
 **Estimated time:** 8.0 hours

**Scope-regel:** Denne visningen skal gjelde **alle tickets som har ordrelinjer (**`order_items` **> 0)** – **uavhengig av kø** (f.eks. en ticket i `Windows`‑kø med varer skal åpnes her).

### Purpose

En dedikert “ordre‑ticket” visning som kombinerer **sales order‑håndtering** (quote → pick → ship → invoice draft) med **ticket‑kommunikasjon** (kundesvar, interne notater, regler/workflow). Ticketen er en ordinær ticket med ordrelinjer; kø kan fortsatt være hvilken som helst.

### Layout (Bootstrap)

- **Top header** (breadcrumbs, tittel, primærhandlinger)
- **Main** (ordrelinjer, meldinger, aktivitet)
- **Right slim rail** (Quote, Shipments, Order Details, Billing, Assignment)

### Reusable & icons

Komponenter: headerbar, tabs (Order/Messages/Activity), kort (Details/Quote/Shipments/Billing/SLA/Workflow/Assignee), datatabell (ordrelinjer m/ inline validering), modaler (send/partial, shipping, bekreftelser), toasts/audit.  
 Ikoner (forslag): shopping-bag, file-text, send, truck, package, barcode, receipt, clock, user, workflow, lock, refresh-ccw, shield, bell

### Core defaults

- **Workflow‑drevet.** Fallbacks i Settings når workflow mangler.
- **Defaults:** Quote‑utløp 7 dager; auto‑reminders; fakturagrunnlag per del‑forsendelse (default); PDF‑maler fra `admin.templates`.
- **Livssyklus:** Draft → Quote Sent → (Reminders) → Quote Accepted/Rejected/Expired → Pick → Ready → Sent → Delivered → Closed.
- **Lagerpolicy:** reserver ved quote, oppgrader ved accept, trekk ved pick, marker dispatch ved send; slipp ved reject/expiry.
- **Universelle oppgaver:** på Accept → opprett “Pick items” med underoppgaver “Pack items” og “Deliver to carrier”.

### Header (actions)

- **Send / Partial Send** (dropdown) → shipping‑modal
- **Send Quote** (PDF + portal link) / Resend / Reminder
- **Mark Quote Accepted / Rejected** (manuell)
- **Close** (hvis levert & låst)

### Main A — Order Lines

Kolonner: SKU, Description, Qty, Unit price, Discount, Tax code, Line total, Stock status.  
 Regler: fri redigering før “Quote Sent”; etter sending → endringer lager **ny quote‑versjon** (eller workflow‑unntak); lås mengder i sendte/ pakkede forsendelser.  
 Totals: subtotal, mva, total, reserverte/tilgjengelige indikatorer.  
 Handlinger: Add/Remove/Discount/Recalculate.

### Main B — Messages

Kunde/intern‑toggle, vedlegg, maler/snippets, CC/BCC, avsenderkonto (Sales‑default eller global fallback), full e‑posttråding. Utsendelse av tilbud inkluderer PDF + portal‑lenke.

### Main C — Activity

System‑/brukerhendelser: quote sendt/påminnet/akseptert, versjon opprettet, tasks, shipments, invoice‑drafts, workflow‑trinn.

### Right rail — Cards

**Quote** (status, utløp, reminders, send/accept/reject, mal/preview).  
 **Shipments** (liste, +Shipment, carrier/service/tracking/ETA/status).  
 **Order Details** (queue, priority, tags, timestamps, assignee, workflow).  
 **Billing** (mode, drafts, mva, “Open in Billing”).  
 **Customer** (Client/Site/Contact, portal‑lenker).

### Rules/Workflow

Ticket Rules kan reagere på innkommende svar (aksept‑nøkkelord, etc.) og sette statuser/kjøre workflow. Workflow JSON styrer tasks, lageroverganger, auto‑status og billing‑triggere. Fallbacks i Settings (ingen workflow): Order Confirmed → Ready → Sent → Delivered → Closed.

### Validering & Låsing

Hindre redigering på sendte mengder og **Locked** invoice‑drafts. Hindre sending hvis ingen reserverbar beholdning (med rollebetingede unntak).

### Audit

Alle handlinger logges (edits, sends, reminders, status, reservasjoner, plukk, sendinger, billing).

---

## 7. Integrasjon med Billing

Integrasjon med Billing

- Hver ticket med `order_items` eller `contracts` genererer fakturagrunnlag.
- `billing_items` skrives når produkter sendes eller kontrakter aktiveres.
- Billing-modulen aggregerer disse og lager fakturautkast.
- Ingen faktura genereres direkte fra Sales; Sales er kun kildesystem.

---

## 8. Oppsummering

Sales-modulen samler all økonomisk aktivitet i systemet:

- Alt er tickets – køen avgjør funksjon.
- Produkter legges direkte i saker og gjør dem til ordre.
- Kontrakter knyttes til tickets for tjenestesalg.
- Sales-visningen gir ett sted for å følge alle salg, kontrakter og leveranser.
- Workflowene styrer fremdrift og automatiske handlinger.
- Billing henter grunnlag fra tickets når produkter eller kontrakter er levert/aktivert.

Dette gir et helhetlig og fleksibelt salgs- og leveransesystem med sporbarhet og økonomisk oversikt.