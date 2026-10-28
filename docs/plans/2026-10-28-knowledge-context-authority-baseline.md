# Knowledge And AI Context Authority Baseline (#265 / #261)

Date: 2026-10-28
Authority: /var/Projects/tdPSA, branch Dev; read-only investigation.
No Knowledge/AI permission, audience, article, model/provider or runtime setting changed.

## Verified Current Paths

- Knowledge ArticleQuery paginates all Tech articles and loads book/chapter pages without an
  article-level audience boundary. relevantForTicket filters published/visibility/client ID but
  does not establish the current actor's Knowledge permission or role/team eligibility.
- Tech Knowledge routes carry the browse permission; KnowledgeApiAccess checks fresh internal
  actor/native permissions and token abilities. Neither defines role/team article eligibility.
- PortalKnowledgeAccess owns published public/client-wide visibility in the selected customer
  context. Preserve and regression-test that behavior rather than routing Portal through staff AI.
- AiToolContextBuilder independently queries published Article and matching Book rows. It emits
  titles, snippets, shelf/book/chapter information and direct Knowledge URLs without current
  Knowledge/audience authority. The Ticket branch filters owner/open status but does not consume
  one Ticket-owned record/Work Context read boundary.
- AiChatResponder assembles tool context before AiOutboundPolicyGuard.prepare. The latter owns
  provider/model/installation policy; typed retrieval must additionally enforce the actual minimum
  data profile of returned fields before source content enters model messages.
- KnowledgeRevisionAccess grants Ticket-scoped proposal access using permissions and linked IDs;
  exact current Ticket/Client/Work Context access must be re-evaluated for that exception.
- ArticleRevisionSnapshot.audience currently binds visibility/client scope only. The accepted
  2026-09-04 ADR explicitly anticipates normalized role/team audiences under #265. Revision
  identity, publication/read-back and immutable history must stay authoritative.

## Concrete Missing Product Decision

Source inventory found no Team model/membership authority. A native schema-name-only read found no
team or department tables. Existing Ticket Rules RFC/ADR deliberately exclude first-class teams
from that older scope. Neither Issue has comments supplying another team authority.

Svein was asked once through the text input panel whether Knowledge audiences should use internal
Nexum teams/member lists, existing roles, or a specified external group source. That choice affects
identity, membership revocation and permissions; it cannot be guessed from a label. The answer is
required before implementing #265's complete audience boundary. #261 must consume that same
Knowledge-owned contract, not introduce a competing temporary access model.

## Full Target And Implementation Order

1. Define one Knowledge-owned current-actor read/AI-consumption authority with separate browse,
   AI consume, authoring, publication, administration and exact Ticket-revision permissions.
   Ordinary technician documentation keeps the agreed safe default; selected restricted role/team
   audiences and Client/Work Context are checked on every read. Unknown identity/scope is denied.
2. Extend immutable revision/audience snapshots and guarded publication so audience change cannot
   bypass review, stale checks, downstream BookStack scope or read-back. Preserve legacy content,
   public/client Portal behavior and existing source ownership.
3. Make every Tech/API/library/book/chapter/Ticket-suggestion/AI consumer use the same boundary.
   AI-only consumers receive safe attribution without dead or unauthorized Knowledge links.
4. Add typed domain providers for Knowledge and existing Ticket context. Carry stable source
   references, current scope/authority, observation/freshness/redaction metadata and explicit denied,
   unavailable, unknown, stale and partial outcomes. No restricted notes, attachment bodies,
   credentials or unapproved free text may become model context.
5. Reuse the central AI egress guard for actual field/profile classification before model messages,
   preserving independent provider/model/runtime gates and input/error boundaries already fixed.
6. Cover two users, inactive/revoked actors, role/team changes, internal/client/site Work Context,
   restricted records, AI-only versus browse/API rights, Ticket suggestions/revision exceptions,
   Portal regression, policy denial, unknown source and stale metadata on Dev and latest Main.
7. Publish verified source guidance/actual handbook sections with scope read-back. Do not publish
   Main-only behavior as deployed production or mark human review complete from tests.

The three older Ticket-handbook paragraphs differing from current source require explicit
Dev/Main-versus-production wording when this authorization work is documented; do not overwrite
unrelated page content as an incidental runtime update.

## Status / Resume Condition

Planning baseline complete. Implementation blocked on the team-membership source decision.
Owner for that decision: Svein. Resume with one coherent #265/#261 RFC/ADR and complete sequential
slices under the already requested work. No native grant/migration or AI source activation is
authorized merely by this baseline. Other independently finishable audited issues may proceed.
