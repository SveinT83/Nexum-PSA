Data Exchange is the shared Nexum platform for reusable import and export profiles.

The module owns profile configuration, run history, generated files, audit events, and retention.
Other modules own the business data they expose to Data Exchange.

Current behavior:

- Admins can open Data Exchange from Admin.
- Admins with `data_exchange.manage` can create and edit profiles in the Livewire Data Builder.
- Export profiles can generate CSV, JSON, and XLSX files.
- Generated files are stored in protected local storage with run history, checksums, audit events,
  and retention dates.
- Import profiles can parse CSV, JSON, and XLSX files, run a dry-run preview, show row-level
  validation errors, and commit only through module-approved import targets.
- Schedules can queue due export runs through `data-exchange:run-due`.
- Delivery targets copy generated files to configured Laravel filesystem disks. FTP/SFTP
  credentials remain outside Data Exchange and are referenced by disk or credential reference only.
- API clients can list profiles, trigger runs, read run status, download generated files, preview
  imports, and commit approved previews when their token has the matching `data_exchange.*` ability.

Security boundaries:

- Password hashes, remember tokens, two-factor secrets, API tokens, encrypted credentials, private
  keys, webhook secrets, and similar fields are permanently blocked.
- Import writes must go through module-approved import targets.
- API access uses explicit `data_exchange.*` abilities.
- Raw SQL is not supported in v1.

Implemented default profiles:

1. Economy Orders Export: line-based order export for ready and approved Economy orders.
2. Clients Basic Import: client identity and billing fields with default site/contact creation for
   new clients.

Operational notes:

- The Laravel Scheduler must run every minute so `data-exchange:run-due` can queue due schedules.
- Delivery targets should reference preconfigured filesystem disks such as an Integration-owned FTP
  or SFTP disk. Do not paste secrets into Data Exchange fields.
- Import previews with invalid rows cannot be committed. Upload a corrected file and run a new
  preview first.

## Economy operational API on Dev (25 October 2026)

Economy adds a scoped preview/command/run facade over this existing file-export runtime on Dev,
under HR-2026-10-25-ECONOMY-API (Reviewed by Svein Tore, 25 October 2026), merged to Main
in [PR #315](https://github.com/SveinT83/Nexum-PSA/pull/315). Production deployment is not verified.

1. GET /api/v1/economy/exports/preview with economy.export and data_exchange.run scopes and current
   economy.view/data_exchange.run permissions. Inspect the canonical Economy Orders profile,
   format, row count and preview_hash. Preview does not create or repair profiles.
2. POST /api/v1/economy/exports with that preview_hash and a fresh Idempotency-Key. An existing
   configured profile/filter is preserved. If missing, the existing default template is created
   only by this explicit command. Default statuses are ready/approved.
3. Read the actual run/file/checksum. A failed run remains a failed result and an identical retry
   replays it. Download the returned canonical Data Exchange file URL with current download and
   Economy read/view authority. Revoked Economy access also blocks direct owner run/file calls.
4. Treat the output as a file for billing handoff. It creates no provider invoice, changes no
   Economy order to exported and dispatches no external delivery. Configured delivery targets are
   disclosed but not activated by this command.

The canonical Economy profile POST /api/v1/data-exchange/profiles/{profile}/runs shares the same
preview/key/replay contract. Other Data Exchange sources retain their owner workflows. Generic
profile discovery hides Economy profiles from users without current Economy visibility.

## Full DataExchange workflows on Dev (25 October 2026)

These workflows are implemented on Dev under HR-2026-10-25-DATA-EXCHANGE-API. Human review,
Main promotion and production deployment remain separate. Do not assume production availability.

### Create or edit a profile

Prerequisites: Data Exchange management permission and permission to the business source.
API users additionally need matching explicit token scopes.

1. Open Admin > System > Data Exchange and choose Create profile or edit an existing profile.
2. Set Name, Key, Status, Direction and Format; choose Source or target.
3. Select safe Fields, Output key/Label and supported Filters.
4. Save. Configuration saves together; stale editors must reload. Paused profiles keep history
   but cannot start new import/export execution.
5. API users follow context/sources and profiles create/detail/update. Supply the returned
   revision in If-Match for edits and a fresh Idempotency-Key. See API Management.

### Export and inspect a file

1. Choose an active export profile and run it.
2. Open the run; inspect status and generated format, size and checksum.
3. Download the exact file. A changed checksum is refused.
4. API users first GET profiles/{profile}/runs/preview, then POST profiles/{profile}/runs with
   preview_hash and a new Idempotency-Key. An identical retry returns the same run/file.
5. A private file does not prove a provider import, invoice or delivery. Failed status remains
   failed and is not silently rerun.

### Review and commit an import

1. Choose an active import profile and upload CSV, JSON or XLSX using Preview import.
   Limits: 10 MiB / 10000 rows.
2. Inspect normalized values and row errors. Fix invalid rows with a new upload/preview.
3. Choose Commit import only after review. Profile/mapping, target data or provider-authority
   changes require a new preview. Repeated commits cannot apply domain writes twice.
4. API users GET imports/{preview} for bounded rows; commit needs the reviewed revision in
   If-Match and a fresh Idempotency-Key.
5. When Tripletex owns customer creation, create/link new Clients through the Client form/API.

### Schedules and delivery

Prerequisites: schedule/delivery permission, active export profile and administrator-approved
Integration filesystem/credential references. Ask the administrator when the reference list is
empty. Never paste a password, token or private key into Data Exchange.

1. In Data Exchange > Schedules, choose export profile, frequency, time and optional target.
   Times use the installation timezone; API weekdays are ISO Monday=1 through Sunday=7.
2. Save active only when ready; pause when execution is unwanted. The approving operator must
   remain active and retain source/run/schedule/delivery rights.
3. Automatic import pickup is unavailable. Use Preview import / Commit import for manual files.
4. Targets use approved configured filesystems. API changes use exact details and revisions.
5. API delivery has a separate default-off installation switch. Preview/inspection do not enable
   it. Existing approved schedule delivery shares verified transport checks.
6. Read each delivery attempt. succeeded with verified=true means exact destination bytes matched.
   queued/writing mean incomplete; unknown means completion cannot yet be proven.
7. Reconcile unknown using its frozen destination. Do not resend blindly. Retry only a failed
   attempt with safe_to_retry=true, the original target, fresh preview and new command key.
8. A live queue or schedule:list alone does not prove scheduled health. The installation needs
   an external every-minute scheduler runner and working queue processing.

### Troubleshooting

- 403: verify active actor, matching token scope, Data Exchange permission and source/Economy rights.
- 409: reread/review changed configuration, data or preview. Reuse keys only for identical retries.
- 428: provide the exact record's current revision.
- 422: inspect field/row errors, approved disk references and supported capabilities.
- 503 for API delivery: the switch remains off or required setup is unavailable.
- Failed or unknown delivery is incomplete. Inspect/reconcile before a permitted retry.
