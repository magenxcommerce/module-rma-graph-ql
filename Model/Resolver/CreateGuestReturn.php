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

use Magenx\RmaGraphQl\Model\Resolver\DataProvider\ReturnDataProvider;
use Magenx\Rma\Service\OrderEligibility;
use Magenx\Rma\Service\RmaSubmitService;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\Exception\NoSuchEntityException;

class CreateGuestReturn implements ResolverInterface
{
    use ReturnInputTrait;
    use GuestOrderLookupTrait;

    /**
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param OrderEligibility $orderEligibility
     * @param RmaSubmitService $rmaSubmitService
     * @param ReturnDataProvider $returnDataProvider
     */
    public function __construct(
        protected readonly OrderRepositoryInterface $orderRepository,
        protected readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        protected readonly OrderEligibility $orderEligibility,
        protected readonly RmaSubmitService $rmaSubmitService,
        protected readonly ReturnDataProvider $returnDataProvider
    ) {
    }

    /**
     * @param Field $field
     * @param mixed $context
     * @param ResolveInfo $info
     * @param array|null $value
     * @param array|null $args
     * @return array
     * @throws GraphQlInputException
     * @throws GraphQlAuthorizationException
     * @throws GraphQlNoSuchEntityException|NoSuchEntityException
     */
    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null): array
    {
        $input = $args['input'] ?? [];
        $this->validateRequiredFields($input, [
            'order_number' => 'Order number',
            'email' => 'Email',
            'reason_id' => 'Reason ID',
            'resolution_type_id' => 'Resolution type ID',
            'items' => 'Items',
        ]);

        $order = $this->findGuestOrder($input['order_number'], $input['email']);

        if (!$this->orderEligibility->isOrderEligible($order)) {
            throw new GraphQlInputException(__('This order is not eligible for a return.'));
        }

        $selectedItems = $this->buildSelectedItems($input['items']);

        try {
            $rma = $this->rmaSubmitService->createRma(
                $order,
                null,
                (string)$order->getCustomerEmail(),
                (string)($order->getBillingAddress()?->getName() ?: 'Guest'),
                (int)$input['reason_id'],
                (int)$input['resolution_type_id'],
                $selectedItems
            );
        } catch (LocalizedException $e) {
            throw new GraphQlInputException(__($e->getMessage()));
        }

        return ['return' => $this->returnDataProvider->formatRma($rma)];
    }
}
