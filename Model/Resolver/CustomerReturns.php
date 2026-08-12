<?php
/**
 * Mage-OS
 * Copyright (c) Mage-OS Association (https://mage-os.org/)
 * SPDX-License-Identifier: MIT
 *
 * Forked from mage-os/module-rma 2.4.1 into Magenx_Rma / Magenx_RmaGraphQl;
 * identifiers renamed, GraphQL surface split into a sibling module.
 * Modified by MagenX: added the `sort` argument (CustomerReturnsSortInput), defaulting to created_at DESC.
 */
declare(strict_types=1);

namespace Magenx\RmaGraphQl\Model\Resolver;

use Magenx\Rma\Api\Data\RMAInterface;
use Magenx\Rma\Api\RMARepositoryInterface;
use Magenx\RmaGraphQl\Model\Resolver\DataProvider\ReturnDataProvider;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\GraphQl\Model\Query\ContextInterface;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\Exception\NoSuchEntityException;

class CustomerReturns implements ResolverInterface
{
    use ReturnQueryTrait;

    /**
     * @param RMARepositoryInterface $rmaRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param ReturnDataProvider $returnDataProvider
     * @param SortOrderBuilder $sortOrderBuilder
     */
    public function __construct(
        protected readonly RMARepositoryInterface $rmaRepository,
        protected readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        protected readonly ReturnDataProvider $returnDataProvider,
        protected readonly SortOrderBuilder $sortOrderBuilder
    ) {
    }

    /**
     * Maps the GraphQL sort argument onto a collection sort order.
     *
     * Defaults to newest first: without an explicit sort order the collection
     * comes back in entity_id order (oldest first), which is never what a
     * customer expects from a list of their own return requests.
     *
     * @param array|null $sort
     * @return SortOrder
     */
    private function buildSortOrder(?array $sort): SortOrder
    {
        $fields = [
            'CREATED_AT' => RMAInterface::CREATED_AT,
            'RMA_ID' => RMAInterface::ENTITY_ID,
        ];

        $field = $fields[$sort['sort_field'] ?? ''] ?? RMAInterface::CREATED_AT;
        $direction = strtoupper((string)($sort['sort_direction'] ?? SortOrder::SORT_DESC));

        if ($direction !== SortOrder::SORT_ASC) {
            $direction = SortOrder::SORT_DESC;
        }

        return $this->sortOrderBuilder
            ->setField($field)
            ->setDirection($direction)
            ->create();
    }

    /**
     * @param Field $field
     * @param ContextInterface $context
     * @param ResolveInfo $info
     * @param array|null $value
     * @param array|null $args
     * @return array
     * @throws GraphQlAuthorizationException|NoSuchEntityException
     */
    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null): array
    {
        if ($context->getExtensionAttributes()->getIsCustomer() === false) {
            throw new GraphQlAuthorizationException(__('The current customer isn\'t authorized.'));
        }

        $customerId = (int)$context->getUserId();
        $pageSize = $this->clampPageSize($args['pageSize'] ?? null, 20);
        $currentPage = max(1, (int)($args['currentPage'] ?? 1));

        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('customer_id', $customerId)
            ->setPageSize($pageSize)
            ->setCurrentPage($currentPage)
            ->addSortOrder(
                $this->buildSortOrder($args['sort'] ?? null)
            )
            ->create();

        $searchResults = $this->rmaRepository->getList($searchCriteria);

        $items = $this->returnDataProvider->formatRmaList(
            $searchResults->getItems(),
            $this->selectedReturnFields($info, 'items')
        );

        return [
            'items' => $items,
            'total_count' => $searchResults->getTotalCount(),
            'page_info' => [
                'page_size' => $pageSize,
                'current_page' => $currentPage,
                'total_pages' => $pageSize ? (int)ceil($searchResults->getTotalCount() / $pageSize) : 0,
            ],
        ];
    }
}
