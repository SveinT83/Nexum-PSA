# Economy API human-review handoff

## HR-2026-10-25-ECONOMY-API - Full Economy Browser/API Coverage

Status: Reviewed. Human reviewer: Svein Tore. Approved: 2026-10-25. Implementation: Codex on authoritative Dev.
Design approved 2026-10-25 in chat. Related: full Economy API RFC, ADR and six 2026-10-25 slices.
Gate: Economy Main promotion is explicitly authorized by the named human approval below.
Production deployment, real customer operations and external activation remain separate.
Acceptance: Svein Tore stated "Godkjent. Merge til main og foreslå neste domain som mangler api"
after the completed Dev handoff. This records acceptance of the delivered scope; the historical
scenario checkboxes are retained and do not assert individually witnessed execution.

- [ ] Compare order list/detail/lines, totals including legacy missing VAT, context/filter/stats and
  source references in browser/API; customer projection excludes internal fields.
- [ ] Generate synthetic Ticket time/cost, quick overuse and confirmed licence billing twice;
  inspect exact source allocation/customer and absence of duplicate billing; test source correction
  and last-line deletion without stock reversal, plus native concurrent writers.
- [ ] Mark ready/draft/manual-invoiced and publish/hide: same actor/status/audit and preference-aware
  notifications; retry cannot duplicate effects; no external invoicing/export is implied.
- [ ] Read/update each Economy setting with omission/false/zero semantics; unauthorized settings,
  inactive/revoked actor, foreign parent, unknown fields and stale revision are refused.
- [ ] Inspect Economy Data Exchange preview/command/run/file/checksum/download; wrong source/file
  denied, configured profile preserved, no automatic provider delivery or exported state.
- [ ] Check client versus site Portal access/publication, full Swagger contracts, employee handbook
  content/access and exact migration/rollback instructions. Confirm Dev-only availability.

Implementation and exact evidence are complete in plans/2026-10-25-economy-api-verification.md.
Final 60 / 640 Economy/DataExchange, 5 / 313 API admin, 384 / 4324 broad affected and 47 / 558
Economy/production Swagger pass; 40 PHP syntax/Pint pass. Native additive migration batch 24,
actual lock/unique probes, trusted HTTP and six BookStack page/access read-backs pass.
Main candidate verification is in progress under the explicit authorization. Production is not authorized. Preserve financial,
source, audit, command and notification evidence on rollback. No scheduler activation.
