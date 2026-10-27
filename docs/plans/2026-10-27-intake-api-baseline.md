# Intake API Planning Baseline

Date: 2026-10-27. Status: Investigation/planning only, not implementation.
Authoritative runtime: /var/Projects/tdPSA, branch Dev.
Machine-readable routes, source SHA256 and Git baseline: intake-api-baseline.json.

## Current Surface

17 Intake browser rows: 14 protected staff, 3 public; zero Intake API operations.
Seven read route rows and ten mutation route rows (HEAD excluded). Telephony is a
different domain and is excluded. No Intake dirty files were present before planning.
Existing documented foundation/final review is HR-2026-08-11-001, Reviewed by Svein
on 2026-08-25. It does not approve this new API implementation.

## Existing Behavior Verification

Executed on authoritative Dev with group-write umask:
umask 0002 && HOME=/tmp php artisan test app/Modules/Intake/Tests --compact

Result: 21 passed / 168 assertions, no failures/skips; 43.64 seconds.
Tests cover layout/options/conditions, uploads/spam, snapshots, client-scoped matching,
known-client Ticket policy, manual Ticket/Task routing, review/link and Sales target.
These old tests do not certify missing API operations, new permission guards,
provider authority alignment, public API response safety or concurrent command claims.

## Important Source Disagreements Requiring RFC Approval

EnforceTechRoutePermission lists explicit review guards for reviewed and route-sales,
but outcome, link-existing, route-ticket and route-task fall through to intake.view.
The approved final-routing slice and Knowledge article require intake.submission_review.
Do not propagate this fallback into the API; RFC explicitly proposes shared correction.

RouteIntakeSubmissionToSales::createClient directly creates Client/ClientSite, requests
local SuggestClientNumber and assigns contact_email to billing_email. Current
CreateClientRecord/CreateClientWithDefaults provider authority must be followed instead
when implementing full routing API; no behavior was changed during this baseline.

Current index GET calls EnsureIntakeDefaults when forms are empty. API reads must be
read-only; approved implementation must choose an explicit controlled seed/init path,
preserve first-run behavior and test it rather than copying writes into API GETs.

## Coordination And GitHub

Read authoritative AGENTS.md, process/architecture/TODO/relevant human review and
existing Intake source/plans. Intake module had no pre-existing dirty paths.
There is extensive unrelated Dev work: preserve it and recheck shared files per edit.
Knowledge and Signal have independent active work; they are not marked completed or
deferred by this task. Implement Intake only after approval and resolved sequencing.
Connected GitHub open-issue query returned zero matches for intake; no external write.
No application/scopes/migration changes, commit/push, Main/production, live provider
write, invitation/send or handbook publication occurred. No Boost-specific framework
question was needed; this baseline uses actual Laravel routes and Dev feature tests.
