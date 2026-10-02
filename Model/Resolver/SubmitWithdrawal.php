<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\RmaGraphQl\Model\Resolver;

use Magenx\Rma\Service\WithdrawalRequest;
use Magenx\Rma\Service\WithdrawalSubmitService;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

/**
 * `submitWithdrawal`: declare withdrawal from a purchase (EU right of withdrawal).
 *
 * The declaration is free text and is recorded as submitted — there is no order
 * lookup here, and pressing "I confirm withdrawal" is the legal act. Matching the
 * order and handling the return is staff work. Open to guests; abuse protection
 * (Turnstile) sits at the storefront proxy and the recorder rate-limits per
 * email address.
 */
class SubmitWithdrawal implements ResolverInterface
{
    private const MAX_SHORT = 255;
    private const MAX_ORDER_NUMBER = 64;
    private const MAX_TEXT = 2000;

    /**
     * @param WithdrawalSubmitService $withdrawalSubmitService
     */
    public function __construct(
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
        $email = trim((string)($input['email'] ?? ''));
        $orderNumber = trim((string)($input['order_number'] ?? ''));
        $items = trim((string)($input['items'] ?? ''));
        $message = trim((string)($input['message'] ?? ''));

        if ($name === '' || mb_strlen($name) > self::MAX_SHORT) {
            throw new GraphQlInputException(__('Please enter your name.'));
        }
        if (mb_strlen($email) > self::MAX_SHORT || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new GraphQlInputException(__('Please enter a valid email address.'));
        }
        if ($orderNumber === '' || mb_strlen($orderNumber) > self::MAX_ORDER_NUMBER) {
            throw new GraphQlInputException(__('Please enter your order number.'));
        }
        if (mb_strlen($items) > self::MAX_TEXT || mb_strlen($message) > self::MAX_TEXT) {
            throw new GraphQlInputException(__('The text can be at most %1 characters long.', self::MAX_TEXT));
        }

        $extension = $context->getExtensionAttributes();
        $customerId = $extension?->getIsCustomer() ? (int)$context->getUserId() : null;

        try {
            $declaration = $this->withdrawalSubmitService->submit(new WithdrawalRequest(
                (int)$extension->getStore()->getId(),
                $name,
                $email,
                $orderNumber,
                $items,
                $message,
                $customerId ?: null
            ));
        } catch (LocalizedException $e) {
            throw new GraphQlInputException(__($e->getMessage()), $e);
        }

        return [
            'ticket_code' => $declaration->ticketCode,
            'received_at' => $declaration->declaredAt,
        ];
    }
}
