# Managed Web Push Installation And Recovery

Scope: Issue #258; RFC 2026-10-28-managed-webpush-setup; HR-2026-10-28-WEBPUSH-SETUP.
This procedure is for the installation owner. Main availability does not attest a production
rollout, worker cohort, browser registration or actual notification receipt.

## Supported installation boundary

Linux is supported when the exact PHP web/queue runtime account owns a dedicated 0700 private
directory, the same directory and APP_KEY are available to every relevant process, and root owns
the read-only installation metadata outside the application. Normal Admin/API requests cannot
provision this boundary. Do not guess the runtime account from the SSH login. Resolve the actual
PHP-FPM pool/service and queue worker account from the installation configuration first.

The private store contains Laravel-encrypted identities, immutable history, action receipts and
a lock, all mode 0600. Back up the directory and the corresponding APP_KEY through the installation
secret backup mechanism; test recovery without printing either. A database-only backup is insufficient.
Never copy a VAPID private key to UI, chat, shell arguments, ordinary database rows, logs or exports.
There is no recurring attestation expiry unless the operator explicitly chooses --days.

## Deploy and attest

1. Record the named operator, protected application/database/configuration rollback point and
   current Web Push state. Preserve existing stable environment keys and device registrations.
2. Deploy the tested application tree to the same stable installation path/origin. Apply only
   the additive migration using the normal installation account:

   ```sh
   php artisan migrate --path=database/migrations/2026_10_28_030000_create_notification_web_push_setup_mutex.php --force
   ```

   Verify its migration row and mutex row id=1. Do not run unrelated pending migrations.
3. Provision /etc/nexum as root-owned 0755 (or an equivalent root-owned non-writable config
   directory configured at deployment). Provision a dedicated private directory outside the
   public root, for example /var/lib/nexum/webpush, mode 0700 owned by the verified non-root
   runtime account. Parent directories must be traversable by that account. Persist this volume
   through deployments and mount the same store in every relevant web/worker container.
4. Verify the default queue connection/queue against the System runtime-health owner. It must be
   asynchronous and already operator-approved with fresh healthy evidence for queue default.
   Do not widen required/probe allowlists, start workers or release blocked business queues as a
   shortcut. Verify the external minute scheduler independently where scheduled delivery is used.
5. Refresh the normal configuration/route/view caches and Swagger through the deployment's
   supported process. Reload every relevant web/PHP-FPM and queue process using its actual
   service manager. Preserve queue scope and backlog controls. Confirm the loaded application
   revision and fresh System worker evidence for the entire cohort. A single canary is not proof
   that all processes reloaded.
6. Only after the preceding actions have actually been verified, the named root operator records
   the capability. Replace both example values with the verified account/directory:

   ```sh
   php artisan notification:webpush:approve-installation \
     --runtime-user=VERIFIED_RUNTIME_ACCOUNT \
     --private-directory=/var/lib/nexum/webpush \
     --operator="NAMED_INSTALLATION_OPERATOR" \
     --confirm="WORKERS AND WEB RUNTIME RELOADED"
   ```

   Run this from the deployed trusted application with the installation's protected configuration.
   The command records metadata only in /etc/nexum/webpush-managed.json and
   /etc/nexum/webpush-managed.enrolled, root-owned 0644, and reads it back. It does not generate
   keys, activate delivery, rewrite .env or restart a process. Run the Admin read-back as the
   normal web account; a root-only CLI result does not prove the web account can use the volume.
7. Confirm HR-2026-10-28-WEBPUSH-SETUP before production activation. API-managed setup actions
   additionally default off through NOTIFICATION_API_MANAGED_WEBPUSH_EXECUTION_ENABLED;
   enable that gate only for an explicitly intended authorized API client. Existing real-device
   tests retain the independent NOTIFICATION_API_EXTERNAL_TESTS_ENABLED gate.

The capability binds the real application path and configured HTTPS origin. Reconcile and
re-attest after moving either. Changing APP_KEY without the supported secret recovery procedure
makes existing ciphertext unreadable and must not be treated as a reason to generate replacement keys.

## Admin setup, check and activation

1. With notification.manage_channels open Admin > Notification channels > Web Push >
   Setup and activation. Unsupported installations show an installation requirement.
2. Choose a stable mailto: or HTTPS contact subject. Confirm Configure with delivery disabled.
   Valid complete environment keys are retained without disclosure. Partial, invalid, PEM-owned
   or unexplained legacy registrations require the installation owner's reconciliation.
   A previously enabled environment identity is paused by explicitly adopting disabled setup.
3. Select Run readiness check. The server validates its fixed trusted HTTPS /sw.js
   without redirects or TLS bypass, then submits a bounded no-message canary only to the
   already-approved default queue. Refresh until exact current worker evidence is verified.
   A login page, dispatch acceptance, stale result, revoked actor or changed queue is not readiness.
4. Review the safe statuses and explicitly enable delivery. The store is read at each send;
   the application does not put a private key into configuration caches. Disabling retains the
   identity, encrypted history and registrations.
5. Separately authorize an owned browser/device through Profile > Notifications > Enable on
   this device, then Send test to this device. Verify actual receipt with that user. Admin setup
   never grants browser consent or enables business-event preferences.

## Failure, rotation and rollback

- Insecure/untrusted HTTPS: fix the actual hostname/certificate chain; never bypass validation.
- Missing/different service worker: deploy the shared public/sw.js and verify normal HTTPS bytes,
  MIME type and push/notificationclick handlers. An HTTP 200 login page is a failure.
- Unknown/stale/blocked queue: ask the System/runtime owner to restore the approved exact scope.
  Do not restart or release unrelated queues from the wizard.
- Protected store unavailable: keep delivery disabled, inspect ownership/mount/APP_KEY and restore
  the exact encrypted backup. Do not delete the enrollment marker to force environment fallback.
- Rotation requires ROTATE WEB PUSH; restoration requires ROLL BACK WEB PUSH and an exact encrypted
  historical revision. Both disable first, audit registration removal and publish a disabled identity.
  Users must explicitly subscribe again. Environment-owned keys are never silently rotated.
- A partial cleanup/publication remains disabled with a visible pending kind/target. Reconcile the
  cause and resume that exact intent; do not start a different operation or blindly repeat an unknown
  API command. A completed identical action returns its receipt without deleting newer registrations.
- To disable urgently, use the guarded Admin disable action. An installation owner may revoke the
  capability while retaining enrollment, which makes managed operation unavailable without falling
  back to legacy keys. WEBPUSH_FORCE_DISABLED is an independent upper bound and needs the normal
  supported configuration reload wherever deployment configuration is cached.
- Before code rollback, disable delivery, preserve encrypted store/history, retain enrollment and
  prevent old workers from sending legacy-key notifications. Keep the additive mutex table.
  Its automatic down migration deliberately refuses unsafe removal. Remove it only in a separately
  reviewed retirement after all managed readers/writers and device registration are stopped.
  Returning to old code alone is not a safe identity rollback.

## Verification evidence

Use temporary protected fixtures for automated identity/store/lifecycle tests and synthetic,
rollback-only MySQL rows for database-boundary verification. Tests never attest the live worker
cohort or generate/rotate the installation's actual keys. Record Dev/Main/production separately.
Keep human review Pending until a named operator/user confirms the concrete runtime/device checks.
