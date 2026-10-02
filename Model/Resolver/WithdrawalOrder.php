<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\RmaGraphQl\Model\Resolver;

use Magenx\Rma\Service\WithdrawalService;
use Magenx\Rma\Service\WithdrawalSubmitService;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * `withdrawalOrder`: the lines of an order a consumer can withdraw from, for the
 * storefront form to offer before `submitWithdrawal`. Read-only; open to guests
 * and customers alike (see WithdrawalOrderLookupTrait for how the order is proven).
 */
class WithdrawalOrder implements ResolverInterface
{
    use WithdrawalOrderLookupTrait;

    /**
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param WithdrawalService $withdrawalService
     * @param WithdrawalSubmitService $withdrawalSubmitService
     */
    public function __construct(
        protected readonly OrderRepositoryInterface $orderRepository,
        protected readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        protected readonly WithdrawalService $withdrawalService,
        protected readonly WithdrawalSubmitService $withdrawalSubmitService
    ) {
    }

    /**
     * @param Field $field
     * @param mixed $context
     * @param ResolveInfo $info
     * @param array|null $value
     * @param array|null $args
     * @return array
     */
    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null): array
    {
        $order = $this->findWithdrawalOrder(
            (string)($args['order_number'] ?? ''),
            (string)($args['email'] ?? ''),
            $context
        );

        $items = [];
        foreach ($this->withdrawalService->getWithdrawableItems($order) as $line) {
            $items[] = [
                'order_item_id' => $line['order_item_id'],
                'name' => $line['name'],
                'sku' => $line['sku'],
                'qty_withdrawable' => $line['qty_held'] + $line['qty_unshipped'],
                'qty_unshipped' => $line['qty_unshipped'],
            ];
        }

        return [
            'order_number' => $order->getIncrementId(),
            'can_submit' => $this->withdrawalSubmitService->isAvailable((int)$order->getStoreId()),
            'items' => $items,
        ];
    }
}
