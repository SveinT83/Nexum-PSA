# Feature Slice: Email Presence, Shared Draft Locks, and Stale-Composer Protection

Status: Rework Needed / Migration Gated
Date: 2026-08-19
Parent: `docs/plans/2026-08-16-email-mail-completion-slice-index.md` (Order 9)
Review ID: `HR-2026-08-16-009`

2026-08-21 audit: the original `140000` schema and its service did not implement the approved shared
scope, durable fencing/audit or fail-closed send boundary. The migration is now an inert deploy
marker, ran in Dev batch 108, and created no `email_mail_draft_locks` table. Collaboration defaults
off independently through `EMAIL_MAIL_COLLABORATION_ENABLED=false`; ordinary Reply, Reply All and
Forward remain available without the table. Per-user whispers do not reach coworkers. Do not
activate this slice until it is redesigned, tested against the original 2026-08-16 approved slice,
and delivered through a new forward migration.

## Purpose

This slice implements real-time coordination for multiple users working in the same Email Mail workspace. It prevents concurrent editing of the same draft, warns when someone else is reading/typing, and protects against submitting stale content.

## Scope

- **Presence:** Real-time "Who is reading this conversation" and "Who is typing a reply" indicators.
- **Shared Draft Locks:** Durable (but expiring) locks when a user starts composing a reply to a conversation.
- **Stale-Composer Protection:** Preventing submission if the underlying conversation or draft has changed since the composer was opened.
- **Privacy:** Presence is limited to the private user coordination channel; no permanent heartbeat content in the database.

## Technical Design

### Presence (Expiring coordination)
- Use Reverb/Echo `whisper` or specialized presence events.
- Since we want to avoid unnecessary backend load, simple "typing" indicators can be client-to-client via Echo whispers.
- "Reading" indicators will be handled via a similar mechanism or a lightweight periodic ping.

### Shared Draft Locks (Backend)
- A new table `email_mail_draft_locks` or using Cache with a specific tag.
- Given the requirement for "durable fencing" and "execution-time reauthorization", a database table is preferred for auditability and strict enforcement during the `send` action.
- Lock duration: 5 minutes, auto-renewed by the active composer.

### Stale-Composer Protection
- The composer will track the `updated_at` or a version hash of the conversation/draft when opened.
- On submit, the backend verifies the version hasn't changed.

## Implementation Plan

1. **Database Migration:** Create `email_mail_draft_locks` table.
2. **Backend Service:** `EmailPresenceService` to manage locks and presence state.
3. **Broadcasting:** Define presence channels and events (if not using whispers).
4. **Livewire Integration:** 
   - Update `MailComposer` to acquire/release locks.
   - Update `MailWorkspace` to display presence indicators.
5. **Frontend (JS):** Update `EmailMailLive` to handle typing whispers and presence sync.

## Boundary & Risks

- **Risk:** Abandoned locks if the browser crashes.
- **Mitigation:** Short TTL (5 mins) and periodic pings.
- **Boundary:** Expiring coordination only; no permanent heartbeat content.
