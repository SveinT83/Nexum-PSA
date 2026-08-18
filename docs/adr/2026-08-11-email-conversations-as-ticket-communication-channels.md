# ADR: Email Conversations As Ticket Communication Channels

Status: Accepted
Date: 2026-08-11
Decision Makers: Svein / Codex
Related RFC: [Mail Module Full Email Client](../rfc/2026-07-04-mail-module-full-email-client.md)
Related ADRs:

- [Email Owns The Full Mail Client Domain](2026-08-11-email-owned-mail-client-domain.md)
- [Canonical Message And Mailbox Placement](2026-08-11-email-canonical-message-mailbox-placement.md)
- [Mailbox Access And Email Rule Authority](2026-08-11-email-mailbox-access-and-rule-authority.md)

## Context

An email-based Ticket is logically a case wrapped around one or more real email conversations. The
Ticket adds assignment, workflow, SLA, time, tasks, internal notes, and durable case evidence, while
the underlying communication must continue to behave like email in Nexum, webmail, Outlook, phones,
and other clients.

Today an inbound Email message can create or link to a Ticket. The link action captures a public
Ticket message, stores provenance, inherits tags, and uses the Email message's Ticket foreign key.
The technician Inbox then excludes linked messages, and current UI/API show and attachment paths
reject them. `Mark as not Ticket` clears that foreign key, creates suppression tag/rule behavior, and
soft-deletes the generated Ticket; Ticket merge moves the message-level foreign keys without a
specific same-client/site guard. Existing inbound correlation can use a Nexum Ticket key such as
TD-2026-000001 in the subject and can match In-Reply-To or References against Message-ID values from
outbound Ticket mail.

The full Mail client changes that presentation and data boundary. Converting mail to a Ticket must
not remove the source from its real provider Inbox. Replies sent from either Mail or Ticket must
remain normal messages in the same account-scoped conversation, including provider Sent placement.
Replies may come from the original sender, another To or CC participant, or an external ticket
system, and may omit the Nexum Ticket key while retaining a valid email header chain.

One Ticket may also need several distinct conversations: a customer thread, a supplier thread, and a
thread with another service provider can all concern the same case without sharing recipients or
portal visibility. A durable decision is therefore needed for ownership, link cardinality,
correlation, audience, retention, closed Tickets, and migration from the current single-message link.

## Decision

### Domain Ownership And Source Of Truth

Email owns each real account-scoped conversation, its canonical messages, mailbox placements,
provider folders, participants, headers, drafts, outbound lifecycle, Sent reconciliation, and
mailbox authorization. A conversation is not made global merely because similar content or the same
Message-ID appears in another account.

Ticket owns the case, workflow, assignment, SLA, time, tasks, internal notes, customer-portal policy,
and the durable evidence explicitly captured into the case. Ticket does not become an IMAP store,
and Email does not change Ticket workflow or visibility directly.

The provider remains authoritative for whether the source message is in Inbox, Sent, Archive, Trash,
or another real folder. Creating or linking a Ticket:

- does not move, archive, delete, or hide the source conversation,
- does not replace the provider placement with a Ticket-only copy,
- adds a visible Ticket relationship to the Mail conversation, and
- captures authorized evidence in Ticket without making that snapshot the mailbox source of truth.

The Mail workspace must therefore continue to list a linked conversation whenever its provider
placement and the user's selected view qualify. The current behavior that treats a non-null Ticket
link as a reason to remove mail from the Inbox is a compatibility behavior to retire.

Every Mail read path derives authorization from account/placement access rather than Ticket linkage,
including technician UI, API list/show, route binding, raw/source, and attachment download. Ticket
access alone never grants these Mail paths. A converting actor can leave the default unread-only view
because the selected source was acknowledged while the same source remains available in its real
folder/all-mail view and remains personally unread for other authorized users.

### First-Class Conversation Links

