# Right of withdrawal (EU) — GraphQL side

Status: **W9 and W11 implemented** on `claude/withdrawal-support`. The full analysis, blocker list and work plan live in
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
- **W10** dropped: the withdrawal form does not look orders up.
- **W11** (done) Mutation `submitWithdrawal(input: {email, name, order_number,
  items, message})` → `ticket_code`, `received_at`. Free text, recorded as
  submitted through `Magenx\Rma\Service\WithdrawalSubmitService`; the record
  is linked to the order only when order number + email (or the logged-in
  owner) match. No RMA or cancel happens automatically — staff run
  `WithdrawalService` after review. Inputs trimmed and bounded (name/email 255,
  order number 64, items/message 2000).
- Storefront: allowlisted and Turnstile-protected (`withdrawal` action).
- Keep existing return error messages unchanged; branch on
  `OrderEligibility::explain()` (W3) only in new code.
