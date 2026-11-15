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
 * Modified by MagenX: exposes the withdrawal and return-shipment fields.
 * Modified by MagenX: order numbers and item names come from two plain selects instead
 * of repository-loaded orders.
 */
declare(strict_types=1);

namespace Magenx\RmaGraphQl\Model\Resolver\DataProvider;

use Magenx\Rma\Api\CommentRepositoryInterface;
use Magenx\Rma\Api\Data\CommentInterface;
use Magenx\Rma\Api\Data\RMAInterface;
use Magenx\Rma\Api\ItemRepositoryInterface;
use Magenx\Rma\Service\LabelResolver;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\ResourceConnection;

class ReturnDataProvider
{
    /**
     * @param LabelResolver $labelResolver
     * @param ItemRepositoryInterface $itemRepository
     * @param CommentRepositoryInterface $commentRepository
     * @param ResourceConnection $resourceConnection
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     */
    public function __construct(
        protected readonly LabelResolver $labelResolver,
        protected readonly ItemRepositoryInterface $itemRepository,
        protected readonly CommentRepositoryInterface $commentRepository,
        protected readonly ResourceConnection $resourceConnection,
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

        $needsOrderNumber = $this->isSelected($selectedFields, ['order_number']);
        $needsItems = $this->isSelected($selectedFields, ['items']);
        $needsComments = $this->isSelected($selectedFields, ['comments']);
        $needsStatus = $this->isSelected($selectedFields, ['status']);
        $needsReason = $this->isSelected($selectedFields, ['reason']);
        $needsResolution = $this->isSelected($selectedFields, ['resolution_type']);

        $orderNumbers = $needsOrderNumber ? $this->loadOrderNumbers($rmas) : [];
        $itemsByRma = $needsItems ? $this->loadItemsByRma($rmaIds) : [];
        $orderItems = $needsItems ? $this->loadOrderItems($itemsByRma) : [];
        $commentsByRma = $needsComments ? $this->loadVisibleCommentsByRma($rmaIds) : [];

        $formatted = [];

        foreach ($rmas as $rma) {
            $rmaId = (int)$rma->getEntityId();

            $formatted[] = [
                'rma_id' => $rma->getEntityId(),
                'increment_id' => $rma->getIncrementId(),
                'order_number' => $orderNumbers[(int)$rma->getOrderId()] ?? null,
                'status' => $needsStatus
                    ? $this->labelResolver->resolveAsArray(LabelResolver::TYPE_STATUS, $rma->getStatusId())
                    : null,
                'reason' => $needsReason
                    ? $this->labelResolver->resolveAsArray(LabelResolver::TYPE_REASON, $rma->getReasonId())
                    : null,
                'resolution_type' => $needsResolution
                    ? $this->labelResolver->resolveAsArray(
                        LabelResolver::TYPE_RESOLUTION_TYPE,
                        $rma->getResolutionTypeId()
                    )
                    : null,
                'items' => array_map(
                    fn($item): array => $this->buildItemData($item, $orderItems),
                    $itemsByRma[$rmaId] ?? []
                ),
                'comments' => $commentsByRma[$rmaId] ?? [],
                'created_at' => $rma->getCreatedAt(),
                'updated_at' => $rma->getUpdatedAt(),
                'is_withdrawal' => $rma->isWithdrawal(),
                'withdrawal_declared_at' => $rma->getWithdrawalDeclaredAt(),
                'helpdesk_ticket_code' => $rma->getHelpdeskTicketCode(),
                'return_carrier' => $rma->getReturnCarrier(),
                'return_tracking_number' => $rma->getReturnTrackingNumber(),
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
     * Order numbers of the page, keyed by order id, from one query.
     *
     * Only the increment id is shown, so this reads that column instead of loading
     * orders through the repository, which hydrates shipping, tax and payment data
     * for each one.
     *
     * @param RMAInterface[] $rmas
     * @return array<int, string>
     */
    protected function loadOrderNumbers(array $rmas): array
    {
        $orderIds = array_values(array_unique(array_filter(
            array_map(static fn(RMAInterface $rma): int => (int)$rma->getOrderId(), $rmas)
        )));

        if (empty($orderIds)) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName('sales_order'), ['entity_id', 'increment_id'])
            ->where('entity_id IN (?)', $orderIds);

        return array_map('strval', $connection->fetchPairs($select));
    }

    /**
     * Name and SKU of every order line the page's return items point at, from one query.
     *
     * @param array<int, array> $itemsByRma
     * @return array<int, array{name: string|null, sku: string|null}> keyed by order item id
     */
    protected function loadOrderItems(array $itemsByRma): array
    {
        $orderItemIds = [];
        foreach ($itemsByRma as $items) {
            foreach ($items as $item) {
                $orderItemIds[] = (int)$item->getOrderItemId();
            }
        }
        $orderItemIds = array_values(array_unique(array_filter($orderItemIds)));

        if (empty($orderItemIds)) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName('sales_order_item'), ['item_id', 'name', 'sku'])
            ->where('item_id IN (?)', $orderItemIds);

        $rows = [];
        foreach ($connection->fetchAll($select) as $row) {
            $rows[(int)$row['item_id']] = ['name' => $row['name'], 'sku' => $row['sku']];
        }

        return $rows;
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
     * @param object $item
     * @param array<int, array{name: string|null, sku: string|null}> $orderItems
     * @return array
     */
    protected function buildItemData(object $item, array $orderItems): array
    {
        $orderItem = $orderItems[(int) $item->getOrderItemId()] ?? null;

        return [
            'item_id' => $item->getEntityId(),
            'order_item_id' => $item->getOrderItemId(),
            'product_name' => $orderItem['name'] ?? null,
            'product_sku' => $orderItem['sku'] ?? null,
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