A first-class, audited relationship links an account-scoped Email conversation to a Ticket. It
records at least the relationship role, audience, actor or system policy, timestamps, provenance,
and active or unlinked state. Exact table and column names are chosen in the Feature Slice after
authoritative Dev schema review.

Cardinality and routing rules are:

- One Ticket may have any number of actively primary-linked Email conversations; for each such
  conversation, that Ticket is its single primary automatic-routing target.
- One Email conversation may have at most one active primary Ticket.
- A conversation may be referenced by other Tickets as a secondary reference.
- Only the primary Ticket receives new correlated messages automatically.
- A secondary reference does not duplicate messages into another Ticket, grant Email access, change
  recipients, or participate in automatic reply routing.
- Promoting, moving, or removing a primary relationship is explicit, reauthorized, idempotent, and
  audited. Existing captured Ticket evidence is not silently deleted.

This asymmetry permits one operational case to contain separate customer, supplier, vendor, and
third-party conversations without causing one external reply to be copied into several active cases.

Ticket merge locks both Tickets and all affected conversation relationships in one transaction.
Every source primary link and secondary reference transfers to the surviving target. Duplicate target
relationships/captures collapse to the strongest valid role while preserving both provenance trails;
a target secondary reference to a source-primary conversation is promoted atomically to the target's
primary link. Every relationship and capture is reauthorized in the target Work Context.
Customer-visible evidence requires matching source/target client, site, and portal identity; a
mismatch, uncertain identity, competing primary ownership, or audience/correlation conflict aborts
atomically and enters authorized review. Merge never silently reclassifies audience, sends or
recaptures mail, changes provider placement/read state, or publishes content. Future correlated
messages route only to the surviving target.

The retired source Ticket key remains an audited, non-authorizing correlation alias to the survivor.
A later reply containing only the old `TD-...` reference can therefore resolve to the surviving
Ticket after installation, account, target Work Context, and audience reauthorization. If a retired
alias, an active Ticket key, or verified header/relationship evidence point to different targets, the
message enters conflict review instead of routing automatically.

### Create Ticket And Link-To-Ticket Behavior

Create Ticket from Mail creates the Ticket and primary conversation relationship as one idempotent
cross-domain operation. Link to existing Ticket creates the same primary relationship when the
conversation has no primary Ticket. If it already has one, Nexum shows the existing relationship and
offers an authorized secondary reference or explicit primary-link transfer rather than silently
overwriting it.

The default durable capture includes the selected source message plus future strongly correlated
messages after the link. Older conversation history is shown in an audience/attachment preview and
requires explicit selection or a separately approved account/Ticket-ingress policy. Linking a long or
mixed-audience thread never silently copies its full past into Ticket or the customer portal.

Converting or primarily linking a conversation is an intentional handling action:

- the source remains in the provider Inbox,
- only the selected source message becomes read in the acting user's personal unread-for-me state;
  other conversation messages, future arrivals, and correlated copies in other accounts are not
  acknowledged,
- provider Seen is submitted only for that source message's active-account placement through Email's
  operation ledger using the actor's or bound system policy's required organize authority, and
- other users' personal unread-for-me state is unchanged even though provider Seen is shared.

Opening or previewing the message alone never performs this conversion behavior. Provider mutation is
not reported as successful until acknowledged or reconciled. A transient provider Seen failure does
not roll back a valid Ticket and link; Mail shows the pending or failed provider operation and allows
authorized retry. An actor without provider organize authority cannot complete the primary
create/link conversion. A separately guarded non-mutating link proposal or secondary reference may
remain available, but it is not presented as converted/handled and does not change personal or
provider read state.

Conversion requires the effective Email permission to view the source, the applicable provider
authority for requested mailbox mutations, and the Ticket permission to create or link the target
case. An Email rule or system ingress path uses an explicitly account-bound system policy and the
same guarded domain actions.

Current create/link behavior copies message-level Email tags into Ticket. Existing Ticket tag
assignments survive migration, but a new conversation link does not silently add or remove Ticket
tags. Create Ticket from Mail may initialize Ticket classification only through an explicit previewed
mapping or approved policy. Email tags may remain immutable Ticket-rule input facts without becoming
Ticket assignments.

