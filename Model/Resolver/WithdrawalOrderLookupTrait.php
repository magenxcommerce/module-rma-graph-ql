<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\RmaGraphQl\Model\Resolver;

use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Finds the order a withdrawal is declared for.
 *
 * Unlike GuestOrderLookupTrait this also reaches orders of registered customers:
 * the right of withdrawal must work without logging in. The caller proves the
 * order with its number plus the order's email address, or by being the
 * logged-in customer who placed it. Every miss gets the same message, so the
 * lookup does not reveal whether an order number exists.
 *
 * Classes using it need `$orderRepository` (OrderRepositoryInterface) and
 * `$searchCriteriaBuilder` (SearchCriteriaBuilder).
 */
trait WithdrawalOrderLookupTrait
{
    /**
     * @param string $orderNumber
     * @param string $email
     * @param mixed $context GraphQL context
     * @return OrderInterface
     * @throws GraphQlAuthorizationException
     */
    protected function findWithdrawalOrder(string $orderNumber, string $email, $context): OrderInterface
    {
        $orderNumber = trim($orderNumber);
        $email = strtolower(trim($email));
        $miss = new GraphQlAuthorizationException(
            __('We could not find an order matching that order number and email address.')
        );

        if ($orderNumber === '') {
            throw $miss;
        }

        $this->searchCriteriaBuilder->addFilter('increment_id', $orderNumber);
        $store = $context->getExtensionAttributes()?->getStore();
        if ($store !== null) {
            $this->searchCriteriaBuilder->addFilter('store_id', (int)$store->getId());
        }
        $orders = $this->orderRepository->getList(
            $this->searchCriteriaBuilder->setPageSize(1)->create()
        )->getItems();
        $order = reset($orders);

        if (!$order) {
            throw $miss;
        }

        $emailMatches = $email !== ''
            && hash_equals(strtolower((string)$order->getCustomerEmail()), $email);
        $isOwner = $context->getExtensionAttributes()?->getIsCustomer()
            && $order->getCustomerId()
            && (int)$order->getCustomerId() === (int)$context->getUserId();

        if (!$emailMatches && !$isOwner) {
            throw $miss;
        }

        return $order;
    }
}
