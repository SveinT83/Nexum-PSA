# RFC: Intake Form Layout Builder

Status: Approved
Date: 2026-07-04
Owner: Codex

## Context

The Intake form builder can define fields, field types, options, file limits, required state,
visibility, and mappings. It still behaves as a simple vertical field list. Real customer workflows,
such as onboarding a new employee or technician, need better layout control so admins can place
related short fields on the same row and reorder fields visually.

This is a Level 2/3 change because it changes significant admin UI behavior, persisted field layout,
and public form rendering.

## Goals

- Let admins reorder Intake fields with drag and drop.
- Let admins place multiple fields on the same visual row.
- Show a visual layout preview inside the builder so left/right placement is clear.
- Preserve field order and layout when the form is saved.
- Render the public form using the same saved layout.
- Keep existing forms backward compatible.

## Non-Goals

- Do not build a full page designer or arbitrary grid editor.
- Do not add conditional field logic in this slice.
- Do not add new routing targets.
- Do not change submission storage or target mapping behavior.
- Do not require a database migration unless implementation proves metadata is insufficient.

## Current Behavior

Intake fields are sorted by `sort_order` and rendered as a single vertical list. The admin builder
posts fields in DOM order, and `IntakeFormFieldInput` assigns `sort_order` from the submitted order.
The public form renders each active field as a full-width `mb-3` block.

`intake_form_fields` already has a nullable JSON `metadata` column.

## Proposed Change

Use a constrained Bootstrap 12-column layout model per field:

- Each field stores a layout width in `metadata.layout.width`, defaulting to `12`.
- Supported widths should be conservative and predictable:
  - `12` full row.
  - `6` half row.
  - `4` third row.
  - `3` quarter row.
- Field order remains the existing `sort_order`.
- The builder displays fields in a Bootstrap row/grid based on the selected widths.
- Admins can drag fields to reorder them. Consecutive fields whose widths fit within 12 columns
  visually appear on the same row.
- Each field row/card exposes a compact width control, for example `Full`, `Half`, `Third`, and
  `Quarter`.
- Dragging changes DOM order; saving persists that order through the existing normalization path.
- Public rendering groups active fields into rows using the saved widths and order.

This avoids a separate row/table model while still supporting the requested visual behavior:
placing fields next to each other, seeing left/right order, and adjusting layout by reordering and
width selection.

## Impact Analysis

- **Intake admin UI:** field builder gains drag handles, visual grid layout, and width controls.
- **Intake public UI:** public forms render fields according to saved layout width.
- **Data:** `intake_form_fields.metadata` stores layout width. `sort_order` continues to store
  order.
- **Routes/permissions:** no new routes or permissions.
- **Database:** no migration expected.
- **Submissions:** no storage change. Submitted payload keys remain the same.
- **Security:** no new public inputs beyond existing field definitions.

## Data And Migration Plan

No migration is planned. Existing fields with missing metadata render as `width = 12`.

On save, normalize layout metadata to allowed values only. Invalid or missing width values fall back
to `12`.

Rollback is simple: older code ignores the metadata and fields return to full-width vertical layout.

## Testing Plan

- Feature test that admin can save fields with layout widths and order.
- Feature test that existing fields without metadata still render full width.
- Feature test that public form includes expected Bootstrap column classes for saved widths.
- Regression test that submission validation and storage still use field keys, not layout position.

## Documentation Plan

- Update Intake Knowledge documentation with layout behavior.
- Mention that layout controls affect visual placement only, not submission mapping or routing.

## Open Questions

- Should the first implementation use only width controls plus drag ordering, or should it also
  support explicit row separators in v1?

## Approval

Approved by Svein in conversation on 2026-07-04.
