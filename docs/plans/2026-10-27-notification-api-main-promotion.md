# Notification API Main Promotion

Date: 2026-10-27. Owner: Codex. Authorized by Svein Tore: "Godkjenner og merge til main".
Implementation PR: https://github.com/SveinT83/Nexum-PSA/pull/336

## Exact Candidate And Merge

Base: 7d37fcae6c9af68ff77787b4e4cec0ed5c99c972
Source: f0db70a945eff18acb648bad01b085e384af7aa6
Merge: f9efc4eee84e16984e4b6c44433ff9749dd8c2d2
Complete tested/merged tree: 34d850b305f2efaba06d9155d33ecc21603538f3
Fresh origin/main fetch and actual GitHub PR read-back independently confirm merge
and exact tree equality. The candidate contains 51 explicit scoped files.
No Main implementation action remains.

## Validation

Isolated Main broad: 174 tests / 6629 assertions, no failures/skips.
Five distinct Workday consumer-contract cases: 5 / 347, no failures/skips.
After limiting generated Swagger to only this scope: 14 / 4564 pass (overlap).
All 34 scoped PHP syntax/Pint pass. Unrelated Swagger paths/schemas remain equal to Main.

The main affected command is:
HOME=/tmp php artisan test app/Modules/Notification/Tests
app/Modules/Workday/Tests/Feature/ReminderTest.php
app/Modules/CustomerPortal/Tests/Feature/CustomerPortalNotificationsTest.php
app/Modules/Integration/Tests/Feature/SwaggerProductionScanTest.php
app/Modules/Documentation/Tests/Feature/DocumentationApiContractTest.php --compact
Run the five Workday ApiContractTest.php cases separately. All commands run with umask 0002.
Tests enforce SQLite :memory:. Candidate App/Tests classes were independently confirmed
to autoload from its own checkout; vendor hardlinks do not redirect application classes
to shared Dev. No candidate runtime migration or business/provider write occurred.

## Preserved State And Remaining Gates

Shared Dev remains on Dev at 36918edbcaae1ce7b1208ba34f84a3a1719f0e98 with the
exact original staged index. Existing unrelated dirty work is retained. Shared catalog,
portal documentation, route loader and coordination entries were reconstructed from
Main plus only this task's delta. No unrelated API/schema update was promoted.

Only the additive Notification command migration ran on Dev. Production requires
separate rollout authorization, that exact migration, Swagger/cache handling and the
named manual checks in HR-2026-10-27-NOTIFICATION-API (In Review).
No human scenario execution or device/inbox receipt is inferred from the merge.
Real API tests remain off; no production, role grants, VAPID, provider, worker,
scheduler or notification-backlog activation occurred.
