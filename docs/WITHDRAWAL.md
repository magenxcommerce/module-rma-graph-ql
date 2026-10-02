# Right of withdrawal (EU) — GraphQL side

Status: **W9–W11 implemented** on `claude/withdrawal-support`. The full analysis, blocker list and work plan live in
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

- **W9** (done) Extend `CustomerReturn` with `is_withdrawal`, `helpdesk_ticket_code`,
  `return_tracking_number`, `return_carrier` (after the `Magenx_Rma` schema
  change W2).
- **W10** (done) Query `withdrawalOrder(order_number, email)` →
  `order_number`, `can_submit`, `items { order_item_id name sku
  qty_withdrawable qty_unshipped }`. Finds guest **and** registered-customer
  orders in the request's store; accepts the order email (case-insensitive) or
  the logged-in owner; one generic error for every miss;
  `@cache(cacheable: false)`. `Model/Resolver/WithdrawalOrderLookupTrait.php`.
- **W11** (done) Mutation `submitWithdrawal(input: {order_number, email, name,
  items?, message?})` → `ticket_code`, `received_at`, `return_number`,
  `order_canceled`. Thin resolver over `Magenx\Rma\Service\WithdrawalSubmitService`,
  which records the declaration through
  `Magenx\Rma\Api\WithdrawalDeclarationRecorderInterface` and then calls
  `WithdrawalService::submit()`. The default recorder is "unavailable", so the
  mutation is off until the helpdesk module provides one. Review reasons are
  not exposed; staff see them on the RMA.
- Storefront: add both operations to the `/api/graphql` allowlist and
  Turnstile (`withdrawal` action), like `CreateHelpdeskGuestTicket`.
- Requires the `Magenx_Rma` changes on the same branch (no version constraint
  can express that until both are released).
- Keep existing return error messages unchanged; branch on
  `OrderEligibility::explain()` (W3) only in new code.
