# Vault Architecture Threat Model

Status: Accepted for implementation planning
Date: 2026-09-04
Scope: Discussion #279 and the planned singular Vault domain
Reviewed source: Authoritative Dev `/var/Projects/tdPSA`, branch `Dev`, HEAD `95846c1d34013eca8dec752c6f89535e689eab52`

## Overview

The planned Vault domain is not implemented in the reviewed source. Nexum currently distributes
secret material across Email, Integration, AI, RMM, BookStack, CloudFactory, Nextcloud,
Relationship, Notification, Telephony, and environment-backed runtime configuration.

Vault will centralize Nexum-managed secret material and its cryptographic lifecycle without taking
ownership of connector endpoints, provider behavior, Client/Site business records, or the source
domain's authorization rules. Reveal and bounded use-without-reveal are separate capabilities.

This document is an architecture threat model, not a vulnerability report. Missing Vault controls
are required implementation work until a concrete Feature Slice and its attack paths are verified.

## Assets

- Plaintext secret material during an authorized operation.
- Encrypted secret payloads, immutable versions, attachments, and import/export packages.
- Installation seal key, wrapping keys, data-encryption keys, key references, and rotation state.
- Item ownership, Client/Site association, grants, publication, approval, expiry, and revocation.
- Opaque references and exact-version bindings used by consumers and queued jobs.
- Metadata-only audit events and security/incident evidence.
- Recovery material, backup manifests, restore evidence, and destruction tombstones.
- Protected search representations and their separately governed keys.

## Trust Boundaries

1. Unauthenticated browser or API traffic to Fortify sessions, public invitation/webhook routes,
   and Sanctum-protected APIs.
2. Authenticated internal users to route permissions, domain authorization, and future object-level
   Vault grants.
3. Authenticated customer-portal users to active membership, Client/Site scope, and future explicit
   Vault publication grants.
4. Web or command execution to database rows containing ciphertext, grants, key metadata, and
   audit evidence.
5. Controllers and services to queues, serialized jobs, failed-job storage, and later workers.
6. Owning-domain consumers to current direct decryption or future bounded Vault use.
7. Connector runtimes to DNS, approved endpoints, TLS peers, and provider responses.
8. Application processes to private storage and operating-system identities.
9. Current Laravel Crypt consumers to `APP_KEY`; future Vault ciphertext to a separately governed
   key-provider boundary.
10. Ordinary administrators to future reveal, export, recovery, key management, and break-glass
    workflows.

The intended runtime boundary is:

```text
Owning domain / Integration endpoint policy
        | opaque item/version + exact purpose and target
        v
Vault authorization and secret-use boundary
  actor + consumer + scope + grant + version + expiry + approval
        | plaintext only in short-lived nonserializable memory
        v
Exact domain adapter / approved provider endpoint
```

## Attacker Capabilities

- An unauthenticated actor can submit arbitrary identifiers and public-route input within current
  validation and throttling limits.
- An authenticated portal user can manipulate route identifiers and membership-scoped input.
- An authenticated technician or API token can be valid while lacking one or more Client, Site,
  record, item, action, or secret grants.
- An Integration administrator may influence endpoint configuration or the requested credential.
- A queue, failed-job, database, backup, or private-file reader may inspect stored material without
  controlling every key provider.
- An external provider or DNS-influencing attacker may return hostile content or redirect traffic.
- An application-host compromise can observe authorized runtime plaintext. Vault reduces blast
  radius and persistence but cannot make an actively compromised trusted runtime harmless.

## Security Objectives

- Keep plaintext out of databases, queues, failed jobs, logs, sessions, caches, notifications,
  URLs, search indexes, prompts, chat, Memory, audit payloads, and reusable workflows.
- Deny by default and authorize each action against actor/workload, Client/Site, record, item,
  exact version, purpose, target, expiry, session freshness, and approval.
- Separate reveal/copy from bounded use-without-reveal.
- Require explicit portal publication; membership or record access must never imply secret access.
- Make versions immutable, bind jobs and approvals to exact versions/digests, and enforce
  revocation again at execution time.
- Keep ciphertext and wrapped per-version keys in a one-to-one encrypted-material record so a
  later policy-approved destruction can erase both without deleting the immutable version
  tombstone; database guards must make that transition irreversible.
- Use per-version data keys and separately governed, rotatable wrapping keys rather than `APP_KEY`.
- Store safe append-only audit facts without values or reusable authentication material.
- Keep endpoint choice, SSRF controls, DNS/IP checks, and TLS validation in Integration or the
  owning connector domain.
- Encrypt Vault attachments independently and prevent fallback to ordinary file storage.
- Make backup, restore, key rotation, compromise response, break-glass, and destruction testable.
- Use blind indexes only for explicitly approved exact-match fields with separate keying.

## Existing Enforcement And Gaps

| Resource | Existing enforcement | Vault implication |
| --- | --- | --- |
| Internal authentication | Fortify/session auth, active-user checks, role-based 2FA enforcement | Confirmed 2FA setup is not fresh Vault step-up; add a separate short-lived action-bound state. |
| Tech authorization | Route permission mapping with Superuser and legacy Admin compatibility | Coarse entry checks are necessary but insufficient; high-risk Vault actions require explicit object grants and no implicit fallback. |
| Customer Portal | Active account/contact/membership and Client/Site scope | Add a non-enumerating Vault publication grant; do not reuse ordinary document visibility. |
| Current encryption | Laravel Crypt/encrypted casts under `APP_KEY` | Build an independent envelope/key-provider boundary before moving any source. |
| Email credentials | Account-owned runtime with endpoint and exact binding checks | Migrate actual Email accounts last; the retired Integration provider lifecycle is a reusable safety pattern, not current ownership. |
| Queue jobs | Most credential jobs carry IDs and resolve at runtime | Require opaque exact-version/grant references and nonserializable runtime material. Do not copy the raw portal invitation-token exception. |
| Private files | Path containment, OS modes, controller authorization, no-store downloads | Vault attachments still need application encryption, quarantine, scanning, and cryptographic destruction. |
| Audit | Email provider events have database update/delete prevention | Apply append-only database enforcement to Vault audit; other general audit tables are not sufficient. |
| API/MCP | Sanctum abilities exist | No general Vault reveal ability; metadata and bounded actions require exact scopes and the same policy service as UI/jobs. |

