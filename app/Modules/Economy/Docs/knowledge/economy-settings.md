Economy settings control when source records become internal order lines.

Defaults:

- `Create orders from closed ticket time`: enabled.
- `Create orders from resolved ticket time`: disabled.
- `Include unresolved ticket time at period close`: disabled.
- `Create orders from picked ticket costs`: enabled.
- `Auto-pick available ticket costs on solved or closed tickets`: disabled.
- `Time grouping`: one order line per time entry.
- `Line text`: ticket reference plus invoice text.
- `Order prefix`: `ORD-`.
- `Default VAT %`: copied from existing economy common settings when available, otherwise `25`.

Important behavior:

- Resolved and closed are separate settings. The default waits for closed tickets.
- Unresolved period-close billing is off by default because this can invoice work before a ticket is complete.
- Auto-pick is off by default. Manual picking is safer because it makes stock consumption explicit.
- Default VAT is used for generated ticket time, quick timebank overuse, and cost lines that do not carry their own VAT rate. Set it to `0` for no default VAT.

Changing settings affects future generation. Use `Generate orders` after changing settings if existing pending records should be re-evaluated.

The scheduled Economy catch-up job runs daily. This is intentionally slower than ticket and email jobs because invoicing is normally monthly, while picked items are still handled quickly when the technician clicks `Pick`.

## Administration and API on Dev (25 October 2026)

The complete settings API is verified on Dev under HR-2026-10-25-ECONOMY-API, Reviewed by
Svein Tore (25 October 2026), and merged to Main in [PR #315](https://github.com/SveinT83/Nexum-PSA/pull/315).
Production deployment is not verified. Open Admin -> Settings -> Economy using an active account
with the current economy.manage_settings permission; the installation may delegate that permission.

1. Review the five source/auto-pick switches and the order text/prefix/VAT fields.
2. Save the intended values. Unchecked browser switches are saved as false. Merely opening the
   page resolves effective defaults without creating a settings row.
3. Use Generate orders when pending records should be re-evaluated. Saving auto-pick does not
   itself pick stock; stock consumption stays in the supported Ticket/Storage workflow.
4. Verify future generated lines. Changing settings does not rewrite historical financial amounts.

GET /api/v1/economy/settings requires economy.settings.read plus the current settings permission.
PATCH requires economy.settings.update, a fresh Idempotency-Key and If-Match from the GET revision.
Every listed setting is supported. Omitted PATCH fields are preserved; false and zero are applied.
The only supported grouping is per_entry and line format is ticket_date_text. Prefix is at most
20 characters; default VAT is 0..100. Null means current common Economy VAT fallback (otherwise 25),
while zero explicitly gives a zero default VAT. Read-back reports the effective value and revision.
Unknown/nested/server-owned fields are refused, and a stale revision requires renewed review.
