# ADR: Installation-Owned Managed Web Push Identity

Status: Accepted for authorized implementation; live operator attestation remains separate
Date: 2026-10-28
Owner: Codex / Svein Tore
Related: #258; RFC 2026-10-28-managed-webpush-setup

## Decision

Use an operator-provisioned root-owned installation capability descriptor plus an application-
owned 0700 private directory outside the web root. Persist VAPID identities/history as atomic
0600 Laravel-encrypted artifacts under the existing installation APP_KEY. Normal UI/API never
accepts or reveals a private key, path or command, and never edits .env. Unsupported installations
receive a concrete deployment requirement and cannot activate. Preserve existing stable
environment identities; incomplete legacy configuration blocks replacement rather than rotating.

Current effective switch/identity is read at the final send boundary through a fresh Notification
transport adapter. An emergency operator force-off is an upper bound. Reuse System-owned exact
queue health read-only and a no-message worker canary for the current identity/runtime contract.
An operator attests the whole worker deployment; one probe does not prove every worker restarted.

Setup is disabled initially. Enable requires fresh security/runtime evidence, current native
rights, exact revision and explicit confirmation. Rotation/rollback are separate strongly
confirmed, disable-first operations with retained encrypted history and audited registration
revocation. Failures remain disabled/unknown; action identity prevents duplicate rotation.
Publishing keys to browser history, ordinary DB/audit/session/queue records or exports is forbidden.

## Alternatives And Consequences

Plaintext .env rewrites and ordinary DB key fields are rejected. A general Vault/Connection lease
is not introduced for a local server VAPID identity. A future installation KMS adapter can replace
this profile behind the same use-without-reveal contract, without changing user/device consent.
Installation backups must protect APP_KEY and the encrypted private store together. Existing
environment-only behavior is unchanged until an explicit managed initialization. Production
attestation, activation and browser proof remain in HR-2026-10-28-WEBPUSH-SETUP.
