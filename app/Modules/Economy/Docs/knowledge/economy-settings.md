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
