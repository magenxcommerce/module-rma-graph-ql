<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\RmaGraphQl\Model\Resolver;

use Magenx\Rma\Service\WithdrawalSubmitService;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * `submitWithdrawal`: declare withdrawal from an order (EU right of withdrawal).
 *
 * Records the declaration (and its confirmation email) through the configured
 * recorder, then creates the withdrawal RMA or cancels the unshipped order, all
 * server-side in WithdrawalSubmitService. The response only says what the
 * consumer needs to know; staff review reasons stay internal.
 *
 * Open to guests. Abuse protection (Turnstile) sits at the storefront proxy.
 */
class SubmitWithdrawal implements ResolverInterface
{
    use WithdrawalOrderLookupTrait;

    private const MAX_ITEMS = 100;
    private const MAX_NAME_LENGTH = 255;
    private const MAX_MESSAGE_LENGTH = 2000;

    /**
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param WithdrawalSubmitService $withdrawalSubmitService
     */
    public function __construct(
        protected readonly OrderRepositoryInterface $orderRepository,
        protected readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        protected readonly WithdrawalSubmitService $withdrawalSubmitService
    ) {
    }

    /**
     * @param Field $field
     * @param mixed $context
     * @param ResolveInfo $info
     * @param array|null $value
     * @param array|null $args
     * @return array
     * @throws GraphQlInputException
     */
    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null): array
    {
        $input = $args['input'] ?? [];
        $name = trim((string)($input['name'] ?? ''));
        $message = trim((string)($input['message'] ?? ''));

        if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new GraphQlInputException(__('Please enter your name.'));
        }
        if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            throw new GraphQlInputException(
                __('The message can be at most %1 characters long.', self::MAX_MESSAGE_LENGTH)
            );
        }
        $items = $this->parseItems($input['items'] ?? []);

        $order = $this->findWithdrawalOrder(
            (string)($input['order_number'] ?? ''),
            (string)($input['email'] ?? ''),
            $context
        );

        try {
            // The confirmation goes to the address on the order: the caller either
            // typed exactly that address or is the logged-in customer who owns it.
            $submission = $this->withdrawalSubmitService->submit(
                $order,
                $name,
                (string)$order->getCustomerEmail(),
                $items,
                $message
            );
        } catch (LocalizedException $e) {
            throw new GraphQlInputException(__($e->getMessage()), $e);
        }

        $result = $submission->result;

        return [
            'ticket_code' => $submission->declaration->ticketCode,
            'received_at' => $submission->declaration->declaredAt,
            'return_number' => $result?->rma?->getIncrementId(),
            'order_canceled' => (bool)$result?->orderCanceled,
        ];
    }

    /**
     * @param array $itemsInput
     * @return array<int, int> Order item id => qty
     * @throws GraphQlInputException
     */
    protected function parseItems(array $itemsInput): array
    {
        if (count($itemsInput) > self::MAX_ITEMS) {
            throw new GraphQlInputException(__('Too many items.'));
        }

        $items = [];
        foreach ($itemsInput as $item) {
            $id = (int)($item['order_item_id'] ?? 0);
            $qty = (int)($item['qty'] ?? 0);
            if ($id <= 0 || $qty <= 0) {
                throw new GraphQlInputException(__('Each item needs an order item ID and a quantity above zero.'));
            }
            $items[$id] = ($items[$id] ?? 0) + $qty;
        }

        return $items;
    }
}
