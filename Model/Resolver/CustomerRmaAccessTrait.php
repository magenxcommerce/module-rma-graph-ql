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
        if ($context->getExtensionAttributes()->getIsCustomer() === false) {
            throw new GraphQlAuthorizationException(__('The current customer isn\'t authorized.'));
        }

        if (!$rmaId) {
            throw new GraphQlInputException(__('RMA ID is required.'));
        }

        try {
            $rma = $this->rmaRepository->get($rmaId);
        } catch (NoSuchEntityException) {
            throw new GraphQlNoSuchEntityException(__('RMA with ID "%1" does not exist.', $rmaId));
        }

        if ((int)$rma->getCustomerId() !== (int)$context->getUserId()) {
            throw new GraphQlAuthorizationException(__('You are not authorized to view this return.'));
        }

        return $rma;
    }
}
