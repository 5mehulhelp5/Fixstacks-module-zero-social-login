<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\SocialLogin\Model;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\Config\Share;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Store\Model\StoreManagerInterface;
use Zero\SocialLogin\Model\Provider\Pool;

/**
 * The POST of the emailed link: takes the token (once) and connects its
 * service to its account. An account whose email is not verified is reset
 * at the same time (Model\AccountReset); one with a verified email is only
 * connected. An account that has a password by now is refused: it signs in
 * with the password and connects under My Account.
 */
class LinkConfirmer
{
    public function __construct(
        private readonly LinkRequests $requests,
        private readonly Authenticator $authenticator,
        private readonly AccountReset $accountReset,
        private readonly Password $password,
        private readonly VerifiedEmail $verifiedEmail,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly Share $share,
        private readonly Pool $pool,
        private readonly EventManagerInterface $eventManager
    ) {
    }

    /**
     * `message`: the success message (Authenticator::connectedMessage), for
     * an account that was not reset.
     *
     * @return array{customer_id: int, label: string, reset: bool, message: Phrase}
     * @throws LoginException
     */
    public function confirm(string $token): array
    {
        $row = $this->requests->take($token);
        $provider = $row ? $this->pool->getAvailableProvider($row['provider']) : null;
        if (!$row || !$provider) {
            throw self::expired();
        }
        $customerId = $row['customer_id'];
        try {
            $customer = $this->customerRepository->getById($customerId);
        } catch (NoSuchEntityException) {
            throw self::expired();
        }
        if ($this->share->isWebsiteScope()
            && (int) $customer->getWebsiteId() !== (int) $this->storeManager->getStore()->getWebsiteId()
        ) {
            throw self::expired();
        }
        $label = $provider->getLabel();
        $profile = new Profile($row['provider'], $row['subject'], $row['email'], true);
        if ($this->password->has($customerId)) {
            throw new LoginException(Authenticator::passwordFirst($label));
        }
        $connected = $this->authenticator->customerFor($profile);
        if ($connected && (int) $connected->getId() !== $customerId) {
            throw new LoginException(__('This %1 account is already connected to another account in our store.', $label));
        }
        $reset = false;
        if (!$connected) {
            $reset = !$this->verifiedEmail->isVerified($customer);
            if ($reset) {
                $this->accountReset->resetAndConnect($customerId, $profile, $label);
            } else {
                $this->authenticator->link($profile, $customerId);
            }
        }
        $this->eventManager->dispatch('zero_sociallogin_link_confirmed', [
            'customer_id' => $customerId,
            'provider' => $row['provider'],
        ]);

        return [
            'customer_id' => $customerId,
            'label' => $label,
            'reset' => $reset,
            'message' => $this->authenticator->connectedMessage($customerId, $row['provider']),
        ];
    }

    public static function expired(): LoginException
    {
        return new LoginException(__('This link has expired or was used already. Continue with the service again to get a new one.'));
    }
}
