<?php
/**
 * Copyright © Magenx. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\RmaGraphQl\Model\Resolver\ReturnAttributesMetadata;

use Magenx\Rma\Model\ChoiceListsCache;
use Magento\Framework\GraphQl\Query\Resolver\IdentityInterface;

/**
 * Tags the cached choice-list response so a change to any reason, resolution or
 * condition purges it. Without a tag the full page cache does not store the
 * response at all.
 */
class Identity implements IdentityInterface
{
    /**
     * @param array $resolvedData
     * @return string[]
     */
    public function getIdentities(array $resolvedData): array
    {
        return [ChoiceListsCache::TAG];
    }
}
