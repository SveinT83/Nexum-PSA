The Picking List is the working queue for reserved ticket items that must be taken from stock.

Use this page when a ticket has a Storage item reserved and the item needs to be physically picked before it can be billed or delivered.

## What The List Shows

Each row is a reserved ticket cost line linked to a Storage item.

The row shows:

- **Item**: the SKU and item name to pick.
- **Ticket**: the ticket that needs the item.
- **Client**: the client and assigned technician from the ticket.
- **Location**: warehouse and box when known.
- **Reserved**: how many units the ticket needs.
- **On-hand**: how many units are physically in stock.
- **Status**: whether the row can be picked now.
- **Action**: the `Pick` button.

## Ready And Waiting

**Ready** means the item has enough on-hand stock for the reserved quantity. The `Pick` button is enabled.

**Waiting for stock** means the ticket needs more items than are currently on hand. The `Pick` button is disabled until stock is added.

## Searching And Filtering

Use Search to find rows by ticket key, ticket subject, SKU, or item name.

Use the filter button to show:

- All reserved items
- Ready to pick
- Waiting for stock

The filter area opens automatically when a status filter is active.

## How To Pick An Item

1. Find the row for the ticket and item.
2. Confirm the location, reserved quantity, and on-hand quantity.
3. Physically take the item from stock.
4. Click `Pick`.

When `Pick` is clicked, Storage:

- Reduces the item's on-hand quantity.
- Reduces reserved quantity.
- Marks the Storage reservation as fulfilled.
- Marks the ticket cost row as picked.
- Creates a `ticket_pick` stock movement for audit history.
- Leaves the picked cost ready for Economy order generation.

## If Pick Is Disabled

Pick is disabled when there is not enough on-hand stock.

Typical next steps:

- Check if the item is in another box or warehouse.
- Adjust stock if the on-hand quantity is wrong.
- Add stock when the item arrives.
- Use the Storage inventory list to filter items that should be ordered.

## Practical Notes

- Do not click `Pick` before the item has actually been taken from stock.
- If the wrong item was reserved on the ticket, fix the ticket cost line instead of picking it.
- If an item requires serial numbers, record the serial workflow when the consuming flow asks for it.
- The movement history on the item is the audit trail for what happened.
