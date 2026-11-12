# Main to Dev history synchronization - 2026-11-12

Status: Done / Remote And Local Dev Synchronized / Pending Work Preserved. Owner: Codex.
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

## Completed synchronization

Sync commit1af8e4413dca40873c212ad23425e1b1c47dba68 was pushed normally to Dev,
without force. Remote Dev8f9b86cd advanced to that descendant of Main d093f34c.
The authoritative local Dev ref advanced from36918edb to the same commit with a
compare-and-swap ref update and atomic prepared-index installation under index lock.

All5917 original non-ignored paths were checked against the private snapshot;
only the five intentionally owned instruction/coordination documents changed.
Application source bytes remained unchanged. Both original staged blobs and their
modes are identical to the pre-sync index. Their contents were not committed/pushed.
The partially staged Storage draft remains a pending staged version, not approved
current code; its owner must inspect staging before a future unrelated commit.

Independent fetch/read-back confirms origin/main is an ancestor of origin/Dev:
Main-only commits0. Local Dev and remote Dev match at the synchronization step.
The final evidence-only follow-up commit advances Dev without changing runtime code.
Dev HTTPS login200; required Dev matrix105 tests/42769 assertions passed.

No Main ref, production source, migration, customer/provider/work data, queue,
scheduler, live setting or GitHub branch-protection configuration changed.
Prior human-review entries retain their original status and gates. Maintenance
scope was explicitly approved by Svein Tore; no product scenario execution is inferred.
