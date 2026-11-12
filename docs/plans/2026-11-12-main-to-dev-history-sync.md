# Main to Dev history synchronization - 2026-11-12

Status: Validated / History Publication In Progress. Owner: Codex.
Explicit user request: synchronize Main back to Dev, preserve ongoing work and
use mandatory Dev-first publication. No Main or production change is requested.

## Initial evidence

Remote Dev8f9b86cd is258 commits behind Maind093f34c. Local Dev36918edb is four
commits ahead of remote Dev, and all four are already ancestors of Main. Therefore
both Dev refs can advance without force-push, rebase or lost committed history.

The inspected Main-vs-remote-Dev delta has2347 paths. In the actual Dev working copy,
2162 are byte-identical to Main and185 differ; no relevant Main file is absent.
Among runtime paths,1413 match Main and38 differ. These pending source differences
are retained, not silently committed or overwritten. The recent attribution feature
has37 exact runtime files and one Ticket view with additional pending Task preview UI.

The Main first-parent log shows many PR merges from codex branches. That does not
prove direct pushes by colleagues or identify who used a shared GitHub account.
Codex's own PR403/404 followed a Main-based candidate without first publishing its
commits to Dev; that contributed to the history gap. No blame is inferred from
author names.

## Preservation and rollback

Private backup: /var/Projects/tdPSA-sync-backups/20261112-main-to-dev, mode0700.
5917 non-ignored source paths are fingerprinted; complete source archive is present,
with original Git index, staged/unstaged binary patches and status snapshot.
Backup Git ref: codex/backup-dev-before-main-sync-20261112 at36918edb.
The two original staged file blobs must remain staged and must not be included in
this synchronization commit:
- allocate_purchase_order_number_handler.php
- app/Modules/Storage/Actions/AllocatePurchaseOrderNumber.php

The second is a partially staged old addition whose current working file differs.
Preserve its exact staged blob and current file independently; do not publish the
old staged content or reinterpret it as approved work. Its relative diff changes
when HEAD advances, so its owner must review staging before a later commit.

Only the owned instructions, TODO/review entry and this report are intentionally
edited. Application source bytes must remain exactly unchanged. Ref/index changes
use compare-and-swap checks and a Git index lock; do not use reset --hard or stash
and blindly reapply thousands of overlapping Main-derived files.

## Process correction

AGENTS.md and ai-team-process.md require commit/test/push to Dev before ordinary
Main promotion, including documentation/release records. A merge/deploy instruction
does not implicitly authorize Main-first. Named emergency exceptions must be
followed by immediate Main-to-Dev synchronization. No GitHub repository permission,
ruleset or branch-protection setting is changed in this maintenance operation.

## Verification and outcome

Required current-Dev matrix:105 passed /42769 assertions in198.49 seconds.
Application source hashes match the protected pre-sync snapshot. Ref/index and
remote ancestry read-back are the remaining publication steps.
No provider/customer/work data, database schema, queues, schedulers or production
source is changed. Existing feature review gates remain independent.
HR-2026-11-12-MAIN-DEV-HISTORY-SYNC records Svein's reviewed maintenance plan.
