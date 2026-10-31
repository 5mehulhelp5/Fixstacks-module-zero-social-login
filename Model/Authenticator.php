<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\SocialLogin\Model;

use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Customer\Model\Config\Share;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Store\Model\StoreManagerInterface;
use Zero\SocialLogin\Model\Provider\Pool;

/**
 * A verified service profile → a store account:
 * 1. the service account already connected to one: that account;
 * 2. a store account with the same email: nobody is signed in. When the
 *    service verified the email (Google, Apple), the account connects it
 *    after a sign-in in this browser (Model\PendingLink: with its password,
 *    one set through "Forgot Your Password?", or a service already
 *    connected); an account without a password also gets an emailed link
 *    (Model\LinkRequests). Otherwise (Facebook) the customer signs in and
 *    connects it in My Account;
 * 3. otherwise a new account without a password, with the welcome email.
 */
class Authenticator
{
    public function __construct(
        private readonly IdentityStore $identities,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly AccountManagementInterface $accountManagement,
        private readonly CustomerInterfaceFactory $customerFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly Share $share,
        private readonly Confirmation $confirmation,
        private readonly Pool $pool,
        private readonly Password $password,
        private readonly LinkRequests $linkRequests,
        private readonly LinkMailer $linkMailer
    ) {
    }

    /**
     * The account to sign in.
     *
     * @throws LoginException
     */
    public function login(Profile $profile): CustomerInterface
    {
        $customer = $this->customerFor($profile);
        if ($customer) {
            $customer = $this->requireConfirmed($customer, $profile);
            $this->identities->touch((int) $customer->getId(), $profile);

            return $customer;
        }
        if ($profile->email === '') {
            throw new LoginException(__(
                'Your %1 account didn\'t share an email address with us. Sign up with your email instead.',
                $this->label($profile)
            ));
        }
        $customer = $this->customerByEmail($profile->email);
        if ($customer) {
            if (!$profile->emailVerified) {
                throw new LoginException(__(
                    'An account with %1 already exists. Sign in with your password, then connect %2 under My Account > Sign-In Methods.',
                    $profile->email,
                    $this->label($profile)
                ));
            }
            $this->pendLink($customer, $profile);
        }

        $customer = $this->createCustomer($profile);
        $this->identities->add((int) $customer->getId(), $profile);
        $customer = $this->requireConfirmed($customer, $profile);
        $this->identities->touch((int) $customer->getId(), $profile);

        return $customer;
    }

    /**
     * Connects the service account to a signed-in customer (My Account, or
     * the sign-in that completes a Model\PendingLink); an emailed link for
     * it stops working.
     *
     * @throws LoginException
     */
    public function link(Profile $profile, int $customerId): void
    {
        $connected = $this->customerFor($profile);
        if ($connected && (int) $connected->getId() !== $customerId) {
            throw new LoginException(__(
                'This %1 account is already connected to another account in our store.',
                $this->label($profile)
            ));
        }
        if (!$connected) {
            $this->assertNoOtherOfTheService($customerId, $profile);
            $this->identities->add($customerId, $profile);
        }
        $this->linkRequests->cancel($customerId, $profile->provider);
    }

    /**
     * The success message once a service is connected. When the account has
     * other services too, it names them all (labels only), so a service a
     * stranger connected before the owner got in (an account registered with
     * the owner's email, R-B7-5) is seen and can be disconnected.
     */
    public function connectedMessage(int $customerId, string $provider): Phrase
    {
        $label = $this->pool->get($provider)?->getLabel() ?? $provider;
        $connected = $this->identities->forCustomer($customerId);
        unset($connected[$provider]);
        if (!$connected) {
            return __('%1 is connected. You can now sign in with it.', $label);
        }
        $labels = [$label];
        foreach (array_keys($connected) as $code) {
            $labels[] = $this->pool->get((string) $code)?->getLabel() ?? (string) $code;
        }
        sort($labels, SORT_NATURAL | SORT_FLAG_CASE);

        return __(
            '%1 is connected. You can now sign in with it. Connected services: %2. If you don\'t recognise one, disconnect it under My Account > Sign-In Methods.',
            $label,
            implode(', ', $labels)
        );
    }

    /**
     * "Sign in with your password first": an account with a password connects
     * a service only after a sign-in with it.
     */
    public static function passwordFirst(string $label): Phrase
    {
        return __(
            'This account has a password. Sign in with it (or reset it with "Forgot Your Password?" and sign in), then connect %1 under My Account > Sign-In Methods.',
            $label
        );
    }