## Attacker Stories And Required Mitigations

| Attacker story | Required mitigation |
| --- | --- |
| Employee invokes a Vault action without the exact grant | Separate metadata, create, use, reveal, publish, export, recovery, key, and break-glass permissions plus item/collection grants. |
| Portal user substitutes another Client/Site/item ID | Lookup only through an explicit membership-bound publication grant with expiry and revocation. |
| Queue or failed-job reader extracts a credential | Serialize only opaque reference, exact version, grant/execution digest, and safe correlation ID; reauthorize immediately before use. |
| Database reader obtains all ciphertext | Per-version data keys wrapped by Client/company-scoped keys held by a provider outside the database and `APP_KEY`. |
| Agent/Tool receives plaintext while it only needs to perform an action | Make use-without-reveal the default and keep plaintext outside plans, prompts, approvals, chat, and Memory. |
| Credential is sent to an attacker-controlled endpoint | The owning connector supplies an already authorized target identity; Vault never accepts an arbitrary URL with a secret reference. |
| Publication/export/recovery uses the wrong version or audience | Preview/approve/apply/read-back bound to an immutable plan digest, exact version, exact audience, reason, and expiry. |
| Migration leaves a stale legacy reader active | Maintain a source/caller inventory, explicit authority state, read-back, rollback window, observation, and separate purge approval. |
| Break-glass becomes routine Admin reveal | Separate emergency permission, strongest step-up, independent approval when possible, narrow duration/scope, notification, and post-event review. |
| Search leaks sensitive values | Strict allowlist, separate keyed exact-match indexes, rate/audit, no fuzzy/substring or low-entropy secret indexing. |

## Assumptions And Unresolved Deployment Evidence

- Deployment values for queue/session drivers, reverse proxy, backups, key custody, worker isolation,
  database privileges, and operating-system permissions were not read in this source review.
- No external KMS/HSM, Vaultwarden transport, malware scanner, protected-search implementation,
  Connection Broker, or Vault module exists in the inspected source.
- Superuser/Admin compatibility behavior remains an explicit design risk for high-risk Vault
  actions and must not be inherited silently.
- Production readiness requires independent security review, penetration testing, backup/restore
  proof, and named human review; automated tests cannot replace those gates.

## Severity Calibration

When validating future implementation findings:

- **Critical:** reliable cross-tenant or unauthenticated mass secret disclosure; key compromise that
  exposes all Vault content; unauthenticated export/recovery; or a production backdoor.
- **High:** authenticated cross-Client reveal/use; bypass of step-up/approval for broad export,
  recovery, or key operations; plaintext persistence in ordinary queues/logs/backups; or endpoint
  substitution that sends a credential to an attacker.
- **Medium:** narrower authorization bypass with meaningful secret metadata or one-item exposure,
  significant audit/revocation failure, or a protected-index leak requiring a valid internal actor.
- **Low:** limited safe-metadata leakage, hardening weakness, or operational visibility gap with no
  demonstrated path to secret disclosure or unauthorized use.

## Required First Security Slice

The first code slice is a runtime-disabled control-plane and cryptographic foundation. It must
establish deny-by-default permissions, opaque IDs, immutable versions, independent envelope
encryption, metadata-only append-only audit, and nonserializable runtime material. Reveal, portal,
migration, import/export, attachments, search, break-glass, and production activation remain off.

## Core Source Evidence

- Authentication: `config/auth.php:16-19,38-43,62-66`; `config/fortify.php:18,48-50,104,117-120,146-156`
- Internal access: `routes/tech.php:18-22`; `app/Http/Middleware/TechAccess.php:14-36`; `app/Http/Middleware/AdminAccess.php:14-37`; `app/Http/Middleware/EnforceTechRoutePermission.php:146-172,430-456,481-499`
- API: `routes/api.php:23-41`; `app/Modules/Integration/Controllers/Admin/ApiController.php:59-76`
- Portal: `app/Modules/CustomerPortal/routes.php:11-55`
- Application encryption: `config/app.php:141-148`; `bootstrap/app.php:34-77`
- Integration/AI stores: `app/Models/System/Integrations/Integration.php:18-72`; `app/Modules/Integration/Models/AiProvider.php:16-68`
- Email credential lifecycle patterns: `app/Modules/Integration/Services/EmailProviderCredentialCipher.php:15-62`; `app/Modules/Integration/Actions/StageEmailProviderCredential.php:29-76,87-136`; `app/Modules/Integration/Actions/ActivateEmailProviderCredential.php:25-108`; `app/Modules/Integration/Actions/RevokeEmailProviderCredential.php:25-90`
- Queue configuration: `config/queue.php:16,37-44,55-73,106-110`
- Private storage: `config/filesystems.php:16,33-61`; `app/Modules/Email/Services/EmailPrivateStorage.php:19-147`
- Append-only audit reference: `app/Modules/Integration/Services/EmailProviderEventRecorder.php:13-38`; `database/migrations/2026_08_16_114000_create_integration_email_provider_events.php:12-35,51-83`
