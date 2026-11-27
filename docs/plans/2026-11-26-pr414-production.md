# PR #414 production deployment

On 26 November 2026 Svein Tore explicitly authorized the repository administrator exception for PR #414 and production activation. GitHub merged the tested head 3fe8460f4677e7ccd6944f4b79cea2318954d898 into Main as 36c738a01c3a0eba781e844d2844bbc80149cad6. All 61 changed paths matched the approved head after the merge. The isolated PHP 8.3 candidate had passed 158 tests and 2,023 assertions.

Production VM169 now serves release 20261126-issues-411-413-161514. All 40 changed runtime files matched their checked hashes after activation. The three production Sales files changed by PRs #415 and #416 since initial staging were reconciled against Main and verified again after activation. The existing application environment and shared storage were preserved. No schema migration or business data backfill was required. PHP-FPM reloaded and returned active.

Public HTTPS returned 200 for /up and /login. Without a bearer token, the Commercial MCP endpoint, customer pairing and Ticket transfer preview returned 401. Independent retrieval of the production OpenAPI document exposed mergeTripletexCustomer, previewTicketClientTransfer, transferTicketClient and clientsApiRegistrationCandidates. A production token negotiated MCP protocol 2025-06-18 at https://portal.tronderdata.no/mcp/commercial and discovered 12 tools. The cost context tool returned native HTTP 200. The production verification issued no business writes.

The prior release and OpenAPI descriptor are recoverable through the VM's root-private /root/nexum-411-413-20261126T161514Z/activate.py rollback. This restores the previous release pointer and descriptor and reloads PHP-FPM. The rollback command was prepared and inspected. Production rollback was not invoked.

Seven employee documentation pages were updated and read back at their existing locations: 389 rev17, 424 rev38, 451 rev29, 457 rev16, 617 rev11, 639 rev10 and 843 rev9. The native endpoint is deployed. No shared LiteLLM registration, catalogue, network rule or user binding changed in this release. Actual employee ChatGPT discovery and read-back, and the human registration/portal checks, remain in HR-2026-11-25-CLIENT-REGISTRATION-TRANSFER and HR-2026-11-26-COMMERCIAL-MCP.

## 27 November 2026 verification

Two customers signed in through the usual portal login in separate cookie sessions on the deployed release sources in an isolated Dev fixture. Staff preview and transfer returned 200. Before the move the original customer could view the Ticket and download the attachment. After the move both customers received 404 for detail and download, and both lists omitted the Ticket. The destination customer's own control Ticket remained available. Two focused Laravel tests passed with 40 assertions. No production customer session or record was used for the move.

The production gateway restarted with the updated Nexum OpenAPI registration. Its live tool inventory returned 1,413 entries including the four registration, pairing and transfer actions and all twelve Commercial operations. Contract tests checked personal credential resolution and the missing-binding denial. A synthetic large catalogue verified that the affected OpenAI profile retained the existing visible tools and the new actions among 1,000 authorized entries.

A genuine OpenAI connector service search succeeded after restart. Its saved tool list still lacked the new actions. Direct authenticated production MCP discovery returned twelve tools and the cost and term indexes both returned native HTTP 200. The production candidate lookup with a synthetic address returned `no_match`. Anonymous discovery returned HTTP 401 on both the public gateway and the native Commercial endpoint. The intended employee's ChatGPT catalogue refresh and calls remain outstanding. Both issues stay open. Named human review remains In Review.

The isolated Commercial MCP workflow passed again with 54 assertions on 27 November. The gateway and Nexum MCP operational pages were published and read back in BookStack (799 revision 4, 843 revision 10).
