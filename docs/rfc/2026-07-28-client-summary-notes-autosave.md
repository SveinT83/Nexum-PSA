# RFC: Client Summary Layout And Notes Autosave

Status: Approved / Implemented
Date: 2026-07-28
Owner: Svein Tore / Codex
Change level: Level 2 Client UI and inline persistence workflow
GitHub issue: #189
Implementation approval: Approved by Svein Tore on 2026-07-28

## Context

The Client profile Summary card currently renders short metadata as one vertical definition list.
This leaves most of the card width unused on desktop, omits the existing Client number, and places
Notes in the same narrow label/value pattern as short fields.

Editing Notes currently requires opening the full Client settings form and submitting every Client
field. Issue #189 requests a focused inline Notes editor with debounced Livewire persistence and
honest saved, saving, and failure states. Existing `client.update` permission behavior must remain
authoritative.

## Goals

- Show Client number as a distinct Summary value.
- Arrange short Client metadata in a compact responsive Bootstrap grid.
- Use three columns on wide screens, two columns on medium screens, and one column on small screens.
- Keep Notes full width below the short metadata.
- Let users with `client.update` edit Notes inline through a debounced Livewire component.
- Show Saving only while the current update request is pending.
- Show Saved only after the server confirms that the current text was persisted.
- Keep the entered text visible and show a compact error when persistence fails.
- Render Notes read-only for users without `client.update`.
- Preserve the existing full Client settings form and update workflow.

## Non-Goals

- Do not autosave Client name, number, status, billing email, RMM mapping, or custom fields.
- Do not add rich text, Markdown editing, note history, comments, mentions, or an audit table.
- Do not add a route, controller endpoint, database table, migration, queue, or scheduled task.
- Do not change the meaning or maximum size of the existing `clients.notes` field.
- Do not redesign the related-record workspace tabs or Client right sidebar.
- Do not replace the full Client settings form.

## Current Behavior

- `clients.notes` is an existing nullable field on the Client model.
- The Client Summary card shows Name, Org No, Format, Billing Email, Status, optional N-able RMM
  mapping, and Notes in one vertical label/value list.
- The Summary card does not display `clients.client_number`.
- The gear action opens the existing Client settings form.
- `ClientSettingsController::update` accepts Notes together with the other Client fields.
- Tech Client settings update routes require `client.update` through `EnforceTechRoutePermission`.
- The Clients module has a Livewire directory but no Client Notes component.
- Module Livewire aliases are registered explicitly in `AppServiceProvider`.

## Proposed Change

### Responsive Summary Grid

Keep the existing Summary card and gear action. Replace the vertical definition list with a
Bootstrap row using `col-12 col-md-6 col-xl-4` metadata cells. Each cell will use a compact muted
label and a readable value. The grid will contain:

- Client number.
- Name.
- Organization number.
- Client format.
- Billing email.
- Status.
- N-able RMM mapping when the integration is active.

Missing values will use the existing muted dash convention. Notes will live in a full-width section
below the metadata grid, separated with a subtle border and compact spacing.

### Client-Owned Livewire Notes Component

Add `ClientNotesAutosave` under `app/Modules/Clients/Livewire/Tech` with its Blade view under
`app/Modules/Clients/Views/Livewire/Tech`. Register the component with a stable
`tech.clients.notes-autosave` alias in `AppServiceProvider` and render it from the Summary card.

The component will receive the current Client, retain a locked Client ID, and initialize its Notes
text from the database. It will calculate whether the current user can update the Client, but every
server-side save will re-check `client.update`; the public render state is never sufficient
authorization by itself.

For authorized users, the component will render a textarea bound with a short Livewire debounce.
After the debounced model update reaches the server, the component will validate Notes as nullable
text, refetch the Client by the locked ID, and persist only the `notes` field.

For unauthorized users, the component will render the existing Notes value as read-only text or a
muted empty state. It will not render a textarea.

### Honest Save State

The component will use three explicit server states: idle, saved, and error. Client-side Livewire
directives will provide the pending state:

