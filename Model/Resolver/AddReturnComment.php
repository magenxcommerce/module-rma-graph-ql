<?php
/**
 * Mage-OS
 * Copyright (c) Mage-OS Association (https://mage-os.org/)
 * SPDX-License-Identifier: MIT
 *
 * Forked from mage-os/module-rma 2.4.1 into Magenx_Rma / Magenx_RmaGraphQl;
 * identifiers renamed, GraphQL surface split into a sibling module.
 * Modified by MagenX: storage errors are not shown to the client;
 * answers "not available" while returns are off for the store;
 * someone else's return or order answers like a missing one; closed returns take no
 * comments and a comment is at most 2000 characters.
 */
declare(strict_types=1);

namespace Magenx\RmaGraphQl\Model\Resolver;

use Magenx\Rma\Helper\ModuleConfig;
use Magenx\Rma\Model\RMA\StatusCodes;
use Magenx\Rma\Model\RMA\StatusResolver;
use Magenx\Rma\Api\CommentRepositoryInterface;
use Magenx\Rma\Api\Data\CommentInterface;
use Magenx\Rma\Api\Data\CommentInterfaceFactory;
use Magenx\Rma\Api\RMARepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\GraphQl\Model\Query\ContextInterface;

class AddReturnComment implements ResolverInterface
{
    use ReturnsAvailabilityTrait;
    use ReturnInputTrait;

    private const MAX_COMMENT_LENGTH = 2000;

    /**
     * @param RMARepositoryInterface $rmaRepository
     * @param CommentRepositoryInterface $commentRepository
     * @param CommentInterfaceFactory $commentFactory
     * @param CustomerRepositoryInterface $customerRepository
     * @param ModuleConfig $moduleConfig
     * @param StatusResolver $statusResolver
     */
    public function __construct(
        protected readonly RMARepositoryInterface $rmaRepository,
        protected readonly CommentRepositoryInterface $commentRepository,
        protected readonly CommentInterfaceFactory $commentFactory,
        protected readonly CustomerRepositoryInterface $customerRepository,
        protected readonly ModuleConfig $moduleConfig,
        protected readonly StatusResolver $statusResolver
    ) {
    }

    /**
     * @param Field $field
     * @param ContextInterface $context
     * @param ResolveInfo $info
     * @param array|null $value
     * @param array|null $args
     * @return array
     * @throws GraphQlAuthorizationException
     * @throws GraphQlInputException
     * @throws GraphQlNoSuchEntityException
     */
    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null): array
    {
        // Fail closed: only a signed-in customer with an id passes (API PRD NFR-A2).
        if ($context->getExtensionAttributes()->getIsCustomer() !== true || !(int)$context->getUserId()) {
            throw new GraphQlAuthorizationException(__('The current customer isn\'t authorized.'));
        }

        $this->assertReturnsAvailable($context);

        $input = $args['input'] ?? [];
        $rmaId = (int)($input['rma_id'] ?? 0);
        $commentText = trim($input['comment'] ?? '');

        if (!$rmaId) {
            throw new GraphQlInputException(__('RMA ID is required.'));
        }
        if ($commentText === '') {
            throw new GraphQlInputException(__('Comment text is required.'));
        }
        if (mb_strlen($commentText) > self::MAX_COMMENT_LENGTH) {
            throw new GraphQlInputException(
                __('The text can be at most %1 characters long.', self::MAX_COMMENT_LENGTH)
            );
        }

        try {
            $rma = $this->rmaRepository->get($rmaId);
        } catch (NoSuchEntityException) {
            throw new GraphQlNoSuchEntityException(__('RMA with ID "%1" does not exist.', $rmaId));
        }

        $customerId = (int)$context->getUserId();
        if ((int)$rma->getCustomerId() !== $customerId) {
            // Someone else's return answers exactly like a missing one (API PRD BR-3).
            throw new GraphQlNoSuchEntityException(__('RMA with ID "%1" does not exist.', $rmaId));
        }

        // Messages are for open returns only (API PRD F4).
        if (in_array($this->statusResolver->getCodeById($rma->getStatusId()), StatusCodes::FINAL_CODES, true)) {
            throw new GraphQlInputException(__('This return is closed. Contact us if you need help with it.'));
        }

        $authorName = $this->getCustomerName($customerId);

        $comment = $this->commentFactory->create();
        $comment->setRmaId($rmaId);
        $comment->setAuthorType(CommentInterface::AUTHOR_TYPE_CUSTOMER);
        $comment->setAuthorName($authorName);
        $comment->setComment($commentText);
        $comment->setIsVisibleToCustomer(true);

        $comment = $this->runWrite(fn() => $this->commentRepository->save($comment));

        return [
            'comment_id' => $comment->getEntityId(),
            'author_type' => $comment->getAuthorType(),
            'author_name' => $comment->getAuthorName(),
            'comment' => $comment->getComment(),
            'created_at' => $comment->getCreatedAt(),
        ];
    }

    /**
     * @param int $customerId
     * @return string
     */
    protected function getCustomerName(int $customerId): string
    {
        try {
            $customer = $this->customerRepository->getById($customerId);
            return trim($customer->getFirstname() . ' ' . $customer->getLastname());
        } catch (NoSuchEntityException | LocalizedException) {
            return 'Customer';
        }
    }
}
