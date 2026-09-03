# Feature Slice: Email Mail Conversation Acknowledgement And Explicit Multi-Account Actions

Status: Implemented On Dev / Runtime Enabled For Named Review
Date: 2026-08-16
Level: 3
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
ADR: `docs/adr/2026-08-11-email-canonical-message-mailbox-placement.md`
Owner: Svein / Codex
Human Review: `HR-2026-08-16-012`

## 2026-09-03 Completion

The accessible boundary is implemented on Dev. Mail now opens a bounded preview panel before marking
one active-account conversation read or unread for the current user, displays provider Seen as a
separate optional effect, and requires explicit confirmation. Versioned API routes support the same
active-account preview plus exact placement selections across authorized accounts, content-free
status, bounded apply/continuation, retry and cancel. The first 25-item apply page runs immediately;
only remaining local work is continued on the default queue. Provider work stays in the
existing remote-operation ledger; status refresh never invents provider acknowledgement.

Focused coverage passes 13 tests / 135 assertions, and the full Email Feature suite passes 715 tests /
7,296 assertions. The environment flag remains false by default; Dev enabled it through the cached
runtime configuration on 2026-09-03 for Svein's named review. No preview, personal-state change or
provider action existed when the review runtime was enabled.

## Goal

Finish honest conversation-wide acknowledgement and explicitly selected multi-account Mail actions.
Every operation previews and freezes the exact currently authorized messages and placements,
reauthorizes each item at apply time, and affects only that snapshot. Opening a conversation remains
non-mutating, future arrivals remain unread, other users remain unchanged, and conversation grouping
never turns separate provider accounts into one implicit mutation target.

## Dependencies

Implementation starts after completion orders 5-9 and 11 are stable. It uses:

- canonical source occurrences only as presentation/correlation evidence, never authorization;
- current access epochs and `EmailUnreadForMeResolver`/`SetEmailUnreadForMe`;
- provider reconciliation, immutable provider-binding versions and remote-operation recovery;
- private invalidation/polling and presence semantics; and
- the final shared-draft/outbound action so an open composer cannot silently retarget after a bulk
  placement change.

The current selected-message command remains the fast default and is not widened by this slice.

## 2026-08-24 Safety Rework

The default-off backend safety boundary is implemented without activating the full user-facing
slice. Forward migration `2026_08_24_140000_create_email_conversation_acknowledgement_action_ledger.php`
adds the approved run/item evidence model and leaves historical inert `150000` unchanged. Preview
freezes an exact active-account conversation or explicit placement selection; apply requires the
same actor and rechecks every mailbox, epoch, message, conversation, placement, folder, UID namespace,
UID, sync version and provider binding. Personal state uses `SetEmailUnreadForMe`; optional provider
Seen is recorded separately through `RecordEmailRemoteOperation` without provider I/O. Personal
success is not rolled back or relabelled when provider work is denied, stale, conflicted or failed.
When one EmailMessage has several active placements, preview deterministically selects the first
placement-ID row for the one account/message/access-epoch/target personal effect and freezes later
rows as non-selected `coalesced` evidence. That selection participates in the immutable item hash;
provider Seen remains independently selected and reserved once per exact placement.

The implicit legacy action now fails closed, the Livewire method cannot bypass preview, and
`EMAIL_MAIL_ACKNOWLEDGEMENT_ENABLED` remains false. During implementation, this repair was verified
against isolated SQLite `:memory:` and an actual socket-only MariaDB 10.11.14 disposable schema,
without shared-Dev migration or provider execution. The forward migration later ran in Dev batch
128; both acknowledgement ledgers remain empty, the gate remains false, and no acknowledgement apply
or provider action ran. The MariaDB contract proves the forward schema, named indexes and foreign
keys, boolean defaults, empty rollback, non-empty evidence refusal, and that the frozen `pending` /
`coalesced` personal statuses and selection values fit and round-trip. Order 8 is not a backend
blocker while invalidation remains its explicit disabled no-op. Order 9 and the complete Order 8
contract still gate shared-draft-safe Archive/Move/Trash expansion, public Livewire/API
preview/confirmation, continuation/retry/cancel and private invalidation behavior. Those unchecked
parts remain required before activation or completion of the full slice.

## User-Visible Behavior

- `Mark conversation read for me` previews all currently visible messages in the selected
  account-scoped conversation, then marks only that frozen set read for the acting user.
- `Mark conversation unread for me` uses the same explicit snapshot. It never changes provider Seen
  and never changes another user's state.
- An actor with Organize may separately choose `also mark provider messages Seen` for the active
  account. The preview labels personal acknowledgement and provider mutation as two different
  effects. Personal success does not fabricate provider success; provider work remains pending,
  acknowledged, reconciled, failed, or conflicted per placement.
- Archive, move, flag, provider Seen/Unseen, Trash, and other existing reversible placement actions
  default to the selected placement or selected conversation in the **active account only**.
