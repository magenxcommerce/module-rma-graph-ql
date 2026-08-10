<?php
/**
 * Copyright © Magenx. All rights reserved.
 * SPDX-License-Identifier: MIT
 *
 * Original work, part of the Magenx fork of mage-os/module-rma
 * (MIT, Copyright (c) Mage-OS Association) — see LICENSE.
 */
declare(strict_types=1);

namespace Magenx\RmaGraphQl\Model\Resolver;

use Magenx\Rma\Api\ItemConditionRepositoryInterface;
use Magenx\Rma\Api\ReasonRepositoryInterface;
use Magenx\Rma\Api\ResolutionTypeRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\GraphQl\Model\Query\ContextInterface;

/**
 * Enumerates the active return reasons, resolution types and item conditions a
 * customer (or guest) can choose from when creating a return.
 *
 * The base RMA schema only resolves a single lookup value by id on an existing
 * RMA (via LabelResolver). The create-return form, however, needs the full list
 * of selectable values to populate its dropdowns — so this resolver exposes them
 * in one round trip. It is intentionally unauthenticated: the values are public
 * store configuration and both customer and guest create flows need them.
 */
class ReturnAttributesMetadata implements ResolverInterface
{
    /**
     * @param ReasonRepositoryInterface $reasonRepository
     * @param ResolutionTypeRepositoryInterface $resolutionTypeRepository
     * @param ItemConditionRepositoryInterface $itemConditionRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     */
    public function __construct(
        protected readonly ReasonRepositoryInterface $reasonRepository,
        protected readonly ResolutionTypeRepositoryInterface $resolutionTypeRepository,
        protected readonly ItemConditionRepositoryInterface $itemConditionRepository,
        protected readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    /**
     * @param Field $field
     * @param ContextInterface $context
     * @param ResolveInfo $info
     * @param array|null $value
     * @param array|null $args
     * @return array
     */
    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null): array
    {
        $storeId = (int)$context->getExtensionAttributes()->getStore()->getId();

        return [
            'reasons' => $this->format(
                $this->reasonRepository->getList($this->activeCriteria())->getItems(),
                $storeId
            ),
            'resolution_types' => $this->format(
                $this->resolutionTypeRepository->getList($this->activeCriteria())->getItems(),
                $storeId
            ),
            'conditions' => $this->format(
                $this->itemConditionRepository->getList($this->activeCriteria())->getItems(),
                $storeId
            ),
        ];
    }

    /**
     * Build a fresh "is_active = 1" search criteria. SearchCriteriaBuilder
     * resets on create(), so a new one is required per repository call.
     *
     * @return SearchCriteriaInterface
     */
    protected function activeCriteria(): SearchCriteriaInterface
    {
        return $this->searchCriteriaBuilder
            ->addFilter('is_active', 1)
            ->create();
    }

    /**
     * Map lookup entities to the ReturnLookupValue shape, ordered by sort order.
     *
     * @param object[] $items
     * @param int $storeId
     * @return array<int, array{id: int|null, code: string, label: string}>
     */
    protected function format(array $items, int $storeId): array
    {
        $items = array_values($items);
        usort($items, static fn(object $a, object $b): int => $a->getSortOrder() <=> $b->getSortOrder());

        return array_map(static fn(object $item): array => [
            'id' => $item->getEntityId(),
            'code' => $item->getCode(),
            'label' => $item->getStoreLabel($storeId),
        ], $items);
    }
}