### Not-Ticket Routing Correction

`Mark as not Ticket` becomes a selected-conversation routing correction, not a return-to-Inbox
operation, because the provider source already stays in Inbox. The guarded action suppresses future
Ticket ingress for that account-scoped conversation, removes its active primary relationship, and
audits the correction without deleting provider mail or captured Ticket evidence. It does not delete
a multi-conversation Ticket or alter its other relationships. Ticket closure/deletion and evidence
redaction/removal remain separate Ticket-owned actions. Legacy not-Ticket tags/rules retain their
scoped suppression meaning and migrate through the new account/rule authority model.

### Additive Correlation

The existing Nexum Ticket-key subject logic remains supported. Standards-based email threading is
added to it; it does not replace it.

Automatic correlation evaluates explicit and conservative signals:

1. an existing primary conversation relationship or an outbound message recorded against that
   relationship,
2. In-Reply-To and References values matching known Message-ID values in a linked conversation,
3. an exact Nexum Ticket key such as TD-2026-000001 in the subject,
4. provider/account placement evidence and other conservative conversation facts.

An exact Ticket key can therefore continue to route a reply when a sender or external system removes
the useful header chain. A valid header chain can route a reply when the subject no longer contains
the Ticket key. Sender and normalized subject similarity alone may produce a suggestion, but are not
sufficient for automatic capture.

A Ticket key is a correlation fact, not a secret or authorization token. Every match still enforces
installation, account, conversation, Ticket, actor/system-policy, and audience boundaries before any
content is disclosed or captured.

Signals are additive but cannot silently overrule one another. Examples requiring a conflict review
include:

- headers resolve to a conversation whose primary link is Ticket A while the subject names Ticket B,
- header references resolve to conversations with different primary Tickets,
- a subject contains several valid Nexum Ticket keys,
- a legacy conversation appears to have been linked to more than one Ticket, or
- an account/privacy boundary makes the apparent match unsafe.

Conflicts enter an authorized triage queue with sanitized reasons. No candidate body, participant,
count, or Ticket identity is disclosed to a user who lacks access to both sides. Resolution records
the selected relationship, rejected candidates, actor, reason, and evidence. Retrying the same
message cannot create duplicate Ticket messages or relationships.

### Inbound And Outbound Conversation Flow

Every new inbound or outbound message in a primary linked conversation is eligible for one
idempotent Ticket evidence capture, subject to authorization, account policy, and audience. This
includes replies sent from the Mail workspace or another synchronized client using the real account,
not only replies composed on the Ticket page.

A Ticket reply is sent through Email's normal outbound action using the selected linked
conversation and a real Email account identity the actor may send from. The outbound message:

- receives a standards-compliant Message-ID and stable idempotency identity,
- where valid identifiers exist, sets In-Reply-To to the selected source Message-ID and builds
  References from its verified chain followed by that selected Message-ID,
- derives the reply subject from the selected source message, retains any external provider ticket
  token, and preserves or adds the Nexum Ticket key without replacing that subject context,
- is reconciled with the provider Sent folder without creating a duplicate copy,
- appears in the Email conversation for every user who may view that account, and
- is captured once in the Ticket timeline with outbound status and Email provenance.

SMTP acceptance alone is not proof of provider Sent placement. Ticket and Mail show pending, sent,
failed, and reconciliation state from the same Email outbound lifecycle. A retry cannot send the
same reply twice.

Internal notes, time entries, workflow events, and other internal Ticket records are not email and
never enter the Email conversation merely because the Ticket has linked mail.

### Participants, Recipients, And Conversation Boundaries

From, Sender, Reply-To, To, CC, and safely available delivery metadata are preserved per message.
Replies from any participant in the valid header chain, including a person copied by CC or another
supplier's ticket system, remain part of the linked conversation and are captured into its primary
Ticket. Participation does not automatically create or verify a Client, Contact, or Vendor.

