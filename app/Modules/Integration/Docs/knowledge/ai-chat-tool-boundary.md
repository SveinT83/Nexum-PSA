Nexum keeps ordinary AI chat tools separate from external API abilities.

## Credential rejection and safe failures (Dev, 2026-10-27)

Availability: verified on Dev and merged to Main in PR #360. Production behavior is unchanged
until a separately approved rollout. Human review remains Pending before production.

Never paste passwords, API keys, authorization headers, tokens, recovery codes or private keys
into either AI Chats or the right-bar assistant. The shared input gate rejects supported
credential-like forms before creating a chat/title/message, pending reply or response job.
It clears rejected text from the response/component and does not save it as validation old input.
The gate detects labelled credentials, known key/token formats, private-key blocks, authenticated
URLs and common equivalent text encodings. Arbitrary unlabelled random text cannot reliably be
recognized; this is not a way to submit secrets safely.

1. Open the full **AI Chats** workspace or the right-bar assistant for the current page.
2. Write the operational question without credential values. Describe the credential type or
   approved opaque reference instead of the secret.
3. If **Credentials cannot be submitted through AI chat** appears, the suspect input is discarded.
   Reword the question; do not retry by disguising or splitting the value.
4. For an AI provider key, users with the existing AI administration permission can use the
   encrypted key field in **Admin > Integrations > AI**. For another credential type, use its
   appropriate authorized secure credential form or ask an administrator. No Vault runtime or
   broader credential access is granted by this change.

AI failures now use fixed categories/messages and a **Reference** ID. The message explains
whether to wait, retry within approved limits or ask an administrator to check policy or secure
configuration. Give support the reference and approximate time; never paste credentials or raw
provider responses. A failed provider call retains local execution/attempt identity and safe HTTP
status/category, not arbitrary upstream error code, model, request-ID or reason text. Successful
approved calls and bounded endpoint fallback retain their existing behavior.

No old chat/telemetry data is silently rewritten. An already disclosed credential requires the
ordinary approved rotation/incident procedure. Provider egress policy remains in force and
rejection does not change actor permissions, runtime enablement or external processing approval.

## Ordinary AI chat

An AI agent can use only chat tools that are registered in the chat tool catalog. The current
catalog contains read-only tools for Knowledge search, allowed record reads, context summaries,
and reply drafts. A reply draft does not send or save a ticket reply.

The **Allow API actions** setting does not turn API abilities into model-invoked chat tools.
Selecting a write API scope therefore does not let ordinary AI chat update tickets, post replies,
or edit Knowledge.

## External API clients

API clients continue to use their separately configured abilities and route authorization. Their
permissions are enforced by the API ability catalog and the target route, not by the chat tool
catalog.

## Existing agent records

Older agent records may contain chat write-tool keys that were previously shown in AI Settings.
Nexum treats those keys as unsupported, displays them as disabled, and removes them when the agent
configuration is saved. They never become executable merely because API actions are enabled.

A future model-invoked write tool requires its own reviewed implementation, authorization checks,
audit behavior, tests, and an approved feature slice before it can be registered for chat.
