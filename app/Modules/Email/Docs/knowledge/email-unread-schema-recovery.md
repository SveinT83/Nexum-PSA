Account creation can fail with "The Email unread schema transition is incomplete" when migration `2026_08_16_104000_add_email_unread_access_baselines` is recorded but its table or indexes are absent. The form cannot safely establish a personal owner or shared mailbox access baseline in this state.

## Diagnosis

Inspect `EmailUnreadSchemaState` and the actual table/index definitions as well as `migrate:status`. The migration ledger alone does not prove the schema is complete. Never request mailbox passwords to diagnose this error.

## Recovery

Take a protected snapshot of affected metadata and state, enable application maintenance, and let reserved queue work finish. Deploy and run only the additive migration `2026_09_17_110000_repair_email_unread_access_schema.php`. Do not delete migration ledger entries or replay the original DDL.

The repair restores epoch uniqueness before removing legacy uniqueness, creates the missing baseline table with its foreign keys, and atomically backfills existing access from the original approved migration. Existing nonempty baselines and personal message states are preserved. Lost baselines with advanced state epochs require backup recovery; the migration refuses to infer them.

Read back the schema mode (`epochs`), exact indexes, baseline foreign keys and unchanged state snapshot. Restore normal service and verify the account form. Database repair does not itself authenticate the mailbox or prove IMAP/SMTP connectivity. Rollback of this repair is intentionally a no-op to preserve personal read history.

Review reference: `HR-2026-09-17-EMAIL-SAVE` in `docs/human-review.md`.
