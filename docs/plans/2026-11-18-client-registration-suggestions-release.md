# Release of client registration suggestions

Svein Tore approved the Dev workflow and authorized promotion to Main and production on 2026-11-18. The human-review entry is HR-2026-11-18-CLIENT-REGISTRATION-SUGGESTIONS.
The isolated candidate passed six registration and recovery tests (102 assertions) and two official Clients API contract tests (427 assertions). The original Dev verification used 10 tests (562 assertions) and Chromium.
Contract verification identified two existing time-report schema entries in the manifest that lagged the generated Main API. They now reflect the existing time-report output; time-report runtime code is untouched.
The production overlay was prepared from the seven installed file preimages plus three new runtime files. It preserves the private-customer integration behavior installed on VM169.
No database migration, provider activation or data backfill is needed. During rollout regenerate the route cache and OpenAPI descriptor, clear the view cache and reload PHP-FPM. Verify both routes, all three identifier types, receipt behavior and the handbook after installation.
