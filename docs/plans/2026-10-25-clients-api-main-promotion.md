# Clients API Main promotion - 25 October 2026

Status: Merged to Main / Human Reviewed; production not deployed.
Reviewer/release owner: Svein Tore. Implementation/reconciliation: Codex.
Acceptance: "Godkjent. Merge til main", after the completed Dev delivery.
Human-review entry: HR-2026-10-25-CLIENTS-API, Reviewed.
Individual checklist boxes retain their original state; no separately witnessed
scenario execution is invented. Production deployment/activation is not requested.

## Isolation and scope

Candidate branch: codex/clients-api-20261025.
Base Main: e7bce4321928863cefb7434bf873a3f5a6c541be.
The authoritative shared Dev HEAD/index remain unchanged. This is a scoped
three-way extraction against the saved pre-task working-copy baseline, not a
promotion of all dirty Dev files. Shared catalogue/loader and test edits preserve
current Main and include only Clients changes. Shared TODO and human-review
files receive only the Clients entries; API Management receives only its section.

Two small existing Dev helper classes are required on Main:
app/Modules/Integration/Support/DomainApiRead.php (typed query boolean/pagination)
and app/Modules/Task/Services/TaskApiScope.php (private Task/current owner visibility).
No additional Ticket/Task API surface or unrelated implementation is promoted.
An isolated first run identified these missing dependencies (6 failed/14 passed);
they are included and the complete affected rerun is the required final evidence.
OpenAPI's old JWT format hint is corrected to Sanctum; runtime auth is unchanged.

The candidate has its own vendor autoloader, storage, synthetic key/environment
and in-memory SQLite test databases. Reflection verified all 110 referenced App
classes resolve within the candidate, never to shared Dev. No production or
provider credential is copied or enabled. Provider mutations use synthetic fakes.

## Review boundaries

Clients HR is Reviewed. Existing open reviews already present on Main are preserved:
NEXTCLOUD-CALENDAR-SYNC, SSO-MFA, RELEASE, SSO, WORKDAY-TRIPLETEX, WORKDAY, WORKLOG,
EMAIL-MISSING, EMAIL-SAVE and MAIL-CONVERSATION-READ (their full HR IDs and current
statuses were read before promotion). Their operational/manual acceptance is not
completed by a Clients merge. This candidate does not deploy/activate those systems.

## Verification and promotion

48 changed PHP files pass syntax and scoped Pint. Fresh candidate runs passed:
407 affected tests / 4857 assertions, 29 CloudFactory tests / 340 assertions and
2 targeted Tripletex tests / 19 assertions (438 tests / 5216 assertions total,
non-overlapping test cases). No verification failure is deferred. Exact evidence:
2026-10-25-clients-api-main-evidence.json. Swagger and actual merge proof follow.
Dev's additive command/RMM migration was already applied and checked at batch 26.
Main source merge does not apply a production migration or deploy the application.

## Release instructions

On separately authorized deployment, apply only the exact Clients additive
migration, clear/rebuild the target's existing Laravel caches and regenerate
Swagger as documented in 2026-10-25-clients-api-verification.md.
Preserve durable receipts, legal/financial evidence and encryption material.
Keep new API provider writes disabled until a separate scoped activation is approved.

Native-runtime Swagger generation succeeded: all 51 methods and 74 exact schemas.
Every non-Clients Swagger path remains structurally identical to current Main.
The generated document is included in the candidate. No provider activation occurred.

## Verified merge

Implementation merged in [PR #319](https://github.com/SveinT83/Nexum-PSA/pull/319) at 154ca96b47867b5dfe7a21438d626000935fb24b.
GitHub merged status and freshly fetched origin/main agree. The complete Main tree
equals tested candidate 15374d844897e700a899539a5ba220237b3a942c
(tree 21bd71355247653db000964525f438266c875345). Existing non-Clients paths and
schemas are structurally unchanged. No commit status checks were registered;
the 438 passing Dev-host candidate tests are the verification evidence.
Dev HEAD/index remain unchanged. Production was not deployed or activated.

## Final coordination and handbook read-back

BookStack pages 639/2, 451/11 and 449/133 explicitly record Reviewed and Main
PR #319, while production is still undeployed. Independent connector reads verified
exact source bodies/scoped section, published state and rendered HTML. The new
page retains inherited staff access; no permission changed. Native Knowledge source
read-back passed; native automatic sync remains unavailable/pending_push and the
personal publication was verified separately. Employee OpenWebUI retrieval is not
claimed. One existing website editorial item was updated and read back, retaining
the do-not-publish production gate. Documentation-only merge-record changes do not
alter the tested PHP, routes, schemas or migration.
