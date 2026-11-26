# Reviewed Tripletex identity and Ticket transfer

Accepted for Dev on 25 November 2026. Product owner and approver for Dev: Svein Tore. Implementation owner: Nexum PSA.
Related issue: [#411](https://github.com/SveinT83/Nexum-PSA/issues/411).
Related decisions: [Client registration candidates](2026-11-18-client-registration-candidates.md) and [Ticket transfer RFC](../rfc/2026-11-25-ticket-client-transfer.md).

## Customer identity

Email, phone and organization number are discovery keys. Several organizations may share a phone number or billing email. The authorized user selects the local and provider identities before adopting the verified Tripletex number. An established different binding, a conflicting nonempty organization number or an occupied number stops the operation. The existing Nexum record retains its primary key, contact relationships and history.

The same provider identity can also create a local card through selected-customer registration. Serialize on the existing connection lock and re-read the provider identity and existing link. An independent retry can return the already bound Client without applying additional registration fields. A new command key after an unknown external outcome requires reconciliation. No schema migration is required.

## Moving a Ticket

The existing staff edit and the new API commands share the destination checks. Ticket staff with Client read access select an active destination and compatible site, contact and asset. The dedicated API requires a preview revision and a stable confirmation key. A row lock serializes the change. The confirmation receipt belongs to the actor and Ticket. The client's old and new IDs remain in the internal history.

Clear portal publication, suppress customer notifications from the transfer and record the last prior message ID. Queued reply jobs check that boundary before sending. Unresolved outbound communications block transfer. Existing attachments and time retain their identifiers. The existing owned-work reconciliation retains booked financial evidence and clears a previous customer's unallocated contract association when appropriate. Portal publication is a separate staff action that requires reviewing everything it would expose.

## Operations

Use the site's normal Dev, Main and production release procedure. The versioned gateway specification and staff connector each need a separate inventory check after promotion. The Dev schema was converted using the installed gateway code with a simulated HTTP transport. No customer or provider mutation is required to verify that contract. Production acceptance belongs to the human-review register.
