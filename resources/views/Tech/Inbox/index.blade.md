# tech.inbox.index — Inbox List View (Read/Triage)

**URL:** `tech/inbox`
**Route name:** `tech.inbox.index`
**Access level:** `inbox.view` (base); action-specific gates below
**Creation date:** 2025-11-03
**Controller path:** `App\Http\Controllers\Tech\Inbox\IndexController@index`
**Status:** In progress
**Difficulty:** Medium
**Estimated time:** 3.0 hours

---

## Purpose

Sanntids listevisning for unmappet/ambiguous e-post før ticket-opprettelse. Gir rask triage uten kontekstbytte. **Ingen svarfunksjon i Inbox.**

---

## Design & Layout (Bootstrap, no HTML)

Bruk standard 3-soners skall.

* **Top (header):** Tittel, sorteringsvalg, søk, resultatteller, (RBAC) bulk actions.
* **Left (sidebar/collapsible):** Filtre (konto, state, labels, dato, vedlegg, client/site-hint).
* **Main (center):** Scrollbar liste (virtualized) med rader som kan selekteres.
* **Right (narrow):** **PreviewPane** med hurtighandlinger (ingen svar), threading-snippets.

**Ikoner (forslag):** `inbox`, `filter`, `sort`, `search`, `link`, `plus`, `tag`, `archive`, `trash`, `shield-alert`.

---

## Components — Blade vs Livewire

* **Blade**: HeaderBar (sort/search chrome), BulkToolbar, tomtilstander.
* **Livewire (kun der nødvendig):**

  * `InboxFeedLW` – liste + infinite scroll + sanntidsinnstikk.
  * `InboxFiltersLW` – filtrerbar tilstand, emiterer `filtersUpdated`.
  * `MessagePreviewLW` – laster body/attachments on-demand; viser triageknapper.
  * `TriageActionsLW` – Create Ticket, Link, Labels, Archive/Delete (optimistisk UI).
  * `AlertBellLW` – lytter på `inbox.received` / `inbox.updated` og viser badge/toast.

---

## Sorting (Top)

* Felter: `received_at` (default), `from`, `subject`, `size`, `state`, optional `priority_hint`.
* Retning: ASC/DESC (sekundær tie-breaker: `received_at` desc).
* Søk: substring over `from`, `subject`, `snippet`.

---

## Filtering (Sidebar)

* **Accounts** (multi)
* **State**: `new`, `untriaged`, `awaiting-link`, `linked`, `archived` (RBAC styrer synlighet)
* **Labels/Tags** (multi)
* **Date range**: Today / 24h / 7d + custom
* **Attachment**: has / has-not
* **Client/Site hint**: valgbar når parser har kandidater

`InboxFiltersLW` emiterer `filtersUpdated` som `InboxFeedLW` konsumerer.

---

## List (Main)

* Radfelt: From (navn/adresse), Subject, Snippet, `received_at` (relative), State-badge, Labels, Account.
* Selektering: Single-select for preview; nye elementer markeres subtilt.
* Infinite scroll: next page ved bunn; duplikat-sikring.
* Empty states: hjelpetekst + lenke til e-postinnstillinger ved 0-konto.

**Tastatur:** Up/Down = flytt markør, `/` = fokus søk, `Enter` = åpne **Show** (full visning),
`C` = Create Ticket, `K` = Link to Ticket, `R` = Create Rule, `L` = Label, `A` = Archive, `D` = Delete (admin).

---

## Preview (Right panel)

* Header: From, Subject, Dato, State/Labels.
* Body: sanitisert HTML-preview (lazy-load), Attachments-liste.
* Threading: relaterte meldinger via `message_id/references` (collapse/expand).
* **Handlinger (ingen reply):** Create Ticket, Link, Create Rule, Label, Archive/Delete.

`Enter` åpner alltid **Show**: `tech/inbox/show:{messageId}`.

---

## Actions & Permissions (RBAC)

* View: `inbox.view`
* Create Ticket: `ticket.create`
* Link to Ticket: `ticket.edit`
* Create Rule: `email.rules.manage`
* Label/Archive (soft): `inbox.manage`
* Delete (hard): `email.admin` (confirm dialog)

> Skjul handlinger brukeren ikke har rettighet til.

---

## Real-time & Background Alerts

* **Bakgrunnsagent** emitter `inbox.received`, `inbox.updated` (Broadcast/Redis).
* **AlertBellLW** viser toast + badge; **InboxFeedLW** gjør soft-prepend/sort-korrekt innstikk.
* Fallback polling: 15–30s når socket er nede (banner: reconnecting…).

---

## Performance & Safety

* Virtualisering ved >200 rader, side 50; prefetch neste side.
* Lazy-load av body/attachments i preview.
* Aggressiv HTML-sanitizer; fjern ekstern lasting (proxied inline-bilder).
* Optimistisk UI med rollback ved feil; idempotente API-kall.

---

## Acceptance Criteria

* Nye e-poster synlig ≤ 2s via socket; ≤ 30s på fallback.
* Sortering/filtrering konsistent mellom liste og preview.
* `Enter` åpner korrekt Show-side; triageknapper oppdaterer state uten full reload.
* Create Rule åpner regel-editor med korrekt prefill (from/domain/subject) eller redirect med query.
* RBAC skjuler uautoriserte knapper; alle triage-aksjoner audites.

---

## Notes for Copilot

* Én kilde for sort/filter (LW store); `filtersUpdated` / `sortUpdated` events.
* `selectMessage(id)` oppdaterer preview og fokus.
* Høy trafikk: batch-indsett, debounce UI, backpressure ved socket-bursts.
* Ingen «reply»/«forward» i noen komponent i Inbox.