- `Across selected accounts` is a distinct control. It first displays every account, folder,
  placement, message count, permission result, provider capability and pending/conflicting operation,
  then requires the user to select accounts/placements and confirm. Nothing inaccessible is counted
  or hinted.
- A partial result is reported per item/account. Retrying reuses durable operation identities and
  never repeats an already acknowledged provider mutation.
- Messages arriving after preview are outside the run and remain `unread for me`. A placement moved,
  deleted, re-correlated, access-revoked, UID-renamespaced, or otherwise changed after preview is
  stale/denied rather than silently substituted.

## Scope Semantics

The explicit scope is always one of:

1. `selected_message`: the existing exact placement behavior;
2. `active_account_conversation`: visible active placements belonging to one durable account
   conversation at preview time; or
3. `explicit_multi_account`: exact user-selected account/conversation/placement members from a
   permission-filtered correlation preview.

Safe canonical correlation may propose additional visible copies but does not select them. A subject,
Message-ID, Ticket link, canonical root, or UI group never grants access or causes a cross-account
action by itself. Each selected placement remains the provider operation identity and each message's
current user-state epoch remains the personal acknowledgement identity.
Duplicate placements for that same message/epoch/target therefore never repeat the personal effect;
they remain separate provider identities and explicit ledger rows.

The preview stores no inaccessible IDs/counts. Account labels and members are added only after the
actor's current ordinary View decision; provider actions additionally require Organize. Personal
owner/direct grant/delegation rules remain as defined by the access slices. Break-glass cannot mutate
personal unread or provider state.

## Additive Data Model

Reserve migrations after order 11, currently `2026_08_16_135000` and `136000`; renumber only if an
earlier dependency occupies them.

Add `email_conversation_action_runs`:

- opaque public UUID, requester, operation, scope kind, active account/conversation/placement,
  selected account IDs, status (`previewed`, `applying`, `applied`, `partial`, `stale`, `cancelled`,
  `failed`), expiry, immutable scope/policy fingerprint and idempotency key;
- preview/applied/denied/stale/personal/provider/pending/conflict counts, safe error code, actor,
  cancellation and timestamps;
- no message body, subject, sender, recipient, attachment name, raw path, credential/canonical ID or
  raw provider exception.

Add `email_conversation_action_items`:

- run, ordinal, account, source message, exact placement, conversation, UID namespace/UID, current
  access epoch, provider-binding version, source/placement sync versions and source fingerprint;
- selected personal/provider effects, target folder where relevant, linked remote-operation ID,
  personal before/after state and status, provider status, safe denial/stale/error reason;
- immutable item fingerprint, tokenized claim/lease and timestamps;
- unique run+placement+message/effect identities so worker redelivery cannot duplicate work.

Preview rows expire after 15 minutes. Apply never rebuilds membership from a live conversation query.
It locks the run and claims the existing items only. A new action requires a new preview, so future
arrivals cannot be accidentally absorbed by an old confirmation.

## Preview, Apply, And Retry

Create shared Email actions for:

- build conversation-action preview;
- query a currently authorized preview/result;
- apply/cancel one run;
- claim/apply one personal acknowledgement item; and
- submit/observe/retry one provider item through the existing remote-operation action.

Preview performs no user-state or provider mutation. It validates bounds, resolves current personal
unread without creating rows, records exact current epoch/source/placement/provider identity, and
shows separate effects.

Apply rechecks actor status, ordinary account access, operation permission, current epoch, exact
message/account/placement/conversation binding, local/provider-missing state, UID namespace, source
fingerprint, provider binding, target folder and active conflicts. Personal state is changed through
the current-epoch action and records the exact prior value. Provider work is first durably reserved
through `RecordEmailRemoteOperation`; apply does not call an ad-hoc IMAP client.

Personal and provider effects are intentionally independent. If personal acknowledgement succeeds
and provider Seen later fails, the run reports `personal_applied_provider_failed`; it does not roll
back the user's deliberate acknowledgement or claim remote success. Retry targets only unresolved
provider items whose existing remote-operation evidence permits retry. It never replays personal
state or an acknowledged remote operation.

## Bounds And Execution

- Default preview cap 100 items; hard cap 500. Fetch cap plus one and fail honestly on overflow.
- Maximum 20 selected accounts per run, with an installation policy allowed to lower both caps.
- Preview and apply queries paginate/order by stable placement ID and never authorize after
  pagination.
- Queue work processes at most 25 items or about ten seconds per claim on the existing Email queue.
  Payloads contain IDs and expected fingerprints/binding versions only.
- Cancellation stops new claims. It does not claim to undo completed personal or provider actions.
- Reordered, duplicate, concurrent, and redelivered jobs use token-owned claims and terminal item
  states. A losing worker cannot overwrite success or cancellation.
- Private invalidations publish only affected account/user projection versions after commit; they
  contain no content or inaccessible account metadata.

## Permissions And API

