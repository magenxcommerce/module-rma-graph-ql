<?php
/**
 * Copyright © Magenx. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\RmaGraphQl\Model\Security;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Psr\Log\LoggerInterface;

/**
 * Server-side limit for the public return operations (API PRD NFR-SEC2): guest proof
 * (order number + email) and the withdrawal declaration. The storefront's bot check
 * sits in front, but the GraphQL endpoint can be reached without it.
 *
 * Counts per client address and per email in fixed one-minute windows, in the
 * application cache. The counter is not atomic; under a burst a few extra calls
 * may pass, which is acceptable for an abuse limit.
 */
class GuestRateLimiter
{
    /**
     * Calls allowed per client and per email in one window (API PRD NFR-SEC2, TBC).
     */
    public const LIMIT = 10;

    private const WINDOW_SECONDS = 60;
    private const KEY_PREFIX = 'magenx_rma_rl_';

    /**
     * @param CacheInterface $cache
     * @param RemoteAddress $remoteAddress
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly RemoteAddress $remoteAddress,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Counts one call and refuses it once the client or the email is over the limit.
     *
     * @param string $operation
     * @param string $email
     * @return void
     * @throws GraphQlInputException
     */
    public function assertAllowed(string $operation, string $email): void
    {
        $window = intdiv(time(), self::WINDOW_SECONDS);
        $subjects = [
            'ip' => (string)$this->remoteAddress->getRemoteAddress(),
            'email' => strtolower(trim($email)),
        ];

        $over = false;
        foreach ($subjects as $kind => $subject) {
            if ($subject === '') {
                continue;
            }
            $key = self::KEY_PREFIX . sha1($operation . '|' . $kind . '|' . $subject . '|' . $window);
            $count = (int)$this->cache->load($key) + 1;
            $this->cache->save((string)$count, $key, [], self::WINDOW_SECONDS * 2);
            $over = $over || $count > self::LIMIT;
        }

        if ($over) {
            // Count only: no address or email in the log (API PRD NFR-O1).
            $this->logger->info('RMA: public operation rate limit hit', ['operation' => $operation]);
            throw new GraphQlInputException(__('Too many attempts. Please try again in a minute.'));
        }
    }

    /**
     * Logs a refused guest proof without the email (API PRD NFR-O1).
     *
     * @param int|null $storeId
     * @return void
     */
    public function recordRefusedProof(?int $storeId): void
    {
        $this->logger->info('RMA: guest proof refused', ['store_id' => $storeId]);
    }
}
