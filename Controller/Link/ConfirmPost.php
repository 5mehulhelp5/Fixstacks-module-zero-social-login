<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\SocialLogin\Controller\Link;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface;
use Zero\SocialLogin\Model\Config;
use Zero\SocialLogin\Model\LinkConfirmer;
use Zero\SocialLogin\Model\LinkRequests;
use Zero\SocialLogin\Model\LoginException;

/**
 * "Connect" on the emailed link's page (form key checked by Magento): the
 * service is connected (Model\LinkConfirmer). Nobody is signed in; a reset
 * account signed in in this browser is signed out.
 */
class ConfirmPost implements HttpPostActionInterface
{
    public function __construct(
        private readonly RedirectFactory $redirectFactory,
        private readonly ForwardFactory $forwardFactory,
        private readonly CustomerSession $session,
        private readonly ManagerInterface $messageManager,
        private readonly LinkConfirmer $confirmer,
        private readonly Config $config
    ) {
    }

    public function execute(): ResultInterface
    {
        if (!$this->config->isEnabled()) {
            return $this->forwardFactory->create()->forward('noroute');
        }
        $token = (string) $this->session->getData(LinkRequests::SESSION_KEY);
        $this->session->unsetData(LinkRequests::SESSION_KEY);
        $redirect = $this->redirectFactory->create();
        try {
            $result = $this->confirmer->confirm($token);
        } catch (LoginException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());

            return $redirect->setPath('customer/account/login');
        }
        $signedIn = $this->session->isLoggedIn()
            && (int) $this->session->getCustomerId() === $result['customer_id'];
        if ($result['reset']) {
            if ($signedIn) {
                $this->session->logout()->setLastCustomerId(null);
                $this->session->regenerateId();
            }
            $this->messageManager->addSuccessMessage(__(
                '%1 is connected. The account\'s other sign-in services were removed and every device was signed out. Continue with %1 to sign in.',
                $result['label']
            ));

            return $redirect->setPath('customer/account/login');
        }
        // The account's services (R-B7-5) only to its signed-in owner; a browser that is not signed
        // in gets the plain message and sees the list on its next sign-in (My Account > Sign-In Methods).
        $this->messageManager->addSuccessMessage(
            $signedIn ? $result['message'] : __('%1 is connected. You can now sign in with it.', $result['label'])
        );

        return $redirect->setPath($signedIn ? 'sociallogin/account' : 'customer/account/login');
    }
}
