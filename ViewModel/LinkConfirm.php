<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\SocialLogin\ViewModel;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Zero\SocialLogin\Model\LinkRequests;
use Zero\SocialLogin\Model\Provider\Pool;
use Zero\SocialLogin\Model\VerifiedEmail;

/**
 * The emailed link's page: which service, which account, and what
 * connecting does to it.
 */
class LinkConfirm implements ArgumentInterface
{
    private ?array $request = null;

    public function __construct(
        private readonly CustomerSession $session,
        private readonly LinkRequests $requests,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly VerifiedEmail $verifiedEmail,
        private readonly Pool $pool,
        private readonly UrlInterface $url,
        private readonly FormKey $formKey
    ) {
    }

    /**
     * @return array{label: string, email: string, provider_email: string, reset: bool}|null
     */
    public function getRequest(): ?array
    {
        if ($this->request === null) {
            $this->request = $this->load() ?? [];
        }

        return $this->request ?: null;
    }

    public function getPostUrl(): string
    {
        return $this->url->getUrl('sociallogin/link/confirmpost');
    }

    public function getCancelUrl(): string
    {
        return $this->url->getUrl('customer/account/login');
    }

    public function getFormKey(): string
    {
        return $this->formKey->getFormKey();
    }

    private function load(): ?array
    {
        $row = $this->requests->peek((string) $this->session->getData(LinkRequests::SESSION_KEY));
        if (!$row) {
            return null;
        }
        try {
            $customer = $this->customerRepository->getById($row['customer_id']);
        } catch (NoSuchEntityException) {
            return null;
        }

        return [
            'label' => $this->pool->get($row['provider'])?->getLabel() ?? $row['provider'],
            'email' => (string) $customer->getEmail(),
            'provider_email' => $row['email'],
            'reset' => !$this->verifiedEmail->isVerified($customer),
        ];
    }
}