- `wire:dirty` identifies text that has not yet been confirmed by the server.
- `wire:loading` targeted at Notes displays a quiet Saving label during the request.
- Saved is rendered only when the last server operation completed successfully and is hidden while
  Notes is dirty or a Notes request is loading.
- A caught persistence failure sets the error state, reports the exception, preserves the entered
  text, and displays a compact validation-style message. It never displays Saved.
- A later successful edit clears the old error and can return to Saved.

Livewire's normal request ordering remains the transport contract for debounced updates. A separate
optimistic-lock column or Notes version history is outside this change.

### Existing Edit Workflow

The full Client settings route and form remain unchanged. Saving Notes inline updates the same
`clients.notes` field, so reopening the settings form shows the latest saved value and ordinary full
form updates remain compatible.

## Impact Analysis

### Affected Areas

- Clients module: Summary Blade markup, one Livewire component/view, tests, and Knowledge docs.
- `AppServiceProvider`: one explicit Clients Livewire alias and import.
- Existing Client model and settings form: same field and behavior, no contract change.

### Permissions And Security

- `client.view` remains required to open the Client profile.
- `client.update` is required to render the inline textarea and is rechecked on every save.
- A manipulated Livewire request must not update Notes without `client.update`.
- The locked Client ID prevents a browser from changing the target Client between requests.
- No customer portal surface is affected.

### Routes, Data, And Runtime

- No route or middleware mapping changes.
- No database migration or backfill.
- No API, queue, scheduler, integration, or frontend build change.
- Livewire uses the existing authenticated runtime and CSRF/session protections.

### Risks And Side Effects

- A false Saved indicator could mislead users after a failed request. Server state plus targeted
  dirty/loading directives must keep the badge honest.
- Weak component authorization could bypass the normal Client settings route guard. Save-time
  permission enforcement and negative Livewire tests are mandatory.
- A deleted or missing Client during an autosave must produce an error state without claiming the
  Notes were persisted.
- Two users editing the same Notes field still use last-confirmed-save-wins behavior. Note history
  or conflict resolution requires a separate approved change.
- Replacing the Summary markup must not remove the gear action, RMM state, status display, or the
  related Client workspace below it.

## Data And Migration Plan

No migration or backfill is required. Rollback removes the Livewire component and restores the
read-only Notes display; the existing Notes data remains unchanged.

## Testing Plan

- Client feature test: Client number and all existing Summary fields are present.
- Client feature test: responsive Bootstrap column classes and full-width Notes section render.
- Livewire test: an authorized user changes Notes and the database plus Saved state update.
- Livewire test: blank Notes persist as null or the established empty representation.
- Livewire test: a user without `client.update` sees read-only content and cannot force a save.
- Livewire test: a missing/deleted Client produces an error state and no false Saved state.
- Render test: debounce, dirty, loading, Saved, and compact error markup are present.
- Regression test the complete Client feature file and existing Client settings update flow.
- Compile Blade views and perform a Dev HTTPS smoke check for Client show.

## Documentation Plan

- Update `app/Modules/Clients/Docs/knowledge/client-domain-overview.md` with the responsive Summary
  layout, Client number, Notes permission, autosave timing, and save states.
- Update `docs/TODO.md`, the RFC index, and `docs/human-review.md` when implementation completes.
- Sync the Clients Knowledge article through the existing repository Knowledge command.
- Add a public-safe website handoff item only after implementation verification, marked not to
  publish while human review is open.

## Open Questions

None. Issue #189 defines the expected responsive layout, permission boundary, and save-state
behavior; this RFC selects a narrow Client-owned implementation.

## Approval

Approved by Svein Tore on 2026-07-28.

## Implementation

Implemented on Dev on 2026-07-28. Clients owns the responsive Summary grid and the
`ClientNotesAutosave` component; every save rechecks `client.update` and updates only the existing
Notes field. The complete Clients feature suite passes with 32 tests and 293 assertions. Clients
Knowledge documentation is synchronized, and manual responsive, permission, save-state, and
existing-settings verification remains open under `HR-2026-07-28-004`.
