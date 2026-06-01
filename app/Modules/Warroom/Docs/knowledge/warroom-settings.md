Warroom settings are managed from Admin -> Warroom.

The settings page controls the global beta dashboard:

- SLA due soon time window.
- Inbox recent time window.
- Latest ticket list limit.
- Latest asset alert list limit.
- Calendar event list limit.
- Recent integration list limit.
- Visible dashboard panels.

The settings are stored in `common_settings` with:

- `type`: `warroom`
- `name`: `dashboard`

All settings affect the live dashboard immediately after save.

Visible panel settings are global. They are not per technician. Per-technician dashboard preferences
belong to the later customizable dashboard work.
