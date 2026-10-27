# Nextcloud API Planning Baseline

Status: Verified existing behavior / new parity implementation awaits RFC approval.
Date: 2026-10-27
Owner: Codex.
Runtime: /var/Projects/tdPSA, branch Dev.
HEAD: 36918edbcaae1ce7b1208ba34f84a3a1719f0e98.

Live route:list inventory: 19 administrative browser rows, 14 versioned protected
calendar-only operations and 1 independently signed inbound Talk webhook. Counts
represent separate surfaces and do not prove a parity percentage. Controller,
actions, models, Blade forms, effective permission resolver, existing calendar RFC/
ADR and relevant GitHub Issue #205 body and zero comments were inspected.

Command on authoritative Dev:

    HOME=/tmp php artisan test app/Modules/Nextcloud/Tests/Feature

Result: 84 passed, 385 assertions, 51.23 seconds; zero failed/skipped.
Suites: AutomaticCalendarSyncTest, CalendarOperationsApiTest,
NextcloudCalendarTransportTest, NextcloudModuleTest and TalkWebhookTest.
Provider/AI behavior is mocked in these tests; no live sync or Talk delivery claim.
This baseline does not verify new API support that has not been implemented.

Created Draft RFC, Proposed ADR, five Draft sequential slices and coverage matrix;
updated TODO and Pending/planned human-review entry. No application implementation,
new migration, role grant, provider/customer mutation, commit/push/Main promotion,
production deployment or handbook publication was performed in this planning task.
Existing shared Dev contributor changes remain intact; local copies are review aids.
Next: Svein Tore approves the concrete Level 3 RFC before implementation.
