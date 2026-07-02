Work Context defines whether supported work belongs to the owning organization or an external
Client.

The initial supported context types are:

- `internal`: no Client selected; work belongs to the owning organization.
- `client`: Client selected; work belongs to an external customer.

The foundation creates the shared `work_contexts` table, one default internal context, one context
per existing Client, and resolver helpers that module slices can use.

Domains adopt Work Context through approved feature slices.

Current adopted behavior:

- New Ticket records resolve Work Context from `client_id`. Tickets without a Client become
  internal work.
- New Task records resolve Work Context from their selected Client, Client owner, or Ticket owner.
  Standalone tasks without a Client become internal work.
- Documentation records keep their local `scope_type` values and also store `work_context_id`.
  Internal documentation resolves to the default internal Work Context, while client and site scoped
  documentation resolves to the selected Client's Work Context.
- Assets can now be client-owned or internal. Internal Assets have no Client, Site, or Client User
  relation and use the default internal Work Context.
- Risk assessments resolve `client_id = null` as internal, matching the existing Risk business rule.
- Calendar events store Work Context for API/report filtering. Current Calendar events are internal
  by default because Calendar ownership and visibility are still controlled by calendar access rules.
- Existing Ticket and Task records with a real Client are backfilled to that Client's Work Context.
  Existing records that had `client_id = null` are left unscoped until a domain workflow updates
  them.
- Adopted module APIs expose `work_context_id`, loaded `work_context`, and per-module
  `work_context_id`/`context_type` filters alongside existing compatibility fields.
- Knowledge article visibility stays separate from Work Context. `internal`, `client-wide`, and
  `public` still control article visibility, not ownership context.

Important safety rules:

- Existing `client_id` fields remain while modules still use them for reporting, API compatibility,
  billing, or validation.
- Historical `client_id = null` records must not be treated as internal unless the module already
  documents that business rule.
- Commercial, Economy, and Sales remain client-only for this RFC. Internal work must not create
  customer contracts, sales opportunities, or Economy order lines.
- Internal work must not appear in customer-facing reports, public quote/contract surfaces, or
  Economy invoice/order preparation.
- NexumRelationship routing is separate. A local internal or client-scoped record may later be shared
  through a relationship channel, but relationship state is not a Work Context type.
