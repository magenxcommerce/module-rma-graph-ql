# Magenx_RmaGraphQl

> **Built with AI assistance.** This module is built with significant use of Claude Code, under active human review and guidance. It is open source and in active development. AI assistance does not lower the engineering bar: every change must meet the same correctness, security, documentation and maintainability requirements as any other contribution.
>
> **No warranty.** We are not responsible for any incompatibility. By installing this software, you accept responsibility for its security, upgrades, patches and monitoring.

The GraphQL surface for [`Magenx_Rma`](../Rma/README.md) — customer and guest
return lookup, return creation, comments, and the return-attribute metadata the
headless create-return form needs.

Split out of the backend module so the schema and resolvers can be versioned and
deployed independently of the admin/REST engine, the same way Magento splits
`Magento_Catalog` from `Magento_CatalogGraphQl`. This module contributes
`etc/schema.graphqls` and the resolvers only — no DB schema, no admin UI, no
`di.xml`.

## Fork provenance

The resolvers and schema come from
[`mage-os/module-rma`](https://github.com/mage-os/module-rma) tag `2.4.1`
(`6ddba4a`), MIT licensed, renamed into `Magenx\RmaGraphQl\`. See `LICENSE`
(Copyright (c) Mage-OS Association); every derived file carries a Mage-OS
attribution header with `SPDX-License-Identifier: MIT`, and the two files we
changed say so in a `Modified by MagenX:` line.

Two additions are ours — they previously lived in
`magento/patches/mage-os-module-rma.patch` and are now folded into source:

- **`returnAttributesMetadata`** (`Model/Resolver/ReturnAttributesMetadata.php`).
  The upstream schema only resolves a *single* lookup value by id on an existing
  RMA, so a create-return form had no way to enumerate the selectable reasons,
  resolution types and item conditions. Intentionally **unauthenticated** — the
  values are public store configuration and guests create returns too. Its
  `activeCriteria()` helper rebuilds the criteria per repository call because
  `SearchCriteriaBuilder` resets on `create()`.
- **`customerReturns(sort: CustomerReturnsSortInput)`**. Upstream takes paging
  only and builds its `SearchCriteria` with no sort order, so the collection
  comes back in `entity_id` order — **oldest return first**, on every page. The
  resolver defaults to `created_at DESC`, so even a client that sends no `sort`
  gets newest-first.

The patch's other hunks (the eight `*SearchResults` models and their `di.xml`
preferences) were superseded by upstream 2.4.1, which ships them under
`Model/Data/`.

## Install

```bash
bin/magento module:enable Magenx_Rma Magenx_RmaGraphQl
bin/magento setup:upgrade
bin/magento setup:di:compile      # production mode
bin/magento cache:flush
```

`Magenx_Rma` must be enabled first — it is sequenced in `etc/module.xml` and the
resolvers depend on its repositories and services.

## Deploy order matters

The storefront's `GetCustomerReturns` document sends `sort:` and the create-return
form queries `returnAttributesMetadata`. **Deploy this module before a storefront
build that uses them**, or the query fails schema validation with
*"Unknown argument sort"* / *"Cannot query field returnAttributesMetadata"*. The
storefront degrades gracefully on the metadata field only (`metadataUnavailable`
in `<CreateReturnForm>`); the `sort` argument fails the whole document.

## Storefront wiring

The seven return operations are client-run from the Next.js storefront, so each is
mirrored in the `/api/graphql` persisted-query allowlist
(`apps/theme/src/app/api/graphql/_lib/allowed-operations.ts`). Documents live in
`packages/engine/src/magento/queries/returns.ts`, types in
`packages/engine/src/magento/types/returns.ts`. `submitWithdrawal` is used by the storefront `/withdrawal` form.

Note the resolvers throw on error (there is no structured `errorV2` on the
create/comment mutations), so callers handle failures with try/catch rather than
a returned error field.

## Returns switch

Every customer and guest operation follows `rma/general/enabled` of the store the
request arrives on (API PRD FR-C2): while it is off they answer *"Returns are not
available for this store."* and `returnAttributesMetadata` answers empty lists.
The config default is `0`, so **confirm the live value for the storefront's
website is `1` before deploying** this version; earlier versions ignored the
switch on reads and comments.

This version also needs the `Magenx_Rma` release that adds
`OrderEligibility::describe()`, `StatusCodes::FINAL_CODES` and
`ChoiceListsCache`; deploy that first.

## GraphQL API

The module provides GraphQL queries and mutations for headless frontend integration.

### Queries

#### customerReturns

Returns a paginated list of returns for the logged-in customer. Requires customer token.

`sort` is optional and defaults to `{ sort_field: CREATED_AT, sort_direction: DESC }`
(newest first). `sort_field` is `CREATED_AT` or `RMA_ID`; `sort_direction` is the
stock `SortEnum` (`ASC` / `DESC`).

```graphql
query {
    customerReturns(
        pageSize: 10
        currentPage: 1
        sort: { sort_field: CREATED_AT, sort_direction: DESC }
    ) {
        items {
            rma_id
            increment_id
            order_number
            status { code label }
            created_at
        }
        total_count
        page_info { page_size current_page total_pages }
    }
}
```

#### returnAttributesMetadata

Lists the active reasons, resolution types and item conditions a customer or
guest can choose from when creating a return, each ordered by its admin sort
order and labelled for the current store view (`Store` header). The `withdrawal`
reason is never offered. No token required — guests create returns too. The
response is stored in the full page cache per store view; saving or deleting a
reason, resolution or condition purges it.

```graphql
query {
    returnAttributesMetadata {
        reasons { id code label }
        resolution_types { id code label }
        conditions { id code label }
    }
}
```

#### customerReturn

Returns a single return by ID for the logged-in customer. Requires customer token.

```graphql
query {
    customerReturn(rma_id: 1) {
        rma_id
        increment_id
        order_number
        status { id code label }
        reason { id code label }
        resolution_type { id code label }
        items {
            product_name
            product_sku
            qty_requested
            condition { code label }
        }
        comments {
            comment_id
            author_type
            author_name
            comment
            created_at
        }
    }
}
```

#### guestReturn

Returns a single return for a guest order, authenticated by order number and email.

```graphql
query {
    guestReturn(order_number: "000000001", email: "guest@example.com", rma_id: 1) {
        rma_id
        increment_id
        status { code label }
        items { product_name qty_requested }
    }
}
```

#### customerReturnableItems / guestReturnableItems

What the create-return form may offer for one order: whether it is eligible, the
reason when it is not, and the units of each line that can still be returned
(shipped, not refunded or canceled, not in an open return). Use these instead of
computing quantities from the order.

```graphql
query {
    customerReturnableItems(order_id: 100) {
        eligible
        reason
        items { order_item_id product_name product_sku qty_available }
    }
    # guests: guestReturnableItems(order_number: "000000001", email: "guest@example.com") { ... }
}
```

#### returnComments

Returns a paginated list of customer-visible comments for a return. Requires customer token.

```graphql
query {
    returnComments(rma_id: 1, pageSize: 50, currentPage: 1) {
        items {
            comment_id
            author_type
            author_name
            comment
            created_at
        }
        total_count
    }
}
```

### Mutations

#### createCustomerReturn

Creates a return for a customer order. Requires customer token.

```graphql
mutation {
    createCustomerReturn(input: {
        order_id: 1
        reason_id: 1
        resolution_type_id: 1
        items: [
            { order_item_id: 1, qty_requested: 1, condition_id: 1 }
        ]
    }) {
        return {
            rma_id
            increment_id
            status { code label }
        }
    }
}
```

#### createGuestReturn

Creates a return for a guest order, authenticated by order number and email.

```graphql
mutation {
    createGuestReturn(input: {
        order_number: "000000001"
        email: "guest@example.com"
        reason_id: 1
        resolution_type_id: 1
        items: [
            { order_item_id: 1, qty_requested: 1, condition_id: 1 }
        ]
    }) {
        return {
            rma_id
            increment_id
            status { code label }
        }
    }
}
```

#### submitWithdrawal

Declares withdrawal from a purchase. The input is free text, recorded as
submitted — there is no order lookup, and the call itself is the legal act.
Staff identify the order and handle the return afterwards.

The declaration is recorded and confirmed by email through
`Magenx\Rma\Api\WithdrawalDeclarationRecorderInterface`, implemented by the
helpdesk module; **until one is installed the mutation fails with
"Withdrawal declarations are not available."** When the order number matches an
order of the store and the email matches it (or the logged-in customer placed
it), the record is linked to that order for staff.

```graphql
mutation {
    submitWithdrawal(input: {
        email: "guest@example.com"
        name: "Jane Smith"
        order_number: "000000001"
        items: "Blue shirt (SH-1), 1 x hat"
        message: "Optional"
    }) {
        ticket_code
        received_at
    }
}
```

#### addReturnComment

Adds a comment to a return. Requires customer token.

```graphql
mutation {
    addReturnComment(input: {
        rma_id: 1
        comment: "When will my refund be processed?"
    }) {
        comment_id
        author_type
        author_name
        comment
        created_at
    }
}
```

### Authentication

| Operation | Auth |
|---|---|
| `customerReturns`, `customerReturn`, `returnComments`, `addReturnComment` | Customer token (`Authorization: Bearer <customer_token>`) |
| `createCustomerReturn` | Customer token — ownership of the order is verified |
| `guestReturn`, `createGuestReturn` | No token — authenticated by `order_number` + `email` |
| `returnAttributesMetadata` | No token — public store configuration |
| `submitWithdrawal` | No token. Turnstile-protect it at the storefront proxy; a customer token, when sent, only helps link the order |
| `customerReturnableItems` | Customer token — ownership of the order is verified |
| `guestReturnableItems` | No token — authenticated by `order_number` + `email` |

Someone else's return or order answers exactly like a missing one. Guest proof
(`guestReturn`, `createGuestReturn`, `guestReturnableItems`) and `submitWithdrawal`
are limited to 10 calls per minute per client address and per email; refusals
are logged as counts, without the address or email. Customers can comment only
on open returns (not rejected, canceled, resolved or refunded), at most 2000
characters. An ineligible order is refused with the specific reason (returns
off, order status, return period, nothing shipped, no returnable items).
Storage errors are logged and answered as an internal error, never with
database text.

### Types

All lookup fields (status, reason, resolution_type, condition) return a `ReturnLookupValue` with `id`, `code` and `label`. The `label` is store-localized based on the current store view header (`Store`).
