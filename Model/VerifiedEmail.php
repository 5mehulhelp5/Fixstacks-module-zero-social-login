<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\SocialLogin\Model;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\AccountConfirmation;

/**
 * An account's email is verified only when the store requires email
 * confirmation and the account has confirmed it. With confirmation off
 * (Magento's default) anyone can register any address, so no account is.
 */
class VerifiedEmail
{
    public function __construct(
        private readonly AccountConfirmation $accountConfirmation
    ) {
    }

    public function isVerified(CustomerInterface $customer): bool
    {
        return $this->accountConfirmation->isConfirmationRequired(
            (int) $customer->getWebsiteId(),
            (int) $customer->getId(),
            (string) $customer->getEmail()
        ) && (string) $customer->getConfirmation() === '';
    }
}
