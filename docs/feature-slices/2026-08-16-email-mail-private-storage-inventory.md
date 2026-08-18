# Feature Slice: Email Mail Private Storage Inventory

Status: Done
Date: 2026-08-16
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex
Human review: `HR-2026-08-15-003`

## Purpose

Provide one bounded, read-only operator inventory for private Email files before any retention or
deletion decision. The inventory reconciles the private `email/*` tree with every current Email
database reference, identifies missing references and unreferenced files, groups byte-identical
unreferenced files, and reports permission or containment violations without changing either files
or database rows.

This closes the observability part of the existing private-storage workstream. It does not authorize
deletion, retention overrides, provider reads, recovery, ownership changes, or permission changes.

## Contract

- `email:inventory-private-storage` is read-only and defaults to redacted stable path identifiers.
- `--show-paths` is an explicit operator-only diagnostic that may print private relative paths; it
  never prints file contents.
- The command scans only the configured private local disk's canonical `email/*` root, rejects
  symlinks and containment escapes, and has a hard file-count cap.
- References include inbound raw snapshots, inbound attachment rows, durable composer attachment
  rows, and outstanding Sent reconciliation snapshots.
- Each regular file is classified as referenced or unreferenced and reports scope, byte count,
  modification time, mode, group, and SHA-1 checksum. Missing database references are reported
  separately.
- Duplicate groups are checksum-and-size evidence only. They do not prove that a file is safe to
  delete because Ticket, backup, retention, legal-hold, or historic recovery context may still
  require it.
- A truncated scan, unsafe path, symlink, missing referenced file, unreadable file, or non-private
  mode makes the command return failure. Unreferenced files alone are an honest inventory result and
  do not mutate the exit status.

## Out Of Scope

- File deletion, moving, quarantine, chmod/chown/setfacl, provider access, or database mutation.
- Automatic retention decisions or treating checksum duplicates as disposable.
- Browser/API exposure of paths, names, hashes, or private storage metadata.

## Verification

- Focused `EmailPrivateStorageInventoryTest` passes **3 tests / 21 assertions**. Coverage includes all
  reference sources, redacted and explicit path output, duplicate grouping, missing references,
  scan limits, and proof that neither files nor database rows change.
- The first live Dev run used the default redacted output and inspected **939 files** without mutation:
  - `sent_pending`: 322 total, 0 referenced, 322 unreferenced;
  - `raw`: 547 total, 465 referenced, 82 unreferenced;
  - `attachments`: 70 total, 34 referenced, 36 unreferenced;
  - total: 499 referenced and 440 unreferenced files;
  - 28 missing `message_raw` database references;
  - 79 non-private legacy files at mode `0644`;
  - 12 duplicate unreferenced checksum-and-size groups.
- The command changed no file, permission, database row, provider, queue, or retention state. Its
  failure result is expected while missing references and non-private modes remain; it did not
  authorize deletion or mode repair.
- The preceding structural audit found zero symlinks, unsafe paths, or unreadable files and verified
  61 `www-data` directories at mode `2770` with group-rwx access/default ACLs. A root/operator must
  still normalize only the 79 identified `0644` files to `0660`, without content/ownership/move/delete,
  then rerun this inventory and the PHP-FPM/queue dual-runtime smoke under `HR-2026-08-15-003`.

## Done Criteria

- [x] The command is bounded, read-only, path-redacted by default, and fails closed on incomplete or
  unsafe evidence.
- [x] Every current Email private-storage reference source and unreferenced file scope is inventoried.
- [x] Duplicate groups remain evidence only and never become automatic deletion decisions.
- [x] Focused tests and the first live redacted Dev inventory pass the documented no-mutation contract.
- [x] Remaining mode, missing-reference, retention, deletion, and dual-runtime work stays explicit in
  `docs/TODO.md` and Pending human review `HR-2026-08-15-003`.
