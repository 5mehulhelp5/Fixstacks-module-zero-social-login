<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\SocialLogin\Controller\Link;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\View\Result\PageFactory;
use Zero\SocialLogin\Model\Config;
use Zero\SocialLogin\Model\LinkConfirmer;
use Zero\SocialLogin\Model\LinkRequests;

/**
 * The emailed link (`?token=`): the token moves into the session and the
 * browser comes back without it, so it never sits in a page's URL, a Referer
 * or an analytics hit. The page then asks to confirm with a POST
 * (ConfirmPost); a GET never uses the token, so a mail scanner that opens
 * links changes nothing.
 */
class Confirm implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly PageFactory $pageFactory,
        private readonly ForwardFactory $forwardFactory,
        private readonly RedirectFactory $redirectFactory,
        private readonly CustomerSession $session,
        private readonly ManagerInterface $messageManager,
        private readonly LinkRequests $requests,
        private readonly Config $config
    ) {
    }

    public function execute(): ResultInterface
    {
        if (!$this->config->isEnabled()) {
            return $this->forwardFactory->create()->forward('noroute');
        }
        $token = $this->request->getParam('token');
        if ($token !== null) {
            $token = is_string($token) ? $token : '';
            if (LinkRequests::valid($token) && $this->requests->peek($token)) {
                $this->session->setData(LinkRequests::SESSION_KEY, $token);
                $redirect = $this->redirectFactory->create()->setPath('sociallogin/link/confirm');
            } else {
                $this->session->unsetData(LinkRequests::SESSION_KEY);
                $this->messageManager->addErrorMessage(LinkConfirmer::expired()->getMessage());
                $redirect = $this->redirectFactory->create()->setPath('customer/account/login');
            }
            $redirect->setHeader('Referrer-Policy', 'no-referrer', true);

            return $redirect;
        }
        $token = (string) $this->session->getData(LinkRequests::SESSION_KEY);
        if (!LinkRequests::valid($token) || !$this->requests->peek($token)) {
            $this->messageManager->addErrorMessage(LinkConfirmer::expired()->getMessage());

            return $this->redirectFactory->create()->setPath('customer/account/login');
        }
        $page = $this->pageFactory->create();
        $page->getConfig()->setRobots('NOINDEX,NOFOLLOW');
        $page->getConfig()->getTitle()->set(__('Connect a sign-in service'));
        $page->setHeader('Referrer-Policy', 'no-referrer', true);

        return $page;
    }
}
