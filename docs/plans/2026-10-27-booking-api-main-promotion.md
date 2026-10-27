# Booking API Main Promotion

Status: Merged to Main; production unchanged; named human review Pending.
Date: 2026-10-27. Authorization: Svein Tore, "Godkjenner og merge til main".

PR: https://github.com/SveinT83/Nexum-PSA/pull/339
Main merge: 8006d0558079bfd8466978761d73a80ed340fa8a.
Verified candidate head: 5d3e0d7ee2de68282dcfecbf78cc702e822d01ac.
Candidate tree: a5e31f832521765bb99c2689e85dbcfc4295e0ae.
Merged tree: b629d4a6d02b2949df320a3506df9b29951f2308.

The application payload matches the verified candidate exactly. The tree difference
contains only independent CustomField promotion documentation already on Main;
none of those records were reverted. A later Contact Main merge retains Booking
unchanged. The companion JSON lists inherited documentation and exact identities.

Dev 189/2622, Main broad regression 189/2513, additional Intake compatibility 48/740,
and final route/ability/schema contract 1/135 passed. Native MySQL races/locking,
encrypted uniqueness/rollback, all-17-operation isolated trusted HTTPS, ordinary
Dev trusted HTTPS reads/Swagger and actual handbook read-back are recorded in the
verification report. Fifty scoped PHP files passed syntax/Pint; whitespace and the
45-file feature allowlist passed. Unrelated Swagger contracts were retained exactly.
Dev HEAD/index remained unchanged; no shared Dev work was committed or pushed.

HR-2026-10-27-BOOKING-API remains Pending. Main merge was explicitly authorized;
manual acceptance, production deployment and API execution activation remain separate.
Apply only the reviewed Booking migration on a later authorized deployment; it is
already applied on Dev. Preserve encrypted receipts/dispatch history and APP_KEY.
Both ordinary Dev API mutation switches remain false. No production deployment,
provider/worker/scheduler activation or real customer notification was performed.

Actual BookStack page 543/revision 3 contains the complete API guide with verified
unchanged inherited staff scope. One public-safe website handoff item was appended
and read back with Status klar for vurdering and an explicit Ikke publiser gate.
