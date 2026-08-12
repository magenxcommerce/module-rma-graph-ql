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

use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

/**
 * Shared query plumbing for the paginated return resolvers: bounding the requested page
 * size, and working out which CustomerReturn fields the caller actually asked for.
 */
trait ReturnQueryTrait
{
    /**
     * Upper bound on a requested page size, matching the ceiling core applies to
     * `customerOrders`. Without it, `pageSize: 1000000` is a cheap way to make one
     * request fan out into a very large result set.
     */
    private const MAX_PAGE_SIZE = 300;

    /**
     * @param mixed $requested
     * @param int $default
     * @return int
     */
    protected function clampPageSize($requested, int $default): int
    {
        $pageSize = (int)($requested ?? $default);

        if ($pageSize < 1) {
            return $default;
        }

        return min($pageSize, self::MAX_PAGE_SIZE);
    }

    /**
     * The CustomerReturn field names this query selected, or null when they cannot be
     * determined — in which case callers fetch everything, i.e. the old behaviour.
     *
     * `$wrapperField` names the field holding the CustomerReturn(s) in the response type:
     * `items` for CustomerReturnsOutput, `return` for CreateReturnOutput. Passing null
     * means the resolver returns a CustomerReturn directly.
     *
     * @param ResolveInfo $info
     * @param string|null $wrapperField
     * @return array|null
     */
    protected function selectedReturnFields(ResolveInfo $info, ?string $wrapperField = null): ?array
    {
        $selection = $info->getFieldSelection($wrapperField === null ? 1 : 2);

        if ($wrapperField !== null) {
            $selection = $selection[$wrapperField] ?? null;
        }

        if (!is_array($selection) || empty($selection)) {
            return null;
        }

        return array_keys($selection);
    }
}
