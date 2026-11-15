<?php
/**
 * Copyright © Magenx. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\RmaGraphQl\Model\Resolver;

use Magenx\Rma\Helper\ModuleConfig;
use Magenx\Rma\Service\OrderEligibility;
use Magenx\RmaGraphQl\Model\Security\GuestRateLimiter;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Returnable quantities of a guest order, proven by order number and email.
 */
class GuestReturnableItems implements ResolverInterface
{
    use ReturnsAvailabilityTrait;
    use GuestOrderLookupTrait;
    use ReturnableItemsTrait;

    /**
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param OrderEligibility $orderEligibility
     * @param ModuleConfig $moduleConfig
     * @param GuestRateLimiter $guestRateLimiter
     */
    public function __construct(
        protected readonly OrderRepositoryInterface $orderRepository,
        protected readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        protected readonly OrderEligibility $orderEligibility,
        protected readonly ModuleConfig $moduleConfig,
        protected readonly GuestRateLimiter $guestRateLimiter
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
     */
    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null): array
    {
        $orderNumber = (string)($args['order_number'] ?? '');
        $email = (string)($args['email'] ?? '');

        if ($orderNumber === '' || $email === '') {
            throw new GraphQlInputException(__('Order number and email are required.'));
        }

        $this->assertReturnsAvailable($context);

        return $this->describeReturnable(
            $this->findGuestOrder($orderNumber, $email, $this->resolveStoreId($context))
        );
    }
}
