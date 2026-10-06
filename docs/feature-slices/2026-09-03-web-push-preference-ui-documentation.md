# Feature Slice: Grouped Web Push Preferences And Documentation

Status: Done On Dev
Date: 2026-09-03
Parent: `docs/rfc/2026-09-03-web-push-notification-type-registry.md`
Dependency: `docs/feature-slices/2026-09-03-web-push-notification-registry-delivery.md`
Owner: Codex

## Goal

Make every eligible internal Notification type understandable and independently configurable in a
responsive, keyboard-accessible preference surface, with complete operator and human-review
documentation.

## User-Visible Behavior

Profile > Notifications groups internal events by domain and shows a short English description plus
the supported Email, in-app, Web Push, preview, and optional Nextcloud Talk controls. Unsupported
channels explain why they are unavailable. Customer Portal-only types are not mixed into the
internal profile page. One Save preferences action persists the complete internal preference set.

## Scope

- Build UI groups and descriptions from the authoritative registry.
- Render native associated labels and understandable supported/unavailable states.
- Preserve server-side validation and one save action.
- Avoid horizontal page overflow on small screens while retaining dense desktop scanning.
- Add UI/persistence/mobile source-contract tests.
- Update Notification plus affected domain Knowledge documentation.
- Add and complete the Issue #257 human-review checklist before handoff.

## Out Of Scope

- New delivery types, Customer Portal Web Push, device-management redesign, VAPID/Admin wizard,
  native apps, or production activation.
- Changes to portal preference behavior or source-domain permissions.

## Data Touched

Existing `notification_settings` rows only. The controller, Blade view, registry presentation data,
tests, TODO, Knowledge documentation, and human-review record are updated. No migration or API
change is required.

## Permissions

The authenticated user may update only their own preferences. Unsupported or wrong-audience values
remain disabled server-side. Existing Web Push device and Admin permissions do not change.

## Tests

- Every eligible internal type appears once in the correct group with an independently labeled Web
  Push switch.
- Descriptions and unsupported reasons are present; portal-only types are absent from the internal
  surface.
- Enable/disable persistence and forged unsupported/wrong-audience values are covered.
- Markup source contracts cover label association, group headings, keyboard-native controls, and
  responsive Bootstrap structure.
- Representative browser QA covers desktop and 390 px mobile layout with no horizontal page
  overflow.

## Documentation

Update Notification channels Knowledge, affected Ticket/Asset/Storage Knowledge notes, TODO, the
parent RFC, both Feature Slices, and the Issue #257 human-review checklist. Record BookStack sync as
an operational handoff action.

## Done Criteria

- [x] Registry/delivery slice is Done On Dev and its tests pass.
- [x] Every eligible internal type is grouped, described, and independently configurable.
- [x] Unsupported and portal-only types cannot be enabled through the internal surface.
- [x] Keyboard-native markup and responsive 390 px source contracts are verified; representative
  visual/device checks remain explicitly Pending in `HR-2026-09-03-004`.
- [x] Focused and affected regression tests pass on authoritative Dev.
- [x] Knowledge, TODO, RFC, slices, and human-review evidence are current.
- [x] GitHub Issue #257 received a verified completion comment and was closed as completed after all
  acceptance criteria are satisfied.

## Completion Evidence

Completed on authoritative Dev on 2026-09-03. The internal page is registry-driven, grouped by
domain, uses associated native switches and responsive Bootstrap columns, explains unavailable
channels, excludes portal-only types, and retains one Save preferences action. Persistence,
wrong-audience/unsupported forgery protection, complete eligible-control coverage, and markup
contracts pass in the 6-test / 322-assertion registry suite. Human visual and real-device checks are
kept Pending in `HR-2026-09-03-004`; they are the Main/release gate, not unfinished Dev code.
GitHub Issue #257 was given completion comment `issuecomment-5530982815` and read back as closed with
state reason `completed`.
