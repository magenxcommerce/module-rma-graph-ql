<?php
/**
 * Mage-OS
 * Copyright (c) Mage-OS Association (https://mage-os.org/)
 * SPDX-License-Identifier: MIT
 *
 * Forked from mage-os/module-rma 2.4.1 into Magenx_Rma / Magenx_RmaGraphQl;
 * identifiers renamed, GraphQL surface split into a sibling module.
 * Modified by MagenX: answers "not available" while returns are off for the store;
 * someone else's return or order answers like a missing one.
 */
declare(strict_types=1);

namespace Magenx\RmaGraphQl\Model\Resolver;

use Magenx\Rma\Api\Data\RMAInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;

trait CustomerRmaAccessTrait
{
    /**
     * @param mixed $context
     * @param int $rmaId
     * @return RMAInterface
     * @throws GraphQlAuthorizationException
     * @throws GraphQlInputException
     * @throws GraphQlNoSuchEntityException
     */
    protected function loadCustomerRma($context, int $rmaId): RMAInterface
    {
        // Fail closed: only a signed-in customer with an id passes (API PRD NFR-A2).
        if ($context->getExtensionAttributes()->getIsCustomer() !== true || !(int)$context->getUserId()) {
            throw new GraphQlAuthorizationException(__('The current customer isn\'t authorized.'));
        }

        $this->assertReturnsAvailable($context);

        if (!$rmaId) {
            throw new GraphQlInputException(__('RMA ID is required.'));
        }

        try {
            $rma = $this->rmaRepository->get($rmaId);
        } catch (NoSuchEntityException) {
            throw new GraphQlNoSuchEntityException(__('RMA with ID "%1" does not exist.', $rmaId));
        }

        if ((int)$rma->getCustomerId() !== (int)$context->getUserId()) {
            // Someone else's return answers exactly like a missing one (API PRD BR-3).
            throw new GraphQlNoSuchEntityException(__('RMA with ID "%1" does not exist.', $rmaId));
        }

        return $rma;
    }
}
