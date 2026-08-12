<?php
/**
 * Mage-OS
 * Copyright (c) Mage-OS Association (https://mage-os.org/)
 * SPDX-License-Identifier: MIT
 *
 * Forked from mage-os/module-rma 2.4.1 into Magenx_Rma / Magenx_RmaGraphQl;
 * identifiers renamed, GraphQL surface split into a sibling module.
 * Modified by MagenX: scoped the lookup to guest orders in the current store and
 * made the e-mail comparison non-enumerable.
 */
declare(strict_types=1);

namespace Magenx\RmaGraphQl\Model\Resolver;

use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Sales\Api\Data\OrderInterface;

trait GuestOrderLookupTrait
{
    /**
     * Resolve the guest order an unauthenticated caller is claiming.
     *
     * Three constraints beyond "increment id matches":
     *
     * - `customer_is_guest`, because an order placed by a registered customer must not
     *   be reachable by anyone who happens to know its number and the customer's
     *   e-mail. Without it, a return created here is stored with a null customer_id and
     *   never appears in that customer's own `customerReturns` list.
     * - the store the request came in on, because `increment_id` is only unique per
     *   sequence and a multi-store install can otherwise cross store boundaries.
     * - a constant-time e-mail comparison, with the same error for "no such order" and
     *   "wrong e-mail", so the query cannot be used to enumerate valid order numbers.
     *
     * @param string $orderNumber
     * @param string $email
     * @param int|null $storeId
     * @return OrderInterface
     * @throws GraphQlAuthorizationException
     */
    protected function findGuestOrder(string $orderNumber, string $email, ?int $storeId = null): OrderInterface
    {
        $this->searchCriteriaBuilder
            ->addFilter('increment_id', $orderNumber)
            ->addFilter('customer_is_guest', 1);

        if ($storeId !== null) {
            $this->searchCriteriaBuilder->addFilter('store_id', $storeId);
        }

        $searchCriteria = $this->searchCriteriaBuilder
            ->setPageSize(1)
            ->create();

        $orders = $this->orderRepository->getList($searchCriteria)->getItems();
        $order = reset($orders);

        if (!$order || !hash_equals(strtolower((string)$order->getCustomerEmail()), strtolower($email))) {
            throw new GraphQlAuthorizationException(
                __('We could not find a guest order matching that order number and email address.')
            );
        }

        return $order;
    }

    /**
     * Store id the request arrived on, or null when the context cannot supply one.
     *
     * Returning null degrades to the pre-existing cross-store behaviour rather than
     * failing the query outright, which keeps the guest flow working on setups where
     * the resolver is invoked without a full query context (tests, custom entry points).
     *
     * @param mixed $context
     * @return int|null
     */
    protected function resolveStoreId($context): ?int
    {
        if (!is_object($context) || !method_exists($context, 'getExtensionAttributes')) {
            return null;
        }

        $store = $context->getExtensionAttributes()?->getStore();

        return $store !== null ? (int)$store->getId() : null;
    }
}
