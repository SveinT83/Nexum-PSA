# RFC: Transfer a Ticket to a different Client

Status: Approved for Dev
Date: 2026-11-25
Owner: Ticket / Clients, product decision by Svein Tore
GitHub: #411

## Context and goals

A technician may have opened an internal Ticket while establishing the customer's identity. They need to select the verified Client, retain the same Ticket number, attachments, history and time, and verify the destination and visibility. A previous customer's information must stay protected.

## Current behavior

The Clients API supports searching active Tripletex profiles by organization/customer number, selecting the provider ID at registration and setting up the primary contact. The approved Clients RFCs govern these operations. The Ticket API's update validator already accepts client_id, contact_id, site_id and asset_id. UpdateTicketFields recalculates work context and reconciles owned work. Portal publication has its own action. A generic update or adding an MCP field alone does not prove safe transfer or portal isolation.

## Proposed change

Use one guarded transfer operation for browser and API callers, with a read-only impact preview. Authorize the actor for the source and destination and require the current Ticket revision and a replay key at commit. Revalidate destination contact, site and asset under lock. Reject mismatches and list the fields to clear or replace. Apply the same transfer rules to any existing entry point that can change the client.

The proposed default clears portal publication during the transaction. Keep existing history, attachments and time internal during transfer. Send no customer notification, portal invitation, email or provider write. Further publication requires a distinct authorized review of the material the destination could see. Review file/download access, portal replies, prior recipients, queued deliveries, public links, and the existing Ticket rule triggers. Recheck delivery at execution time where queued work can outlive transfer.

## Tripletex registration and merging

Use the existing normalized email, phone and organization-number suggestions while typing. When both a local Client and provider customer are candidates, present the two selected identities for explicit review. Shared contact channels are discovery evidence. A conflicting organization number or an existing different provider binding prevents a merge. Adopt the verified Tripletex number only after the reviewed local and remote numbers have been supplied. Preserve the local Client ID, contact relations and other fields. A provider-only selection continues to use the existing registration path. The merge API must be independently callable, replayable and protected by the same management rights as the existing browser adoption. Existing create clients may continue to consume selection_required, while strict callers can request a conflict response with candidates and next actions. A duplicate stopped by provider validation must also explain how to find and select the candidate.

## Impact and data

Retain the Ticket identity and primary keys. Reuse WorkContext and the existing reconciliation and audit mechanisms. Record actor, old and new Client, accepted target context and resulting visibility in internal history. Preview impacted Tasks, ledger entries and reservations. Block a transfer when bound financial evidence, contractual records, or outbound work cannot be reconciled safely. Respect immutable billed evidence. No bulk moves, production data edits, provider writes or retroactive customer messages are proposed.

## Migration and rollback

Start with existing data and event history. Determine whether durable replay and review evidence need an additive migration before implementation. Roll back an attempted operation atomically. Reversing a completed transfer requires a fresh authorized action. No data backfill is proposed.

## Testing and documentation

Use synthetic customers and isolated provider responses to test no match, ambiguity, selected ID, organization conflicts, existing local bindings, primary contact and concurrent registration. Exercise internal-to-client and client-to-client transfers, stale/repeated/concurrent requests, wrong destination children, portal and attachment denial on both customers, no notification and invariant Ticket/Task/time identifiers and ledger values. Verify API/OpenAPI and the consuming MCP inventory. Update Ticket/Clients Knowledge and employee BookStack after Dev verification.

## Open question and approval

Svein Tore approved the hidden/no-notification Dev transfer in conversation on 25 November 2026. He also requested general Tripletex-first import, live suggestions and an explicit merge endpoint. Main, production and edits to the example customer or Ticket remain separate decisions.

## Dev implementation and acceptance

The dedicated API preview includes counts for tasks, attachments, time entries, cost entries and linked reservations. Pending outbound communication is a conflict. Existing paid or booked evidence remains attributable to its original customer under the established owned-work reconciliation. No new table, migration, backfill or scheduled task is needed. The browser retains its standard form submission. The dedicated MCP/API command additionally requires an explicit preview revision and idempotency key.
See the [accepted ADR](../adr/2026-11-25-reviewed-customer-identity-and-ticket-transfer.md) and the pending human-review checklist HR-2026-11-25-CLIENT-REGISTRATION-TRANSFER.

The staff registration shortcut opens a single-pair comparison under Clients. The integration administration view reuses the same comparison form. Dev Chromium exercised the shortcut and confirmed the persisted local number as a Tech with the required permissions. Two real portal-account sessions against the Laravel test kernel verified that a previously working attachment URL returned 404 to both after transfer. The Ticket and its recorded minutes remained accessible to staff, and a queued previous reply stopped before delivery. A replay after subsequent portal publication reports a conflict so the caller reads current state.

The trusted Dev HTTPS contract exposes all three operations. The installed MCP generator serialized their bodies, destination paths and the customer merge idempotency header using a simulated upstream. The active production gateway uses its versioned specification and needs separate promotion and staff acceptance. The shared ChatGPT connector previously rejected a catalogue above 1,000 tools. No production customer, Ticket, gateway specification or account grant was changed.

Two separate Dev PHP processes submitted selected-provider registrations concurrently with different API and Tripletex keys against a shared isolated SQLite database and shared lock store. Responses were 201 and 200. Both returned the same Nexum Client ID, and the database contained one Client and one provider link. The fake provider was used throughout.

Final Dev regression evidence: all 55 Tripletex customer number tests passed (414 assertions) in independent isolated databases. Ticket and portal suites passed 31/309, outbound email suites 11/85 and generated contract suites 5/42,647. The staff Chromium test passed 1/15 and concurrent registration with separate PHP workers passed 1/6. The human checklist and operational promotion remain open.

## Decision on imported invoice addresses, 26 November 2026

Svein Tore approved explicit billing recipients for selected existing Tripletex customers. The same rule applies with or without an organization number. Importing that customer with a primary contact and no invoice email leaves the Nexum billing address empty. A reviewed invoice address submitted through the form or API is retained. Adoption of an already existing Nexum Client preserves its fields. The 9 November rule still applies to ordinary new-customer creation.

## Production authorization, 26 November 2026

Svein Tore accepted the Dev delivery and requested promotion to Main and production. Use the same native permissions, token scopes and actor policies in production. The shared gateway to Dev remains office-restricted. Employee ChatGPT discovery and account-specific acceptance require separate read-back.
