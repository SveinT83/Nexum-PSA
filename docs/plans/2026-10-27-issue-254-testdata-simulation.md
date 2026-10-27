# Issue #254 - Contact Portal Test-Data Simulation

Date: 2026-10-27. Environment: authoritative Dev, /var/Projects/tdPSA, branch Dev.
Owner: Codex, coordinated with the existing Contact API workstream at Svein Tore's request.
Status: Automated simulation and focused combined regression passed.
Parent: ../rfc/2026-10-27-contact-full-api-parity.md.
Human-review checklist: HR-2026-10-27-CONTACT-API remains Pending; this report does not
record a human review, authorize production deployment or certify production behavior.

## Test data and workflow

Added app/Modules/Contact/Tests/Feature/ContactPortalIssue254SimulationTest.php on Dev.
Nine independent cases cover central, Client and Site entry points crossed with all
three authoritative roles: Customer admin, Site admin and Viewer.
Each case creates an active technician, active Client, default Site and synthetic
@example.test person in an isolated SQLite in-memory test database. Queue::fake()
captures the invitation job; no external email recipient or provider is contacted.

Each case executes the real ContactForm Livewire save action, reads the saved Contact,
pending invitation and audit, obtains the captured invitation token, then executes
the real GET/POST portal activation routes. Assertions confirm:

- Viewer is the initial role; the role selector appears when invitation is selected.
- Job title and configured Contact relation remain separate from the selected portal role.
- Exactly one Contact, pending invitation and invitation job are created.
- Invitation Client/Site and role match the chosen create context, including client-wide scope.
- Activation creates one active portal account/membership with that exact role and scope.
- Acceptance audit and accepted_at are saved; no internal user role or permission is granted.
- The resulting user opens the portal dashboard (200) and is denied the tech dashboard (403).
- Activation does not create another Contact, invitation, membership or invitation job.

Related existing tests also cover global-default overrides, missing invitation permission,
ordinary edit without resend, invalid role/email, inactive Client, foreign Site, existing
active access, transaction rollback and API replay/read-back.

## Verification

Standalone new simulation: 9 passed, 378 assertions, 32.26 seconds.
Final combined verification: 93 passed, 1224 assertions, 95.58 seconds; exit code 0.
Command, run with umask 0002 and HOME=/tmp:

```bash
php artisan test \
  app/Modules/Contact/Tests/Feature/ContactModuleTest.php \
  app/Modules/Contact/Tests/Feature/ContactApiParityTest.php \
  app/Modules/Contact/Tests/Feature/ContactApiAfterCommitTest.php \
  app/Modules/CustomerPortal/Tests/Feature/CustomerPortalFoundationTest.php \
  app/Modules/Contact/Tests/Feature/ContactPortalIssue254SimulationTest.php
```

Evidence log: /tmp/nexum-issue254-final-simulation-20261027.log.
New test: Pint passed; PHP syntax and scoped whitespace checks passed.
No full application test suite was run by this simulation.

The first combined attempt passed 83 cases but failed the real-commit test during
migration reset: Notification's retained-history migration intentionally refuses down().
The Contact owner added RunInSeparateProcess and PreserveGlobalState(false) to that
real-commit test. The test passed alone (1 test, 20 assertions) and the final combined
run above passed with that isolation. No retention migration was weakened.
The earlier missing signals.occurred_at fixture is also fixed and passes.

## Handoff

The new simulation adds tests only; no application behavior, migration, runtime switch,
role grant, production record or real email delivery changed in this task. No deploy
command is required for the test addition. Test data exists only in the test database.
The Contact owner retains overall API/docs/BookStack/Main/Issue reconciliation ownership;
include this test and evidence in the scoped candidate. Source guide and actual handbook
publication evidence are tracked by that parent workstream. This simulation is not visual
browser QA or proof of inbox receipt. Svein Tore performs the remaining human checklist
before production deployment/release; no checklist item is marked Reviewed by Codex.
