# Feature Slice: Email Mail Taxonomy Classification

Status: Done
Date: 2026-08-12
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex
Human review: `HR-2026-08-12-011`

## Goal

Let technicians classify mail with the existing Taxonomy category and tag definitions while keeping
provider mailbox flags separate.

## User-Visible Behavior

The `/tech/mail` reading pane keeps category and tag controls hidden in normal reading. Users with
mailbox Organize access open **Category and tags** from More actions when a message needs
classification, then select one system category and assign several tags. Existing tags are suggested,
and users with Taxonomy tag-management permission may create new tag names from the Mail form.

Provider flagging remains a mailbox action in More actions. Flagged mail now has a yellow flag icon
and stronger visual styling in the message list and reading pane so it is not confused with category
or tags.

## Scope

- Add Email-owned classification records scoped to account and message.
- Reuse Taxonomy-owned `categories` and `tags` definitions.
- Store multiple tags through the existing `taggables` polymorphic tag system.
- Record a compact classification event for each update.
- Add a Mail workspace category selector, tag input, visible chips, and clear action behind More
  actions.
- Improve visual treatment for provider-flagged mail.

## Out Of Scope

- Full account-scoped conversation classification after the dedicated conversation model exists.
- Bulk classification.
- Category creation from the Mail workspace.
- Provider keyword/label synchronization.
- Ticket-category promotion or automatic copying from Email classification to Ticket classification.

## Data Touched

- New `email_message_classifications`.
- New `email_message_classification_events`.
- Existing `categories`.
- Existing `tags`.
- Existing `taggables`.
- Existing `email_mailbox_placements` provider flag display only.

## Permissions

Viewing classification requires mailbox View through the selected placement. Changing category or
tags requires View plus Organize. Creating unknown tag definitions additionally requires
`taxonomy.manage_tags`; assigning existing active tags does not.

## Tests

- Mail workspace can assign one system category and multiple existing tags.
- Mail workspace refuses classification updates without mailbox Organize access.
- Mail workspace creates unknown tag definitions only for users with Taxonomy tag-management access.

## Documentation

Email Knowledge, TODO, and human-review records are updated.

## Done Criteria

- [x] Flagging has a visible effect beyond a neutral badge.
- [x] Mail category uses existing Taxonomy categories.
- [x] Mail tags use existing Taxonomy tags and support multiple tags.
- [x] Classification is separate from provider flags/folders.
- [x] Focused tests pass on Dev.
