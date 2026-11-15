<?php
/**
 * Copyright © Magenx. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\RmaGraphQl\Model\Resolver;

use Magento\Framework\GraphQl\Exception\GraphQlInputException;

/**
 * While returns are off for the store of the request, customer and guest operations
 * answer "not available" (API PRD FR-C2). Needs a ModuleConfig as $this->moduleConfig.
 */
trait ReturnsAvailabilityTrait
{
    /**
     * @param mixed $context
     * @return bool
     */
    protected function areReturnsAvailable($context): bool
    {
        $store = $context->getExtensionAttributes()?->getStore();

        return $store !== null && $this->moduleConfig->isEnabled((int)$store->getId());
    }

    /**
     * @param mixed $context
     * @return void
     * @throws GraphQlInputException
     */
    protected function assertReturnsAvailable($context): void
    {
        if (!$this->areReturnsAvailable($context)) {
            throw new GraphQlInputException(__('Returns are not available for this store.'));
        }
    }
}