BCC membership is never inferred from thread participants or exposed beyond the concrete authorized
delivery copy. Cross-account copies remain separate unless safely correlated for presentation
without widening access.

Every compose or reply action has one selected Email conversation and account. Reply and Reply All
show an explicit recipient preview calculated from the selected message. Nexum never:

- combines recipient lists from several conversations linked to the same Ticket,
- adds the Ticket contact, customer, supplier, or vendor merely because they participate in another
  thread,
- carries quoted history or attachments across thread boundaries, or
- lets Ticket-wide context turn a private/internal conversation into a public message.

New email from a Ticket to a recipient outside the selected thread creates a new account-scoped
Email conversation and links it to the same Ticket before sending. This is the normal path for a
separate supplier, vendor, or third-party discussion.

Ticket-originated external mail is classified before Email sending. A customer reply retains the
existing Ticket customer-reply action guard, client-scoped Published requirement, active same-client
primary-contact rule, and validated CC behavior. A reply in an already linked internal third-party
conversation requires the dedicated Ticket external-communication guard plus Email `view` and `send`;
recipients come only from the selected source message. Starting a new supplier/vendor/third-party
conversation requires the same dedicated Ticket guard, an authorized From identity, and an explicit
recipient/audience preview. A verified Contact/Vendor address may be selected; a manually entered new
recipient additionally requires explicit confirmation, never auto-creates or verifies a Contact, and
may be disabled by organization policy. Email send authority alone cannot bypass Ticket workflow,
recipient, or portal policy.

### Audience And Customer Portal

Each primary conversation link has an explicit audience boundary. At minimum it distinguishes
customer-visible communication from internal-only communication. Captured Ticket messages retain
their effective audience and provenance rather than deriving visibility later from the current
participants alone.

A customer conversation may use a reviewed account policy or explicit choice to become
customer-visible. A newly linked supplier, vendor, other service provider, or otherwise third-party
conversation is internal-only by default, even when it concerns the same Ticket and appears in the
technician timeline.

Changing a thread's default audience is an explicit, permissioned, audited action with a disclosure
preview. It does not retroactively expose previously internal messages or attachments without an
additional explicit selection and confirmation. The customer portal never exposes BCC data, raw
mailbox metadata, an inaccessible source-account link, or another thread merely because it belongs
to the same Ticket.

### Closed Tickets

Closing a Ticket does not sever its primary Email relationships or break email threading. A strongly
correlated inbound message is captured against the existing Ticket and invokes a Ticket-owned
customer-reply or external-reply workflow action.

Ticket workflow decides whether that action:

- reopens the Ticket,
- moves it to a configured follow-up state, or
- leaves it closed while creating a visible review item and notification.

Email never reopens a Ticket directly, and it does not create a new Ticket solely because the
correlated primary Ticket is closed. Weak similarity against a closed Ticket remains a manual
suggestion. Outbound mail from a closed Ticket remains subject to Ticket Action and workflow guards;
Email cannot bypass them.

### Retention, Deletion, And Access

Primary capture creates Ticket-owned durable evidence with source identifiers, direction, relevant
headers, participants, safe body content, attachment provenance, timestamps, and audience. The
capture has Ticket retention, correction, redaction, legal-hold, and deletion behavior. It remains
distinguishable from the live provider item.

Provider deletion, folder movement, account removal, or Email cache expiry does not delete evidence
already captured deliberately into Ticket. Ticket deletion, unlinking, or retention action does not
delete or move provider mail. Unlinking stops future auto-routing but preserves the relationship
audit and already captured evidence unless a separate authorized Ticket retention action removes it.

Reading the live source conversation, raw source, or provider attachment continues to require Email
account authorization. Reading a deliberately captured Ticket snapshot requires Ticket
authorization and its audience policy. The capture action must preview the target audience and must
not silently use a private mailbox as a way to disclose content broadly.

