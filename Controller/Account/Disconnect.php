<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\SocialLogin\Controller\Account;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Zero\SocialLogin\Model\IdentityStore;
use Zero\SocialLogin\Model\Provider\Pool;
use Zero\SocialLogin\ViewModel\Account as AccountViewModel;

/**
 * Disconnects a service from the signed-in customer's account (POST with
 * the form key, checked by Magento). Not the last way in: an account without
 * a password keeps at least one service.
 */
class Disconnect implements HttpPostActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly CustomerSession $customerSession,
        private readonly IdentityStore $identities,
        private readonly AccountViewModel $account,
        private readonly Pool $pool,
        private readonly ManagerInterface $messageManager,
        private readonly RedirectFactory $redirectFactory
    ) {
    }

    public function execute(): Redirect
    {
        if (!$this->customerSession->isLoggedIn()) {
            return $this->redirectFactory->create()->setPath('customer/account/login');
        }
        $redirect = $this->redirectFactory->create()->setPath('sociallogin/account');
        $customerId = (int) $this->customerSession->getCustomerId();
        $code = $this->request->getParam('provider');
        $code = is_scalar($code) ? (string) $code : '';
        $label = $this->pool->get($code)?->getLabel() ?? $code;
        if (!$this->account->canDisconnect($code)) {
            $this->messageManager->addErrorMessage(__(
                '%1 is the only way into your account. Set a password first: sign out and use "Forgot Your Password?" on the sign-in page.',
                $label
            ));

            return $redirect;
        }
        if ($this->identities->remove($customerId, $code)) {
            $this->messageManager->addSuccessMessage(__('%1 is disconnected.', $label));
        }

        return $redirect;
    }
}
