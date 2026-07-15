# RFC: Intake Conditional Fields And Submit Button Text

Status: Approved
Date: 2026-07-04
Owner: Codex

## Context

The Intake form builder now supports configurable fields, field options, mapping, collapsed field
editing, drag-and-drop order, and responsive layout widths. Admins also need conditional fields so
forms can adapt to the submitter. Example workflows include:

- Ask whether the submitter is private or business, then show organization number only for business.
- Show phone and email only after the name field has data.
- Show different follow-up fields based on select, multi-select, or checkbox answers.

The public submit button text is currently hardcoded as `Send inquiry`. Admins need to edit this
text from the form builder.

This is a Level 2/3 change because it changes public form behavior, validation, persisted field
metadata, and how required fields are interpreted.

## Goals

- Let a dependent field define when it should be shown.
- Keep dependency settings on the field that is dependent, not on the source field.
- Add a gear/settings icon on field cards for dependency settings.
- Support conditional visibility based on select, multi-select, checkbox, and text-like fields.
- Hide fields until their configured conditions are met.
- Do not enforce `required` for fields that remain hidden.
- Ignore hidden fields during normalized mapping and file handling.
- Let admins edit the public submit button label from the builder, shown near the bottom after the
  field `+` button.
- Keep existing forms backward compatible.

## Non-Goals

- Do not add multi-page/wizard forms in this slice.
- Do not add complex calculated fields.
- Do not add nested condition groups beyond simple `all` or `any` matching.
- Do not add database migrations unless existing JSON metadata proves insufficient.
- Do not change Sales routing or submission review behavior except that hidden fields are ignored.

## Proposed Change

Use existing JSON metadata:

- Field visibility rules stored in `intake_form_fields.metadata.visibility`.
- Submit button label stored in `intake_forms.metadata.submit_button_label`.

Each field defaults to always visible. A field can be configured as hidden until conditions match:

```json
{
  "visibility": {
    "mode": "conditional",
    "match": "all",
    "rules": [
      {
        "source_key": "customer_type",
        "operator": "equals",
        "value": "Business"
      }
    ]
  }
}
```

Supported first-slice operators:

- `has_value`: source field has any non-empty value.
- `equals`: source field equals the configured value.
- `not_equals`: source field does not equal the configured value.
- `contains`: multi-select source contains the configured value.
- `checked`: checkbox source is checked.
- `unchecked`: checkbox source is not checked.

The builder should show a gear icon on each field card. Clicking it opens the dependent field's
visibility settings. The settings should include:

- Visibility mode: always visible or show when conditions match.
- Match mode: all conditions or any condition.
- One or more condition rows.
- Source field selector.
- Operator selector.
- Value input or select, using source options where available.

For predictability and to avoid circular logic, source field choices should initially be limited to
fields above the dependent field in the saved/displayed order. Reordering a field should therefore
also make its dependency options easier to reason about.

## Public Form Behavior

The public form should render conditional field wrappers with data attributes describing the saved
conditions. A small browser-side script should:

- Evaluate conditions on load and on source field changes.
- Hide dependent fields until conditions pass.
- Disable hidden inputs so they are not submitted by the browser.
- Remove browser `required` enforcement while a field is hidden.
- Restore `required` when the field becomes visible and is configured as required.

Server-side validation must repeat the same visibility evaluation from submitted values:

- Hidden fields are treated as not required.
- Hidden fields are ignored in raw payload normalization and target mapping.
- Hidden file fields should not accept or store files.
- Visible required fields must still be required.

This keeps behavior safe even if browser JavaScript is disabled or tampered with.

## Submit Button Text

The form builder should show a public submit button preview below the field `+` button. Admins can
open it and edit the label text. Public forms use:

- Saved `metadata.submit_button_label` when present.
- `Send inquiry` as the default fallback.

This affects only the public submit button text, not the admin `Save form` button.

## Impact Analysis

- **Intake admin UI:** field cards gain a gear/settings icon and conditional visibility controls.
- **Intake public UI:** conditional fields hide/show dynamically, and the submit button label is
  configurable.
- **Validation:** required rules depend on server-evaluated visibility.
- **Data:** no migration expected; metadata stores both field conditions and submit button label.
- **Submissions:** hidden field values are ignored so stale or tampered values do not route into
  mapped targets.
- **Routes/permissions:** no new routes or permissions.

## Testing Plan

- Feature test that admins can save conditional visibility metadata.
- Feature test that public required fields are not required while hidden.
- Feature test that public required fields become required when conditions match.
- Feature test that hidden mapped fields are not included in normalized payload.
- Feature test that hidden file fields do not store uploaded files.
- Feature test that custom submit button label renders on public form.
- Regression test that existing forms without metadata render all active fields as before.

## Documentation Plan

- Update Intake Knowledge documentation with conditional field behavior.
- Document that conditional settings belong to the dependent field.
- Document that hidden required fields are not required until visible.
- Document submit button label customization.

## Open Questions

- Should source fields be limited to fields above the dependent field in v1, or should admins be
  allowed to depend on any other field with cycle prevention?

## Approval

Approved by Svein in conversation on 2026-07-04.