Secondary references store relationship metadata only by default. They neither copy durable evidence
nor bypass the intersection of Email and Ticket permissions needed to open the live source.

### Migration And Compatibility

Migration is additive and reversible:

- create the first-class conversation-to-Ticket relationship and audience/provenance records,
- build account-scoped conversations conservatively from existing Email rows and headers,
- backfill current Email-message Ticket links as primary links where the relationship is
  unambiguous,
- preserve existing Ticket messages, attachment copies, metadata, events, Ticket/tag assignments,
  and message-level Email tags without promoting Email tags into Ticket,
- index existing outbound Email-log Message-ID values for header correlation,
- keep the TD Ticket-key subject matcher active throughout migration, and
- retain compatibility reads/writes until parity, backfill verification, rollback, and human review
  are complete.

Backfill does not move provider messages, mark historical mail read, resend outbound mail, generate
new Ticket messages from old content, or merge cross-account conversations. If legacy rows that
appear to share one conversation point to different Tickets, migration preserves every existing
record and reports the conflict instead of choosing a primary Ticket silently.

First-class relationships are backfilled before the `EmailMessage.ticket_id` compatibility write is
retired. Database-supported uniqueness and transactional locking enforce one active primary per
account-scoped conversation and one Ticket/canonical-message evidence capture; shadow reports expose
legacy conflicts before enforcement. Existing Ticket/tag assignments are preserved as-is, ambiguous
tag provenance is reported rather than detached, and no message tag is backfilled as a Ticket tag.

Legacy not-Ticket message tags/rules migrate to explicit account-scoped ingress suppression. An
ambiguous global suppression rule stays disabled for Admin review; historical soft-deleted Tickets
and audit records remain unchanged.

Retired Ticket-key aliases are backfilled only from unambiguous historical merge provenance.
Ambiguous source/survivor candidates remain review items and never become guessed routing aliases.

Every historical Ticket-message audience/visibility value remains unchanged. A backfilled
conversation audience becomes customer-visible only when authoritative same-client/customer-policy
evidence supports it; supplier/third-party or ambiguous legacy relationships default to
internal/review. Backfill never retroactively publishes or hides historical evidence.

The Mail cutover removes linked-means-hidden behavior from technician UI and API list/show, route
binding, raw/source, and attachment reads together. A linked source remains visible according to its
real folder, account authorization, and chosen Mail view. Merge compatibility transfers and
deduplicates relationships/evidence under the normative atomic merge rule before message-level
foreign-key writes are retired.

## Rationale

- Email remains a real provider-synchronized client instead of becoming a disposable Ticket intake
  queue.
- Ticket can represent a complete case containing several external discussions without merging
  unrelated recipients into one artificial email thread.
- One primary routing target prevents duplicate case histories, conflicting replies, and portal
  disclosure while secondary references still support cross-case context.
- Keeping both header chains and the existing Ticket key survives malformed clients, changed
  subjects, and external ticket systems without regressing established routing.
- Per-thread recipients and audience make supplier or third-party collaboration safe inside a
  customer case.
- Separate provider state and Ticket evidence preserve both mailbox correctness and PSA retention.
- Ticket-owned closed-case workflow avoids Email making hidden case-management decisions.

## Consequences

Positive:

- Mail and Ticket show the same real communication without removing handled mail from Inbox.
- Replies from customers, CC participants, vendors, and other ticket systems can stay attached to
  the correct case.
- Technicians can start a separate external conversation from a Ticket without contaminating the
  customer thread.
- Provider Sent state, Ticket delivery status, and conversation history share one idempotent Email
  outbound lifecycle.
- Existing TD subject-key behavior remains a supported recovery path.
- Third-party discussions remain private from the customer portal by default.

Negative:

- Conversation, relationship, evidence, audience, and correlation-conflict records add schema and
  query complexity.
- Every Ticket reply must select an exact conversation/account or deliberately start a new one.
- Provider state and per-user unread state can differ and require clear UI labels.
- A Ticket timeline may contain several visually distinct communication threads and needs strong
  participant/account/audience indicators.
