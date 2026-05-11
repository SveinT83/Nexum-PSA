# Email Module – General View Documentation

**Namespace:** `tech.admin.settings.email.*`  
 **Views:**

- `index` – hovedmeny/oversikt
- `accounts.index` / `accounts.create`
- `config.index`
- `rules.index` / `rules.create`
- `parser.index` (senere)
- `logs.index` / `inbox.index`

**Access:**

- `email.admin`, `email.accounts.manage`, `email.settings.manage`, `email.rules.manage`  
   **Created:** 2025-10-23  
   **Controller Path:** `App\\Http\\Controllers\\Tech\\Admin\\Settings\\Email\\*Controller`  
   **Status:** In progress  
   **Difficulty:** Medium  
   **Estimated time:** 6 hours

---

## 1) Purpose

Dette settet av visninger gir administratorer full kontroll over e-postsystemet i tdPSA — inkludert kontoer (IMAP/SMTP), globale regler, konfigurasjon, parserprofiler, logg/helse og fallback-inbox.  
 Alle visninger følger samme layoutmal: **Header / Main / Right slim sidebar**, og bruker standard PSA-komponenter for skjemaer, tabeller og modaler.

---

## 2) Structure Overview

| View                           | Formål                                                              | Controller                 | Viktige tillatelser   |
|--------------------------------|---------------------------------------------------------------------|----------------------------|-----------------------|
| `email.index`                    | Inngangsside / oversikt, viser lenker og status for alle delområder | `EmailController@index`      | `email.admin`           |
| `email.accounts.index`           | Liste over alle IMAP/SMTP-kontoer                                   | `AccountsController@index`   | `email.accounts.manage` |
| `email.accounts.create`          | Opprett/rediger e-postkonto                                         | \`AccountsController@create | edit\`                 |
| `email.config.index`             | Global systemkonfigurasjon (polling, sikkerhet, retention)          | `ConfigController@index`     | `email.settings.manage` |
| `email.rules.index`              | Liste og administrasjon av e-postregler                             | `RulesController@index`      | `email.rules.manage`    |
| `email.rules.create`             | Opprett/rediger regel                                               | \`RulesController@create    | edit\`                 |
| `email.parser.index` *(fremtidig)* | Parserprofiler (regex/mapping/AI)                                   | `ParserController@index`     | `email.admin`           |
| `email.logs.index`               | Logg og systemhelse                                                 | `LogsController@index`       | `email.admin`           |
| `email.inbox.index`              | Fallback Inbox (“Needs Triage”)                                     | `InboxController@index`      | `inbox.view`            |

---

## 3) Common Layout & Components

- **Headerbar:** Titel + breadcrumb + knapper (`+ Add`, `Save`, `Test`, `Reset`, `Close`)
- **Main section:** Kort-basert struktur (Bootstrap) med skjemafelter eller tabeller
- **Right sidebar:**
  - Helse-widgets (OK/Warning/Error)
  - Siste feil, testresultat eller auditinfo
  - Snarveier til relaterte undersider (Accounts, Rules, Logs, Parser)
- **Reusable components:**
  - `Form.Select`, `Form.Toggle`, `Form.Number`, `HelpPopover`, `ConfirmModal`, `HealthBadge`, `List.InlineErrors`
- **Validering:** Klientside før submit, server-revalidering, alle endringer audites

---

## 4) Behaviour & Navigation

- Navigasjon følger sidekartet fra *Settings → Email*:
  - Accounts → Rules → Parser → Inbox → Logs → Advanced/Audit
- Alle undersider kan åpnes direkte via toppnivålenker eller høyre-panel-snarveier.
- Endringer lagres umiddelbart (toast “Saved successfully”).
- Tester kjøres asynkront; resultat vises i sidepanelet.
- Kritiske handlinger (slett, destruktive regler) krever bekreftelsesmodal.

---

## 5) Visual & UX Guidelines

- Følg PSA standardtema (Bootstrap, Lucide-ikoner).
- Fargebruk: grønn = OK, gul = advarsel, rød = feil/destruktivt.
- Alle tabeller søkbare, sorterbare og paginerte.
- Responsive og PWA-kompatible for desktop/mobil.
- Inline hjelpetekster for komplekse felt (polling, retention, TLS).

---

## 6) Security & Audit

- All konfig- og regelendring logges (bruker + tid + før/etter verdi maskert).
- Tilgang styrt via Spatie-permissions (se `email.*`).
- Secrets alltid maskert; passord re-angis ved endring.
- “Health test” og “Acknowledge error” krever `email.settings.manage`.

---

## 7) Non-Functional Requirements

- Skal laste raskt (< 200 ms view-load, lazy load data).
- Alle jobber og tester kjører asynkront i køsystem.
- Full tenant-isolasjon på alle data og logger.
- Forventet stabilitet og observability via metrikker (poll intervall, feilrate, etc.).

---

## 8) Developer Notes

- Backend-config eksponeres via `config('emailhub.*')`.
- API-endepunkter under `/api/email/...` gir sanntidsdata til UI.
- Vue/Livewire-store håndterer state mellom faner.
- Komponenter og validering gjenbrukes i alle e-postvisninger.
- Hendelser (EmailAccountUpdated, EmailRuleTested, EmailConfigUpdated) sendes som domen-events.