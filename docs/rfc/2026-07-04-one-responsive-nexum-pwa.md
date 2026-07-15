# RFC: One Responsive Nexum PWA

Status: Approved
Date: 2026-07-04
Owner: Codex

## Context

GitHub Discussion #169 defines that Nexum should be one responsive application with PWA behavior
across the platform. The product should not split into separate desktop and mobile versions.

This is Level 2/3 work because it affects shared layout, navigation, public/customer surfaces,
mobile technician workflows, assets, service worker strategy, and permissions around mobile actions.

## Goals

- Keep one Nexum application for desktop, tablet, and mobile.
- Make Tech, Admin, Customer Portal, and public surfaces responsive.
- Add hamburger navigation for mobile and small screens.
- Add PWA manifest, icons, install metadata, and a controlled service worker strategy.
- Prepare mobile workflows for My Day, ServiceVisit, time, costs, photos, checklists, messages,
  on-my-way, and map links.
- Define online/offline rules honestly.

## Non-Goals

- Do not build a native mobile app.
- Do not create a separate mobile frontend.
- Do not promise offline writes until conflict, queueing, and sync behavior are designed.
- Do not hide unfinished mobile actions behind visible buttons.

## Current Behavior

Nexum uses a web UI and Bootstrap conventions. Some pages are responsive, but there is no product
wide PWA strategy or complete mobile navigation standard.

## Proposed Change

Create a platform-wide PWA and responsive UI program.

The first slices should focus on:

- shared responsive shell/navigation,
- PWA manifest and icons,
- safe service worker with no offline write promise,
- mobile technician "My Day" read/action surface,
- page-by-page responsive hardening for high-value workflows.

Domain modules remain responsible for their own mobile-safe actions and permissions.

## Approved Implementation Direction

- Use `erag/laravel-pwa` for Laravel manifest/head/service-worker registration.
- Keep one installable Nexum app with scope `/`, standalone display, Nexum branding, and app-wide icons.
- Register PWA metadata in the Tech shell, login/guest surfaces, Customer Portal, Booking, Intake,
  public quote acceptance, and public contract acceptance.
- Use an online-first service worker. Navigations fall back to a static offline page only when the
  server cannot be reached. Static assets may be cached, but private HTML/API responses and writes
  are not cached as offline data.
- Add `/tech/my-day` as the first mobile technician surface. It reads existing Ticket, Task, and
  Calendar data and uses existing permissions and actions.
- Add responsive tech navigation with mobile offcanvas behavior instead of a separate mobile
  frontend.

## Impact Analysis

- **UI:** shared layout, sidebars, admin nav, portal nav, public layout.
- **Assets:** manifest, icons, service worker, Vite/build behavior.
- **Permissions:** mobile actions must use existing abilities.
- **Ticket/Task/Calendar/ServiceVisit:** mobile workflows and My Day.
- **Notification:** install/push notification direction later.
- **Testing:** viewport tests and browser/PWA checks where practical.

## Data And Migration Plan

No required database changes for the platform foundation. Later offline-capable writes would require
separate RFC updates and possibly client-side storage metadata.

## Testing Plan

- Feature tests for route visibility and permissions remain module-owned.
- Browser viewport checks for shared shell and critical pages.
- Manifest/service worker checks.
- Regression checks for desktop navigation.

## Documentation Plan

- Update UI guidelines with responsive/PWA rules.
- Add Knowledge/developer docs for supported PWA behavior.
- Document offline limitations clearly.

## Open Questions

- Should push notifications be part of this RFC later, or handled by Notification separately?
- Which workflows should later get explicit offline queueing, conflict handling, and sync status?

## Approval

Approved by product discussion on 2026-07-05 for implementation of the responsive PWA foundation
and mobile My Day slice. Offline writes and push notifications remain future slices.
