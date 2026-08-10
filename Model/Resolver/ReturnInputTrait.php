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

use Magento\Framework\GraphQl\Exception\GraphQlInputException;

trait ReturnInputTrait
{
    /**
     * @param array $input
     * @param array $requiredFields
     * @throws GraphQlInputException
     */
    protected function validateRequiredFields(array $input, array $requiredFields): void
    {
        foreach ($requiredFields as $field => $label) {
            if (empty($input[$field])) {
                throw new GraphQlInputException(__('%1 is required.', $label));
            }
        }
    }

    /**
     * @param array $itemsInput
     * @return array
     */
    protected function buildSelectedItems(array $itemsInput): array
    {
        $selected = [];
        foreach ($itemsInput as $item) {
            $selected[(int)$item['order_item_id']] = [
                'qty_requested' => (int)$item['qty_requested'],
                'condition_id' => isset($item['condition_id']) ? (int)$item['condition_id'] : null,
            ];
        }

        return $selected;
    }
}
