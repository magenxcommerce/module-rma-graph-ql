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
    /**
     * @param RMARepositoryInterface $rmaRepository
     * @param CommentRepositoryInterface $commentRepository
     * @param CommentInterfaceFactory $commentFactory
     * @param CustomerRepositoryInterface $customerRepository
     */
    public function __construct(
        protected readonly RMARepositoryInterface $rmaRepository,
        protected readonly CommentRepositoryInterface $commentRepository,
        protected readonly CommentInterfaceFactory $commentFactory,
        protected readonly CustomerRepositoryInterface $customerRepository
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
        if ($context->getExtensionAttributes()->getIsCustomer() === false) {
            throw new GraphQlAuthorizationException(__('The current customer isn\'t authorized.'));
        }

        $input = $args['input'] ?? [];
        $rmaId = (int)($input['rma_id'] ?? 0);
        $commentText = trim($input['comment'] ?? '');

        if (!$rmaId) {
            throw new GraphQlInputException(__('RMA ID is required.'));
        }
        if ($commentText === '') {
            throw new GraphQlInputException(__('Comment text is required.'));
        }

        try {
            $rma = $this->rmaRepository->get($rmaId);
        } catch (NoSuchEntityException) {
            throw new GraphQlNoSuchEntityException(__('RMA with ID "%1" does not exist.', $rmaId));
        }

        $customerId = (int)$context->getUserId();
        if ((int)$rma->getCustomerId() !== $customerId) {
            throw new GraphQlAuthorizationException(__('You are not authorized to comment on this return.'));
        }

        $authorName = $this->getCustomerName($customerId);

        $comment = $this->commentFactory->create();
        $comment->setRmaId($rmaId);
        $comment->setAuthorType(CommentInterface::AUTHOR_TYPE_CUSTOMER);
        $comment->setAuthorName($authorName);
        $comment->setComment($commentText);
        $comment->setIsVisibleToCustomer(true);

        try {
            $comment = $this->commentRepository->save($comment);
        } catch (LocalizedException $e) {
            throw new GraphQlInputException(__($e->getMessage()));
        }

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
