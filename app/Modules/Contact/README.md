# Contact Domain

Contact is Nexum's canonical identity domain for external people, customer contacts, shared
mailboxes, departments, vendor representatives, and communication endpoints.

## Canonical Technician Workflow

Technicians create and edit people through the Contact form. The Client and Site workspaces list
canonical Contact records and open the same Contact routes as the central Contacts workspace.

- Client context preselects the Client in the Contact form.
- Site context preselects both Client and Site.
- The Client, Site, and central entry points share validation, duplicate detection, permissions,
  relation persistence, audit behavior, and error handling.
- Old Client User URLs remain compatibility redirects only. Old form submissions are normalized and
  written through the Contact-owned `StoreContact` action.
- Creating a Client creates its primary person through the same Contact action.

Canonical Client and Site visibility is determined by `contact_relations`, not by a second
user-facing Client User catalogue.

## Compatibility Bridge

The `client_users` table remains an internal compatibility bridge for domains that still store
legacy Client User IDs, including Tickets, Assets, Sales, Nextcloud, Telephony, Intake, and parts of
Marketing.

Every bridge row links to its canonical Contact through `client_users.contact_id`. Its primary key is
stable and must not be deleted or recycled during ordinary Contact edits, moves, detach operations,
or cleanup. When a Contact leaves a Client context, the bridge is retained inactive with its default
flags cleared. This preserves historical foreign keys and polymorphic references.

New integrations should use Contact IDs and `contact_relations`. A legacy Client User ID should be
requested only when a compatibility consumer still requires it.

## Automatic Production Cutover

The forward-only migration
`2026_09_03_180000_complete_canonical_contact_cutover.php` runs the one-time cutover as part of the
normal production migration. An operator does not need to remember a separate import command.

For every legacy `client_users` row, the cutover:

1. Uses an explicit Contact link first.
2. Uses an existing linked User Contact when available.
3. Reuses only an unambiguous normalized email or phone match.
4. Creates a Contact when no safe match exists.
5. Copies identity and communication fields additively.
6. Creates canonical Client and Site relations.
7. Retains the original bridge row and ID.

It then adds the same canonical Contact ID to downstream records that support dual identity:

- Marketing list members, manual criteria, campaign recipients, campaign events, and durable
  delivery identity evidence;
- Telephony calls;
- Intake submissions;
- Signals with provable legacy identity;
- linked Nexum User accounts.

Tickets, Assets, Sales, Nextcloud, and other legacy-only consumers keep their existing Client User ID
unchanged, so their relations remain valid.

The cutover is idempotent. Ambiguous identity or conflicting Marketing delivery evidence stops the
migration instead of guessing. Work already copied is additive and safe to inspect and rerun after
the conflict is resolved. The cutover does not dispatch a job, call a provider, send email, or replay
a Marketing delivery.

The maintenance command runs the same action for controlled read-back or an idempotent rerun:

```bash
php artisan contacts:migrate-client-users
```

A successful production read-back must show zero legacy rows without `contact_id`.

## Customer Portal Invitations

Contact Settings controls whether an authorized create form selects `Send customer portal
invitation` by default. The installation default is off. A user with
`customer_portal.invite` can override the setting for one create action.

When selected, Contact saves the canonical Contact and Client/Site relations before CustomerPortal
creates a viewer invitation in the same database transaction. CustomerPortal owns active Contact and
Client validation, Site scope, email identity, existing access, replacement of pending invitations,
audit, and queued delivery.

The option is unavailable during ordinary Contact edit. Editing a Contact never resends a portal
invitation. API and legacy compatibility writes do not inherit this UI-only default.

## Contact API

The primary integration surface is under `/api/v1/contacts`.

- `GET /api/v1/contacts` supports broad, exact email, exact phone, status, Client, and Site lookup.
- `POST /api/v1/contacts` creates or safely upserts a Contact.
- `PATCH|PUT /api/v1/contacts/{contact}` updates a known canonical Contact.

Contact writes accept optional Client and Site context and maintain the compatibility bridge when a
legacy consumer requires a Site-scoped Client User ID.

## Ownership Repair API

Trusted repair routes remain available with `contacts.ownership_manage` and support `dry_run`.

- Move and bulk-fix update canonical relations and move or create a stable bridge.
- Detach removes the selected canonical Client/Site relations, but retains linked bridge IDs as
  inactive compatibility evidence.
- Legacy orphan cleanup migrates selected unlinked Client User rows to Contact; it no longer deletes
  them.
- `delete_if_orphan` can soft-delete the canonical Contact only after the ordinary orphan checks.
  Compatibility rows are still retained.

Every repair call records actor, token when available, reason, before state, result, and after state
in the activity log.

## Removal Rule

Do not remove `client_users`, legacy identity columns, or stable bridge rows until every dependent
module has migrated to Contact IDs and a later approved ADR defines the deletion and historical-data
strategy.
