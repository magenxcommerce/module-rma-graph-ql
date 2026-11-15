<?php
/**
 * Copyright © Magenx. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\RmaGraphQl\Model\Resolver;

use Magenx\Rma\Helper\ModuleConfig;
use Magenx\Rma\Service\OrderEligibility;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Returnable quantities of one of the signed-in customer's orders (API PRD F2 step 1).
 */
class CustomerReturnableItems implements ResolverInterface
{
    use ReturnsAvailabilityTrait;
    use ReturnableItemsTrait;

    /**
     * @param OrderRepositoryInterface $orderRepository
     * @param OrderEligibility $orderEligibility
     * @param ModuleConfig $moduleConfig
     */
    public function __construct(
        protected readonly OrderRepositoryInterface $orderRepository,
        protected readonly OrderEligibility $orderEligibility,
        protected readonly ModuleConfig $moduleConfig
    ) {
    }

    /**
     * @param Field $field
     * @param mixed $context
     * @param ResolveInfo $info
     * @param array|null $value
     * @param array|null $args
     * @return array
     * @throws GraphQlAuthorizationException
     * @throws GraphQlInputException
     * @throws GraphQlNoSuchEntityException
     */
    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null): array
    {
        $customerId = (int)$context->getUserId();
        if ($context->getExtensionAttributes()->getIsCustomer() !== true || !$customerId) {
            throw new GraphQlAuthorizationException(__('The current customer isn\'t authorized.'));
        }

        $this->assertReturnsAvailable($context);

        $orderId = (int)($args['order_id'] ?? 0);
        try {
            $order = $this->orderRepository->get($orderId);
        } catch (NoSuchEntityException) {
            $order = null;
        }

        // Someone else's order answers exactly like a missing one (API PRD BR-3).
        if ($order === null || (int)$order->getCustomerId() !== $customerId) {
            throw new GraphQlNoSuchEntityException(__('Order with ID "%1" does not exist.', $orderId));
        }

        return $this->describeReturnable($order);
    }
}
