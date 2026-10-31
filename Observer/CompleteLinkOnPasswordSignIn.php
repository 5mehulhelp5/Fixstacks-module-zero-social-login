<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\SocialLogin\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Zero\SocialLogin\Model\PendingLink;

/**
 * `customer_customer_authenticated` (the password was right, storefront
 * only): a service this account tried in this browser is connected now.
 */
class CompleteLinkOnPasswordSignIn implements ObserverInterface
{
    public function __construct(
        private readonly PendingLink $pendingLink
    ) {
    }

    /**
     * @inheritdoc
     */
    public function execute(Observer $observer): void
    {
        $customer = $observer->getEvent()->getData('model');
        if ($customer && $customer->getId()) {
            $this->pendingLink->complete((int) $customer->getId());
        }
    }
}