    /**
     * An existing account with the profile's verified email: no sign-in.
     * With a password, the sign-in that follows in this browser connects the
     * service; without one, an emailed link does too.
     *
     * @throws LinkPendingException
     * @throws LoginException
     */
    private function pendLink(CustomerInterface $customer, Profile $profile): never
    {
        $customerId = (int) $customer->getId();
        $this->assertNoOtherOfTheService($customerId, $profile);
        if ($this->password->has($customerId)) {
            throw new LinkPendingException(__(
                'An account with %1 already exists. To connect %2, sign in now with your password (forgot it? Use "Forgot Your Password?", then sign in) or with a service already connected to it.',
                $profile->email,
                $this->label($profile)
            ), $customerId, false);
        }
        $this->linkMailer->send($customer, $profile, $this->linkRequests->create($customerId, $profile));
        throw new LinkPendingException(__(
            'An account with %1 already exists. To connect %2, open the link we\'ve emailed to %1, or sign in now with a service already connected to it.',
            $profile->email,
            $this->label($profile)
        ), $customerId, true);
    }

    private function assertNoOtherOfTheService(int $customerId, Profile $profile): void
    {
        if (isset($this->identities->forCustomer($customerId)[$profile->provider])) {
            throw new LoginException(__(
                'Your account is already connected to a different %1 account. Disconnect it under My Account > Sign-In Methods first.',
                $this->label($profile)
            ));
        }
    }

    /**
     * The account this service account is connected to, on this website when
     * accounts are shared per website.
     */
    public function customerFor(Profile $profile): ?CustomerInterface
    {
        $websiteId = (int) $this->storeManager->getStore()->getWebsiteId();
        foreach ($this->identities->findCustomerIds($profile->provider, $profile->subject) as $customerId) {
            try {
                $customer = $this->customerRepository->getById($customerId);
            } catch (NoSuchEntityException) {
                continue;
            }
            if (!$this->share->isWebsiteScope() || (int) $customer->getWebsiteId() === $websiteId) {
                return $customer;
            }
        }

        return null;
    }

    private function customerByEmail(string $email): ?CustomerInterface
    {
        try {
            return $this->customerRepository->get($email, (int) $this->storeManager->getStore()->getWebsiteId());
        } catch (NoSuchEntityException) {
            return null;
        }
    }

    private function createCustomer(Profile $profile): CustomerInterface
    {
        $store = $this->storeManager->getStore();
        $firstName = $profile->firstName !== '' ? $profile->firstName : $this->nameFromEmail($profile->email);
        $customer = $this->customerFactory->create();
        $customer->setEmail($profile->email)
            ->setFirstname(mb_substr($firstName, 0, 255))
            ->setLastname(mb_substr($profile->lastName !== '' ? $profile->lastName : '-', 0, 255))
            ->setWebsiteId((int) $store->getWebsiteId())
            ->setStoreId((int) $store->getId());

        return $this->accountManagement->createAccount($customer);
    }

    /**
     * Accounts waiting for email confirmation: confirmed when the service
     * verified that same email, otherwise the customer confirms first.
     *
     * @return CustomerInterface The account as it is now
     * @throws LoginException
     */
    private function requireConfirmed(CustomerInterface $customer, Profile $profile): CustomerInterface
    {
        if (!$this->confirmation->isPending($customer)) {
            return $customer;
        }
        if ($profile->emailVerified && strcasecmp((string) $customer->getEmail(), $profile->email) === 0) {
            $this->confirmation->confirm((int) $customer->getId());

            return $this->customerRepository->getById((int) $customer->getId());
        }
        $this->confirmation->sendLink($customer);
        throw new LoginException(__(
            'We\'ve sent a confirmation link to %1. Open it, then continue with %2 again.',
            $customer->getEmail(),
            $this->label($profile)
        ));
    }

    private function nameFromEmail(string $email): string
    {
        $name = trim((string) preg_replace('/[^\p{L}\p{M}\d]+/u', ' ', strstr($email, '@', true) ?: $email));

        return $name !== '' ? mb_convert_case($name, MB_CASE_TITLE) : '-';
    }

    private function label(Profile $profile): string
    {
        return $this->pool->get($profile->provider)?->getLabel() ?? $profile->provider;
    }
}
