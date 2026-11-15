<?php
/**
 * Copyright © Magenx. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\RmaGraphQl\Model\Resolver;

use Magenx\Rma\Service\OrderEligibility;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Shapes the eligibility of one order for the create-return form. The quantities are
 * the back office's own (PRD BR-10), so the storefront offers exactly what a create
 * will accept. Needs an OrderEligibility as $this->orderEligibility.
 */
trait ReturnableItemsTrait
{
    /**
     * @param OrderInterface $order
     * @return array{eligible: bool, reason: string|null, items: array}
     */
    protected function describeReturnable(OrderInterface $order): array
    {
        $verdict = $this->orderEligibility->explain($order);

        if ($verdict !== OrderEligibility::RESULT_OK) {
            return [
                'eligible' => false,
                'reason' => (string)$this->orderEligibility->describe($verdict),
                'items' => [],
            ];
        }

        return [
            'eligible' => true,
            'reason' => null,
            'items' => array_map(static fn(array $line): array => [
                'order_item_id' => (int)$line['order_item_id'],
                'product_name' => $line['name'],
                'product_sku' => $line['sku'],
                'qty_available' => (int)$line['qty_available'],
            ], $this->orderEligibility->getEligibleItems($order)),
        ];
    }
}
