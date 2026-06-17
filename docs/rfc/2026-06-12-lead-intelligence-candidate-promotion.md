# RFC: Lead Intelligence Candidate Promotion

Date: 2026-06-12
Status: Approved
Owner: Codex

## Context

Lead Intelligence can already store prospecting settings, segments, planned
research runs, source evidence, suppression entries, and contact marketing
eligibility. The next needed slice is the safe persistence path from a
structured research result into Nexum PSA records.

The full web crawler and AI enrichment worker are still out of scope, but once a
future worker has a structured candidate it must be able to create or reuse a
Client lead candidate, create or reuse Contacts, attach them to the Client, and
promote eligible Contacts to configured Marketing lists.

## Decision

Add a narrow API-backed promotion action:

- `POST /api/v1/lead-intelligence/promote-candidate`
- Ability: `lead-intelligence.run`
- Input: structured company, optional contacts, optional research run, optional
  marketing list IDs, optional dry run.
- Respect Lead Intelligence settings before creating Clients, Contacts, or
  Marketing list members.
- Use existing Client, Contact, ContactRelation, legacy ClientUser bridge, source
  evidence, eligibility, suppression, and MarketingListMember tables.
- Do not send email.
- Do not crawl websites or query external services in this slice.

## Scope

Included:

- Match existing Clients by organization number, website host, or exact name.
- Create Client lead candidates when settings allow it.
- Create a default Client site when needed.
- Create or reuse Contacts and attach them to the Client.
- Keep the legacy ClientUser bridge populated through the Contact module action.
- Store Lead Source Evidence for the company and contacts.
- Evaluate contact marketing eligibility with suppression override.
- Create MarketingListMember rows only when eligibility and settings allow it.
- Support segment marketing-list targets through the associated research run.

Out of scope:

- Web discovery/crawling.
- BRREG lookup.
- External search providers.
- AI enrichment workers.
- Email sending.

## Risks

- The existing Client model has no dedicated lead status field. The configured
  `default_client_status` is recorded in lead evidence/client notes rather than
  a first-class Client status.
- Promotion depends on structured candidate payloads until the future discovery
  worker exists.

## Tests

- Lead Intelligence feature tests cover candidate promotion, suppression
  blocking Marketing list membership, and required API ability enforcement.
- Marketing feature tests verify existing list behavior still passes.
