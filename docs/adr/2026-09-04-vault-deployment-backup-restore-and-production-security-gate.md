# ADR 12: Vault Deployment, Backup, Restore, and Production Security Gate

Status: Accepted
Date: 2026-08-30
Decision Makers: Svein Tore / Codex
Related RFC: #279

## Context

Passing application tests is not enough to operate a credential Vault. Correct behavior depends on key-provider configuration, OS permissions, HTTPS, queues, scheduler, backup separation, restore procedures, monitoring, migration state, incident response, and human verification.

A partially configured deployment could appear functional while backups are unrecoverable, scheduled reviews never run, queues retain sensitive data, or legacy credential paths remain silently active.

## Decision

Vault ships dormant and fail-closed. An installation cannot activate real Vault secret storage or migration until an Admin readiness check verifies the required security and operational controls.

Readiness covers:

- supported PHP/libsodium and approved cryptographic suite;
- dedicated Vault Key Provider configured outside the database and APP_KEY;
- provider health and recoverable key version;
- HTTPS/trusted origin and secure session/cookie configuration;
- required 2FA/step-up capabilities;
- Vault permissions and at least one eligible administrator;
- sole-admin versus multi-custodian status and warning;
- secure storage paths and OS/process permissions;
- queue worker and failed-job policy with secret-free payload proof;
- external scheduler runner, not only registered schedule entries;
- encrypted database/attachment backup;
- separately protected key/recovery backup;
- successful isolated restore drill within policy;
- audit storage, retention, time synchronization, and monitoring;
- incident lock and emergency contacts/runbooks;
- no debug, trace, Telescope, analytics, or error path that records secrets;
- inventory and explicit state of every legacy credential source;
- accepted RFC, ADR set, threat model, Feature Slice, and open human-review gate.

Production migration uses staged feature flags and source-specific cutover. There is no global enable switch that silently moves or dual-reads every credential. Each source has preview, stage, verification, read-back, rollback window, monitoring, and separate purge approval.

Deployment must include:

- database migrations and permission seeding;
- key-provider setup without printing keys;
- secure filesystem ownership/modes;
- cache/config rebuild;
- queue restart;
- scheduler verification through the actual OS/Plesk/systemd runner;
- web and worker smoke tests;
- key-provider and backup health read-back;
- negative secret-leakage checks;
- rollback commands that preserve the declared authority and do not restore stale secrets silently.

Operational monitoring distinguishes healthy, degraded, unavailable, locked, recovery-required, migration-conflict, sync-lag, and unknown states. It monitors key provider, queue, scheduler, backup age, restore-test age, review/rotation deadlines, external-vault adapter, failed access, break-glass/recovery, and suspected leakage. Alert payloads contain safe references only.

Backups follow the separate-custody ADR. Restore is performed in isolation first, validates data/key manifests and audit continuity, and keeps Connections, customer publication, Agents, Automations, runtime grants, and external sync disabled until explicitly reauthorized. A restored instance must not contact production providers accidentally.

The production security gate requires:

- all mandatory Feature Slices for the intended release scope completed;
- automated unit, feature, migration, integration, cryptographic, leakage, and recovery tests passing on authoritative Dev;
- independent threat-model and security review;
- penetration testing of the Vault and portal boundaries;
- verified backup and isolated restore;
- source migration rehearsal and rollback proof;
- updated Knowledge and operational runbooks;
- an open human-review checklist completed by a named reviewer;
- explicit approval before the first production credential migration.

Vault is not marketed or declared complete while any RFC completion criterion remains outstanding. Automated tests never mark the human-review entry Reviewed.

## Rationale

Vault failure modes span application, infrastructure, people, and recovery. A visible readiness gate and source-specific activation prevent a green UI from hiding an unusable or unsafe deployment. Restore isolation prevents recovered credentials and Automations from contacting live systems before their state is revalidated.

## Consequences

Positive:

- Installations receive explicit proof of required operational controls.
- Production migration is incremental and reversible within declared limits.
- Scheduler, queue, backup, and key health become observable.
- Restores do not automatically reactivate external actions.
- Completion claims are tied to concrete evidence and human approval.

Negative:

- Vault cannot be enabled casually on an incomplete server.
- Deployment and upgrades require more checks and documentation.
- Independent review and restore drills add cost and time.
- Operations must maintain monitoring, recovery custody, and review cadence.

## Alternatives Considered

- Enable Vault after database migrations only. Rejected because cryptographic and operational dependencies would remain unproven.
- Treat backup success as recovery proof. Rejected because only a restore verifies data and key compatibility.
- Resume every runtime automatically after restore. Rejected because restored authorization and external state may be stale.
- One global credential cutover. Rejected because providers and callers have different verification and rollback risks.
- Let automated tests satisfy production approval. Rejected because security-critical workflows require independent and named human review.

## Follow-Up

- Create the Vault readiness checklist and machine-readable health model.
- Add deployment, rollback, key-provider, backup, restore, and incident runbooks.
- Define the human-review entry before the first implementation handoff.
- Run isolated restore and migration rehearsals before production credentials are accepted.
- Keep completion status visible in the RFC Feature Slice matrix.