- Migration must reconcile existing message-level links and outbound logs without rewriting
  historical evidence.
- Permission tests must cover both Email account access and Ticket/audience access, including
  negative leakage paths.

## Alternatives Considered

- **Replace the TD subject key with standard email headers.** Rejected because real clients and
  external ticket systems can remove or damage header chains; the current Ticket-key fallback is
  established and remains useful.
- **Use the Ticket key only.** Rejected because participants may reply with a changed subject while
  preserving valid In-Reply-To and References headers.
- **Remove or archive mail when it becomes a Ticket.** Rejected because Ticket conversion must not
  mutate provider placement or make Nexum cease behaving like a real email client.
- **Allow one conversation to auto-route into several Tickets.** Rejected because it duplicates
  evidence, produces competing replies and workflow, and can disclose one discussion through the
  wrong Ticket.
- **Allow only one conversation per Ticket.** Rejected because customer, supplier, vendor, and
  third-party discussions often concern the same operational case but require distinct recipients
  and audiences.
- **Merge all Ticket communication into one synthetic email thread.** Rejected because headers,
  provider placement, recipients, and privacy boundaries belong to the real conversations.
- **Treat sender and normalized subject as sufficient automatic correlation.** Rejected because
  repeated subjects and common contacts would create unsafe false links; those facts may support a
  review suggestion only.
- **Keep only a live pointer from Ticket to Email.** Rejected because provider deletion, mailbox
  access changes, and retention cannot be allowed to erase deliberately captured case evidence.
- **Copy all linked mail into Ticket and discard Email provenance.** Rejected because provider
  reconciliation, folder state, Sent placement, reply headers, and mailbox authorization still
  belong to Email.
- **Expose every linked conversation in the customer portal.** Rejected because supplier and
  third-party work is internal by default and may contain unrelated recipients, terms, or
  attachments.
- **Always create a new Ticket when mail reaches a closed Ticket.** Rejected because closed state does
  not invalidate a strong reply chain; Ticket workflow must decide whether to reopen or review.

## Follow-Up

- Implement this accepted decision through the parent RFC's ordered Feature Slices.
- Revalidate authoritative Dev schema, inbound matching, outbound Email logs, Ticket messages,
  attachment provenance, tag-copy/not-Ticket behavior, linked UI/API/attachment denial paths,
  workflow guards, and merge implementation before naming migration structures.
- Split implementation into Feature Slices for relationship/data migration, correlation and
  conflict triage, not-Ticket correction and merge compatibility, Mail/Ticket compose and Sent
  reconciliation, dedicated third-party recipient policy/guard, multi-thread Ticket UI, audience and
  portal enforcement, and closed-Ticket workflow.
- Preserve regression coverage for TD-only subject replies and existing header-only reply matching.
- Add feature tests for one Ticket with several conversations, database/concurrent primary and capture
  uniqueness, secondary references, selected-source conversion/read semantics, source access through
  every Mail UI/API/raw/attachment path, selected-source/future-message capture, explicit older-history
  inclusion, tag compatibility, not-Ticket suppression, atomic merge transfer/deduplication/conflict,
  primary/secondary role promotion, target Work Context reauthorization and cross-client/site/portal
  public-evidence denial, selected-message recipients and verified reply headers, external ticket-token
  subject preservation, key-only replies through a retired source alias plus alias/key/header conflict
  review, current customer-reply guard compatibility, the dedicated third-party recipient
  guard/confirmation, audience-backfill non-publication, cross-surface draft/presence, provider Sent
  idempotency, conflicts, closed Tickets, deletion isolation, and negative access/portal leakage.
- Run migration shadow reports before cutover and require human review of all ambiguous legacy
  relationships.
- Update Email and Ticket Knowledge documentation, API contracts, operational reconciliation
  guidance, and the applicable human-review entry in each implementation slice.
