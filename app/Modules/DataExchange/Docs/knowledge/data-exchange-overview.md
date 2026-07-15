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
