<?php
/**
 * Mage-OS
 * Copyright (c) Mage-OS Association (https://mage-os.org/)
 * SPDX-License-Identifier: MIT
 *
 * Forked from mage-os/module-rma 2.4.1 into Magenx_Rma / Magenx_RmaGraphQl;
 * identifiers renamed, GraphQL surface split into a sibling module.
 */
declare(strict_types=1);

namespace Magenx\RmaGraphQl\Model\Resolver;

use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;

trait GuestOrderLookupTrait
{
    /**
     * @param string $orderNumber
     * @param string $email
     * @return OrderInterface
     * @throws GraphQlNoSuchEntityException
     * @throws GraphQlAuthorizationException
     */
    protected function findGuestOrder(string $orderNumber, string $email): OrderInterface
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('increment_id', $orderNumber)
            ->setPageSize(1)
            ->create();

        $orders = $this->orderRepository->getList($searchCriteria)->getItems();
        $order = reset($orders);

        if (!$order) {
            throw new GraphQlNoSuchEntityException(__('Order "%1" not found.', $orderNumber));
        }

        if (strtolower((string)$order->getCustomerEmail()) !== strtolower($email)) {
            throw new GraphQlAuthorizationException(__('Order email does not match.'));
        }

        return $order;
    }
}
