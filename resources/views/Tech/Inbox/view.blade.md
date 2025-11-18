# tech.inbox.view — Full Email View (Read/Triage)

**URL:** `tech/inbox/show:{messageId}`
**Access level:** `inbox.view` (base); action-specific gates below
**Creation date:** 2025-11-03
**Controller path:** `App\Http\Controllers\Tech\Inbox\ShowController@show`
**Status:** In progress
**Difficulty:** Medium
**Estimated time:** 2.5 hours

---

## Purpose

Les hele e-posten før ticket-opprettelse, og utfør **kun triage** (ingen svar). Hurtigknapper for å **opprette ticket**, **linke til eksisterende**, **lage regel**, **sette labels**, og **arkivere/slette**.

---

## Design & Layout (Bootstrap, no HTML)

**Top (header):** Fra/til, emne, mottatt-dato, konto, state/labels, «Back to Inbox», Prev/Next.
**Main (center):** Full HTML-render (sanitisert) + attachments + thread-snippets.
**Right panel (narrow):** TriagePanel med primærhandlinger.

**Suggested icons:** `arrow-left`, `inbox`, `file-text`, `paperclip`, `link`, `plus`, `tag`, `archive`, `trash`, `shield-alert`.

---

## Components (Blade vs Livewire)

* **Blade**

  * HeaderBar (metadata, navigasjon)
  * MessageBody (sanitisert HTML, inline-bilder via proxy)
  * AttachmentList (nedlasting/åpne i ny fane)
  * ThreadTimeline (kompakt, collapsible)
* **Livewire (kun der nødvendig)**

  * TriageActionsLW (Create Ticket, Link, Labels, Archive/Delete)
  * RuleQuickCreateLW (åpner Rule Editor med preutfylte felt, eller redirect m/ query)

---

## Actions & Permissions (RBAC)

* **Create Ticket** → `ticket.create`
* **Link to Ticket** → `ticket.edit`
* **Create Rule** (global) → `email.rules.manage`
* **Label/Unlabel** → `inbox.manage` (alternativt `email.admin`)
* **Archive (soft)** → `inbox.manage`
* **Delete (hard)** → `email.admin` (krever confirm)

> **Ingen svarfunksjon i Inbox View.** For å svare må e-posten bli en ticket.

---

## Data shown

* Fra (display + adresse), Til/Cc, Emne, Mottatt (`received_at`), Størrelse, Konto
* State (`new|untriaged|awaiting-link|linked|archived`), Labels[]
* Parser hints: client/site/asset-kandidater (vises som chips med «Apply» → prefill i Create Ticket)
* Headers (toggle): Message-ID, In-Reply-To, References

---

## TriagePanel — detalj

* **Primary CTA:** Create Ticket (queue/category/priority, prefill fra hints)
* **Secondary:** Link to existing (ID/tittel-søk modal)
* **Rule:** Create Rule (prefill: from/domain/subject; Continue/Stop flag; scope=account/all)
* **Labels:** typeahead + hurtigchips
* **Archive/Delete:** bekreftelsesdialog; vis retention-policy tekst ved Archive
* **Audit snippet:** «Triaged by X at 12:34» (lesevisning)

---

## Keyboard (scoped til view)

* `B` = Back to Inbox
* `N`/`P` = Next/Prev
* `C` = Create Ticket
* `K` = Link
* `R` = Create Rule
* `L` = Label
* `A` = Archive
* `D` = Delete (admin)

---

## Real-time hints (background agent)

* AlertBell viser ny aktivitet (f.eks. «Rules updated: this message now matches rule X»).
* Soft-banner i toppen når tilstanden til meldingen endres av andre (optimistisk oppdatering → re-fetch minimal payload).

---

## Error & Safety

* HTML-sanitizer, blokker eksterne ressurser (last via proxy).
* Store body lazy-load, attachments lazy-lookup.
* Optimistic UI, rollback ved 4xx/5xx.
* Alle handlinger audites (who/what/when).

---

## Acceptance Criteria

* Lasting av full body < 300ms etter initial fetch (cache/lazy).
* Triage-handlinger oppdaterer state uten full reload.
* Regel-knapp åpner editor med korrekt prefill.
* Ingen «Reply»/«Forward» er synlige i UI.
* RBAC skjuler uautoriserte knapper.

---

## Notes

* «Archive» = soft-delete med gjenoppretting innen retention-vindu (30–90 dager, tenant-setting).
* «Delete» = hard delete (admin), auditert med regelmessig begrunnelse.
* Delte Labels: vurder samsvar med Ticket-tags for enkel filtrering i etterkant.
