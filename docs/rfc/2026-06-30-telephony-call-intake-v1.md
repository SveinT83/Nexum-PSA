# RFC: Telephony Call Intake v1

Status: Approved
Date: 2026-06-30
Owner: Codex

## Context

Technicians need a fast intake surface when a phone provider opens Nexum PSA during an answered
call. The provider-neutral idea was captured in `docs/TELEPHONY-CALL-INTAKE-IDEA.md` and approved
through GitHub Discussion #49 before implementation.

The first slice introduces a new Telephony domain, a public provider URL, call records, caller
matching, and ticket integration. Because this is a domain, database, integration, permission, and
cross-module change, it is Level 3 under `AGENTS.md`.

## Goals

- Create a Telephony module that owns call intake routes, records, token URLs, views, docs, and tests.
- Give each technician with `telephony.view` a personal provider URL.
- Accept provider GET, form, and JSON payloads through a public token URL.
- Normalize caller numbers and match existing Contact, ClientUser, Client, and Site context.
- Record each call and keep provider payload metadata for audit/debugging.
- Deduplicate provider call IDs per technician/token owner, not globally across technicians.
- Let authorized technicians save call notes, create a ticket from a call, and link a call note to a
  related existing ticket.
- Keep the first implementation provider-neutral and compatible with Telia-style `caller=%no`
  substitution.

## Non-Goals

- Provider-specific Telia API adapter.
- Admin-managed provider profile definitions and field mapping UI.
- Outbound click-to-call, transfer, SMS, missed-call handling, contact sync, or call-log imports.
- Unknown-caller Contact/Client creation from the intake page.
- Quick time registration from calls.
- Global technician note app.

## Current Behavior

Before this slice, Nexum PSA had no Telephony module and no provider URL for answered calls.
Technicians handled caller lookup, notes, and ticket creation manually.

## Proposed Change

Implement `app/Modules/Telephony` with:

- Public intake routes under `/telephony/intake/{token}`.
- Authenticated technician profile routes under `/tech/telephony/profile`.
- `telephony_tokens` for encrypted personal intake tokens.
- `telephony_calls` for provider payloads, caller context, notes, linked tickets, and status.
- Provider-neutral payload parsing for common caller and call-id field names.
- Ticket creation through the existing Ticket module action.
- Ticket linking only when the target ticket belongs to the same call context.

## Impact Analysis

- **Telephony:** new module, routes, models, migrations, actions, views, tests, and Knowledge docs.
- **Ticket:** call intake can create tickets and add internal notes to scoped related tickets.
- **Contact/Clients:** caller matching reads existing phone/contact/client/site data.
- **Permissions:** technicians need `telephony.view`; ticket creation/linking also requires the
  existing ticket permissions used by the web UI.
- **Integration/security:** the public provider URL is credentialed by a long-lived token, can be
  rotated by the technician, and must not allow arbitrary ticket writes.
- **Docs:** module README, Knowledge docs, TODO status, and this RFC are source of truth for v1.

## Data And Migration Plan

- Add `telephony_tokens`.
- Add `telephony_calls`.
- No backfill is required for clean install.
- Provider call dedupe keys are scoped by token owner/technician so two technicians cannot overwrite
  each other's records when providers reuse call IDs.
- Rollback drops the Telephony tables through normal migration rollback.

## Testing Plan

- Feature tests cover route ownership, profile token creation/rotation, public token rejection,
  caller matching, provider ID deduplication, fallback deduplication, ticket creation, ticket linking,
  ticket permission guards, and unrelated-ticket blocking.
- Run the Telephony feature test suite before merge.
- Run Ticket feature tests when ticket integration code changes.

## Documentation Plan

- Keep `docs/TELEPHONY-CALL-INTAKE-IDEA.md` as the product direction and v1 status document.
- Keep `app/Modules/Telephony/README.md` as developer/module documentation.
- Keep `app/Modules/Telephony/Docs/knowledge/telephony-domain-overview.md` ready for Knowledge sync.

## Open Questions

- Should admin-managed provider profiles and custom field mapping be the next Telephony slice?
- Should unknown callers be linkable/creatable from the intake page before quick time registration?
- Should call notes later integrate with a broader technician note app?

## Approval

Approved by Svein through GitHub Discussion #49 and follow-up implementation instruction in this
conversation on 2026-06-30.
