<?php
/**
 * Mage-OS
 * Copyright (c) Mage-OS Association (https://mage-os.org/)
 * SPDX-License-Identifier: MIT
 *
 * Forked from mage-os/module-rma 2.4.1 into Magenx_Rma / Magenx_RmaGraphQl;
 * identifiers renamed, GraphQL surface split into a sibling module.
 * Modified by MagenX: added batch formatting and field-selection awareness so a list
 * of returns costs a fixed number of queries instead of three per row.
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
     * Field names on CustomerReturn whose resolution needs the order loaded.
     */
    private const ORDER_BACKED_FIELDS = ['order_number', 'items'];

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
     * Format a single RMA.
     *
     * @param RMAInterface $rma
     * @param array|null $selectedFields Field names the GraphQL query asked for; null means all.
     * @return array
     */
    public function formatRma(RMAInterface $rma, ?array $selectedFields = null): array
    {
        return $this->formatRmaList([$rma], $selectedFields)[0];
    }

    /**
     * Format a page of RMAs using a fixed number of queries.
     *
     * The per-row cost used to be one order load (which hydrates the order's whole item
     * collection), one RMA-item query and one comment query. For a 20-row page that is
     * 60+ round trips, paid whether or not the caller selected those fields. Here the
     * orders, items and comments for the whole page are fetched once each and indexed in
     * memory, and each of the three fetches is skipped outright when nothing in the
     * selection needs it.
     *
     * @param RMAInterface[] $rmas
     * @param array|null $selectedFields Field names the GraphQL query asked for; null means all.
     * @return array
     */
    public function formatRmaList(array $rmas, ?array $selectedFields = null): array
    {
        $rmas = array_values($rmas);

        if (empty($rmas)) {
            return [];
        }

        $rmaIds = array_map(static fn(RMAInterface $rma): int => (int)$rma->getEntityId(), $rmas);

        $needsOrder = $this->isSelected($selectedFields, self::ORDER_BACKED_FIELDS);
        $needsItems = $this->isSelected($selectedFields, ['items']);
        $needsComments = $this->isSelected($selectedFields, ['comments']);

        $orders = $needsOrder ? $this->loadOrders($rmas) : [];
        $itemsByRma = $needsItems ? $this->loadItemsByRma($rmaIds) : [];
        $commentsByRma = $needsComments ? $this->loadVisibleCommentsByRma($rmaIds) : [];

        $formatted = [];

        foreach ($rmas as $rma) {
            $rmaId = (int)$rma->getEntityId();
            $order = $orders[(int)$rma->getOrderId()] ?? null;
            $orderItemsMap = $order !== null ? $this->buildOrderItemsMap($order) : [];

            $formatted[] = [
                'rma_id' => $rma->getEntityId(),
                'increment_id' => $rma->getIncrementId(),
                'order_number' => $order?->getIncrementId(),
                'status' => $this->labelResolver->resolveAsArray(LabelResolver::TYPE_STATUS, $rma->getStatusId()),
                'reason' => $this->labelResolver->resolveAsArray(LabelResolver::TYPE_REASON, $rma->getReasonId()),
                'resolution_type' => $this->labelResolver->resolveAsArray(
                    LabelResolver::TYPE_RESOLUTION_TYPE,
                    $rma->getResolutionTypeId()
                ),
                'items' => array_map(
                    fn($item): array => $this->buildItemData($item, $orderItemsMap),
                    $itemsByRma[$rmaId] ?? []
                ),
                'comments' => $commentsByRma[$rmaId] ?? [],
                'created_at' => $rma->getCreatedAt(),
                'updated_at' => $rma->getUpdatedAt(),
            ];
        }

        return $formatted;
    }

    /**
     * Whether any of the given fields was requested. A null selection means the caller
     * did not narrow the query, so everything is fetched as before.
     *
     * @param array|null $selectedFields
     * @param string[] $fields
     * @return bool
     */
    protected function isSelected(?array $selectedFields, array $fields): bool
    {
        if ($selectedFields === null) {
            return true;
        }

        return (bool)array_intersect($fields, $selectedFields);
    }

    /**
     * Load every order referenced by the page, keyed by order id.
     *
     * OrderRepository has no batch getById, so this goes through getList with an `in`
     * filter — one query for the page instead of one per row.
     *
     * @param RMAInterface[] $rmas
     * @return array<int, OrderInterface>
     */
    protected function loadOrders(array $rmas): array
    {
        $orderIds = array_values(array_unique(array_filter(
            array_map(static fn(RMAInterface $rma): int => (int)$rma->getOrderId(), $rmas)
        )));

        if (empty($orderIds)) {
            return [];
        }

        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('entity_id', $orderIds, 'in')
            ->create();

        $orders = [];

        try {
            foreach ($this->orderRepository->getList($searchCriteria)->getItems() as $order) {
                $orders[(int)$order->getEntityId()] = $order;
            }
        } catch (NoSuchEntityException) {
            return [];
        }

        return $orders;
    }

    /**
     * @param int[] $rmaIds
     * @return array<int, array>
     */
    protected function loadItemsByRma(array $rmaIds): array
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('rma_id', $rmaIds, 'in')
            ->create();

        $grouped = [];

        foreach ($this->itemRepository->getList($searchCriteria)->getItems() as $item) {
            $grouped[(int)$item->getRmaId()][] = $item;
        }

        return $grouped;
    }

    /**
     * @param int[] $rmaIds
     * @return array<int, array>
     */
    protected function loadVisibleCommentsByRma(array $rmaIds): array
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('rma_id', $rmaIds, 'in')
            ->addFilter('is_visible_to_customer', 1)
            ->create();

        $grouped = [];

        foreach ($this->commentRepository->getList($searchCriteria)->getItems() as $comment) {
            $grouped[(int)$comment->getRmaId()][] = $this->buildCommentData($comment);
        }

        return $grouped;
    }

    /**
     * @param CommentInterface $comment
     * @return array
     */
    protected function buildCommentData(CommentInterface $comment): array
    {
        return [
            'comment_id' => $comment->getEntityId(),
            'author_type' => $comment->getAuthorType(),
            'author_name' => $comment->getAuthorName(),
            'comment' => $comment->getComment(),
            'created_at' => $comment->getCreatedAt(),
        ];
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
}
