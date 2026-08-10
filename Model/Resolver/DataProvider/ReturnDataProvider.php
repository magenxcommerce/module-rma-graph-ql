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

namespace Magenx\RmaGraphQl\Model\Resolver\DataProvider;

use Magenx\Rma\Api\CommentRepositoryInterface;
use Magenx\Rma\Api\Data\CommentInterface;
use Magenx\Rma\Api\Data\RMAInterface;
use Magenx\Rma\Api\ItemRepositoryInterface;
use Magenx\Rma\Service\LabelResolver;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;

class ReturnDataProvider
{
    /**
     * @param LabelResolver $labelResolver
     * @param ItemRepositoryInterface $itemRepository
     * @param CommentRepositoryInterface $commentRepository
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     */
    public function __construct(
        protected readonly LabelResolver $labelResolver,
        protected readonly ItemRepositoryInterface $itemRepository,
        protected readonly CommentRepositoryInterface $commentRepository,
        protected readonly OrderRepositoryInterface $orderRepository,
        protected readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    /**
     * @param RMAInterface $rma
     * @return array
     */
    public function formatRma(RMAInterface $rma): array
    {
        try {
            $order = $this->orderRepository->get($rma->getOrderId());
            $orderNumber = $order->getIncrementId();
            $orderItemsMap = $this->buildOrderItemsMap($order);
        } catch (NoSuchEntityException) {
            $orderNumber = null;
            $orderItemsMap = [];
        }

        return [
            'rma_id' => $rma->getEntityId(),
            'increment_id' => $rma->getIncrementId(),
            'order_number' => $orderNumber,
            'status' => $this->labelResolver->resolveAsArray(LabelResolver::TYPE_STATUS, $rma->getStatusId()),
            'reason' => $this->labelResolver->resolveAsArray(LabelResolver::TYPE_REASON, $rma->getReasonId()),
            'resolution_type' => $this->labelResolver->resolveAsArray(LabelResolver::TYPE_RESOLUTION_TYPE, $rma->getResolutionTypeId()),
            'items' => $this->getItems((int)$rma->getEntityId(), $orderItemsMap),
            'comments' => $this->getVisibleComments((int)$rma->getEntityId()),
            'created_at' => $rma->getCreatedAt(),
            'updated_at' => $rma->getUpdatedAt(),
        ];
    }

    /**
     * @param int $rmaId
     * @param array $orderItemsMap
     * @return array
     */
    protected function getItems(int $rmaId, array $orderItemsMap): array
    {
        return array_map(
            fn($item) => $this->buildItemData($item, $orderItemsMap),
            $this->loadRmaItems($rmaId)
        );
    }

    /**
     * @param int $rmaId
     * @return array
     */
    protected function loadRmaItems(int $rmaId): array
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('rma_id', $rmaId)
            ->create();

        return $this->itemRepository->getList($searchCriteria)->getItems();
    }

    /**
     * @param OrderInterface $order
     * @return array
     */
    protected function buildOrderItemsMap(OrderInterface $order): array
    {
        $map = [];
        foreach ($order->getItems() as $orderItem) {
            $map[(int)$orderItem->getItemId()] = $orderItem;
        }

        return $map;
    }

    /**
     * @param object $item
     * @param array $orderItemsMap
     * @return array
     */
    protected function buildItemData(object $item, array $orderItemsMap): array
    {
        $orderItem = $orderItemsMap[(int) $item->getOrderItemId()] ?? null;

        return [
            'item_id' => $item->getEntityId(),
            'order_item_id' => $item->getOrderItemId(),
            'product_name' => $orderItem?->getName(),
            'product_sku' => $orderItem?->getSku(),
            'qty_requested' => $item->getQtyRequested(),
            'qty_approved' => $item->getQtyApproved(),
            'qty_returned' => $item->getQtyReturned(),
            'condition' => $this->resolveItemCondition($item->getConditionId()),
        ];
    }

    /**
     * @param int|null $conditionId
     * @return array|null
     */
    protected function resolveItemCondition(?int $conditionId): ?array
    {
        if (!$conditionId) {
            return null;
        }

        return $this->labelResolver->resolveAsArray(LabelResolver::TYPE_ITEM_CONDITION, $conditionId);
    }

    /**
     * @param int $rmaId
     * @return array
     */
    public function getVisibleComments(int $rmaId): array
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('rma_id', $rmaId)
            ->addFilter('is_visible_to_customer', 1)
            ->create();

        $comments = $this->commentRepository->getList($searchCriteria)->getItems();

        return array_map(fn(CommentInterface $c) => [
            'comment_id' => $c->getEntityId(),
            'author_type' => $c->getAuthorType(),
            'author_name' => $c->getAuthorName(),
            'comment' => $c->getComment(),
            'created_at' => $c->getCreatedAt(),
        ], array_values($comments));
    }
}
