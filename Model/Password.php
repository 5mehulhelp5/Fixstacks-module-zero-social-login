<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\SocialLogin\Model;

use Magento\Customer\Model\CustomerRegistry;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Whether an account has a password. An account with one connects a service
 * only after a sign-in with that password (Model\PendingLink); an account
 * without one (made by a service) through an emailed link (Model\LinkRequests).
 */
class Password
{
    public function __construct(
        private readonly CustomerRegistry $customerRegistry
    ) {
    }

    public function has(int $customerId): bool
    {
        try {
            return (string) $this->customerRegistry->retrieveSecureData($customerId)->getPasswordHash() !== '';
        } catch (NoSuchEntityException) {
            return false;
        }
    }
}
