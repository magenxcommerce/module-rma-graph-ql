# Magenx_RmaGraphQl

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

All seven operations are client-run from the Next.js storefront, so each is
mirrored in the `/api/graphql` persisted-query allowlist
(`apps/theme/src/app/api/graphql/_lib/allowed-operations.ts`). Documents live in
`packages/engine/src/magento/queries/returns.ts`, types in
`packages/engine/src/magento/types/returns.ts`.

Note the resolvers throw on error (there is no structured `errorV2` on the
create/comment mutations), so callers handle failures with try/catch rather than
a returned error field.

## Known gap

No resolver checks `rma/general/enabled` — upstream never did, while its Luma
controllers do. The GraphQL surface is therefore live even when RMA is switched
off in admin. Adding the gate would be correct but is a behaviour change: the
config default is `0`, so it must not be enabled without first confirming the
live value in the target store scope.

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
order and labelled for the current store view (`Store` header). No token
required — guests create returns too.

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

### Types

All lookup fields (status, reason, resolution_type, condition) return a `ReturnLookupValue` with `id`, `code` and `label`. The `label` is store-localized based on the current store view header (`Store`).