Personal `for me` acknowledgement requires current ordinary View and a current unread access epoch.
Provider mutations require current ordinary View plus Organize for each exact account/placement and
the underlying operation's existing capability checks. No new broad bulk permission replaces those
decisions. If an installation wants a separate organizational bulk ceiling, add
`email.conversation_bulk_manage` conservatively to Admin/Superuser and still require each mailbox
decision; ordinary conversation acknowledgement remains available without it inside the active
account.

Add request-ceiling API abilities `email.read` for preview/status and `email.update` for apply/cancel,
or introduce narrower catalog abilities only if order 10/11 has established the new names. Account-
bound service tokens may use only explicitly allowed accounts and operation kinds. API and Livewire
call the same actions and return non-enumerating denials.

Add versioned preview/apply/status/cancel endpoints for conversation actions. Resources show only
safe per-account/item status and opaque public run/item identifiers. They never serialize hidden
members, raw provider errors, canonical IDs, private paths, credentials, or another user's personal
state.

## Out Of Scope

- Automatic acknowledgement on open, dwell time, presence, provider Seen, Ticket conversion or AI
  analysis.
- Permanent provider deletion, provider search, bulk arbitrary-query actions, all-mailbox default
  selection, or destructive select-all.
- Changing another user's unread/opened state, deriving personal unread from provider Seen, or
  changing access baselines.
- Implicit action across correlated accounts, Ticket-linked mail, duplicate Message-ID, canonical
  root, or same subject.
- Undo of a whole mixed run. Existing verified per-provider-operation Undo remains available where
  its exact inverse is still safe; a separate explicit personal action may change unread again.

## Data Touched

- New preview/run/item evidence rows.
- Existing current-epoch user state only for selected personal effects.
- Existing provider remote-operation/attempt ledgers and placement projections only for explicitly
  selected provider effects.
- Conversation/list counts and private invalidation versions after committed changes.
- No provider, personal-state, Ticket, Signal, Notification, AI or draft mutation during preview.

## Tests

- Selected-message behavior remains exact and unchanged; active-account conversation preview/apply
  includes current members only; a post-preview arrival remains unread.
- Mark read/unread for me changes only actor/current epoch, works with provider Seen divergence, and
  leaves other users, opened receipts and access baselines unchanged.
- Optional provider Seen is separately labelled/statused, requires Organize, creates one remote
  operation per exact placement, and never fabricates success or replays acknowledged work.
- Two active placements for one message select and execute one immutable personal effect, mark the
  later row coalesced, retain provider work per placement and never turn a selected personal failure
  into false partial/success evidence.
- Active-account default versus explicit two-/many-account selection, unselected accounts unchanged,
  safe correlation without auto-selection, and no inaccessible count/identity leak.
- Revoked/expired delegation, personal account owner, blocked personal direct grant, break-glass,
  disabled actor/account/folder, target-folder mismatch and account-bound API token.
- Snapshot expiry, cap plus one, changed conversation membership, moved/provider-missing placement,
  UIDVALIDITY/source/provider-binding/access-epoch drift, remote-operation conflict and target drift.
- Partial personal/provider results, cancellation, job restart/redelivery, lease races, exact retry,
  existing success reuse, failure evidence sanitization and no whole-run false success.
- Archive/move/flag/Seen/Unseen/Trash active-account and explicit multi-account cases use existing
  action/capability/Undo contracts; permanent delete remains absent.
- Livewire and API parity, pagination, non-enumerating routes/resources, opaque invalidations,
  selected-row stability and mobile/keyboard confirmation UI.
- Migration/down guards, queue/cache/view/OpenAPI, provider reconciliation interaction and affected
  Email/Integration/Notification/Ticket tests.

## Documentation And Operations

Update Email README and Knowledge, API/OpenAPI docs, TODO, completion index and
`docs/human-review.md`. Deployment applies additive migrations with `umask 0002`, clears caches,
rebuilds group-writable views and restarts Email/default workers. It never creates a preview, changes
personal state, or queues a provider operation automatically.

`HR-2026-08-16-012` remains Pending until a named reviewer checks selected-message compatibility,
active-account conversation acknowledgement, future arrival behavior, explicit multi-account
selection/confirmation, personal/provider separation, partial failure/retry/cancel, revoked access,
provider conflict, API scope, mobile/keyboard UI, worker health and sanitized evidence.

## Done Criteria

- [x] The default-off safety rework removes the implicit action and provides a frozen exact-placement
  ledger with same-actor/per-item reauthorization and separate personal/provider outcomes.
- [x] The final access/epoch/provider/draft boundaries are used rather than duplicated; disabled
  Order 8 invalidation remains a safe no-op and this acknowledgement does not retarget shared drafts.
- [x] Preview freezes an exact bounded authorized scope; apply reauthorizes every item and never
  absorbs a future arrival or unselected account.
- [x] Personal and provider effects remain separate, truthful, idempotent and retry-safe through the
  existing actions/ledgers.
- [x] Livewire/API controls, permissions, non-enumeration, races, partial results, invalidations,
  tests, docs and deployment checks are complete.
- [x] `HR-2026-08-16-012` is registered and remains Pending for named human review.
