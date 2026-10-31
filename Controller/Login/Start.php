<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\SocialLogin\Controller\Login;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Forward;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Zero\SocialLogin\Model\Flow;
use Zero\SocialLogin\Model\FlowStore;
use Zero\SocialLogin\Model\Provider\Pool;
use Zero\SocialLogin\Model\Urls;

/**
 * A "Continue with …" button: opens a sign-in attempt and sends the
 * customer to the service. A plain link (GET): it changes nothing but the
 * session's list of open attempts, so the buttons can sit on cached pages
 * and inside other forms. A signed-in customer gets the "connect" flow
 * (My Account > Sign-In Methods).
 */
class Start implements HttpGetActionInterface
{
    /** Pages a customer can come from and go back to. */
    public const FROM = ['login', 'register', 'checkout', 'account'];

    public function __construct(
        private readonly RequestInterface $request,
        private readonly Pool $pool,
        private readonly FlowStore $flows,
        private readonly Urls $urls,
        private readonly CustomerSession $customerSession,
        private readonly RedirectFactory $redirectFactory,
        private readonly ForwardFactory $forwardFactory
    ) {
    }

    /**
     * @return Redirect|Forward
     */
    public function execute()
    {
        $providerParam = $this->request->getParam('provider');
        $provider = $this->pool->getAvailableProvider(is_scalar($providerParam) ? (string) $providerParam : '');
        if (!$provider) {
            return $this->forwardFactory->create()->forward('noroute');
        }
        $from = $this->request->getParam('from');
        $from = is_scalar($from) ? (string) $from : '';
        $from = in_array($from, self::FROM, true) ? $from : 'login';
        if ($this->customerSession->isLoggedIn()) {
            $flow = $this->flows->start(
                $provider->getCode(),
                Flow::MODE_LINK,
                'account',
                (int) $this->customerSession->getCustomerId()
            );
        } else {
            $flow = $this->flows->start($provider->getCode(), Flow::MODE_LOGIN, $from, null);
        }

        return $this->redirectFactory->create()
            ->setUrl($provider->authorizationUrl($flow, $this->urls->callback($provider->getCode())));
    }
}
