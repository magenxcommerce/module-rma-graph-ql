# Right of withdrawal (EU) — GraphQL side

Status: **plan**. The full analysis, blocker list and work plan live in
`magenxcommerce/module-rma` → `docs/WITHDRAWAL.md` (branch
`claude/withdrawal-support`). This file lists only what lands in this module.

## Blockers in this module

- **G1** `Model/Resolver/GuestOrderLookupTrait.php:45` filters
  `customer_is_guest = 1`, so a registered customer who is logged out cannot
  reach their order by order number + email. Correct for returns, wrong for a
  withdrawal declaration.
- **G2** `CreateCustomerReturn.php:85-86` / `CreateGuestReturn.php:73-74` turn
  every eligibility failure into "This order is not eligible for a return.", so
  a caller cannot tell late / not shipped / nothing left apart.
- **G4** `AddReturnComment.php` is customer-only: guests cannot post a tracking
  number back.

## Work items

- **W9** Extend `CustomerReturn` with `is_withdrawal`, `helpdesk_ticket_code`,
  `return_tracking_number`, `return_carrier` (after the `Magenx_Rma` schema
  change W2).
- **W10** Query `withdrawalOrderItems(order_number: String!, email: String!)`
  returning per item `order_item_id`, `name`, `sku`, `qty_withdrawable`,
  `qty_unshipped`. Finds guest **and** registered-customer orders, returns data
  only when the order email matches, one generic error for every miss,
  `@cache(cacheable: false)`. Turnstile-protected at the storefront proxy.
- **W11** No public "create withdrawal RMA" mutation here. The single submit
  mutation lives with the helpdesk/withdrawal module: it creates the ticket,
  then calls `Magenx\Rma\Service\WithdrawalService::submit()` server-side.
- Keep existing return error messages unchanged; branch on
  `OrderEligibility::explain()` (W3) only in new code.
