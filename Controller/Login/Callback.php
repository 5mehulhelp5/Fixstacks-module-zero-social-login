<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\SocialLogin\Controller\Login;

use Magento\Customer\Model\Account\Redirect as AccountRedirect;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\UrlInterface;
use Psr\Log\LoggerInterface;
use Zero\SocialLogin\Model\Authenticator;
use Zero\SocialLogin\Model\Flow;
use Zero\SocialLogin\Model\FlowStore;
use Zero\SocialLogin\Model\LinkPendingException;
use Zero\SocialLogin\Model\LoginException;
use Zero\SocialLogin\Model\PendingLink;
use Zero\SocialLogin\Model\Profile;
use Zero\SocialLogin\Model\Provider\Pool;
use Zero\SocialLogin\Model\ServiceException;
use Zero\SocialLogin\Model\Urls;

/**
 * The redirect URI: the service sends the customer back here with a code.
 *
 * Apple sends a cross-site POST (form_post). It has no session cookie
 * (SameSite=Lax), and Plugin\KeepSessionOnApplePost keeps Magento from
 * starting a new session that would replace the customer's. The POST is
 * only turned into a GET to this same URL (303): a top-level GET does carry
 * the session cookie, and the attempt is checked there.
 */
class Callback implements HttpGetActionInterface, HttpPostActionInterface, CsrfAwareActionInterface
{
    /** Callback parameters passed from Apple's POST to the GET. */
    private const POST_PARAMS = ['code', 'state', 'user', 'error'];

    public function __construct(
        private readonly EventManagerInterface $eventManager,
        private readonly HttpRequest $request,
        private readonly Pool $pool,
        private readonly FlowStore $flows,
        private readonly Urls $urls,
        private readonly Authenticator $authenticator,
        private readonly CustomerSession $customerSession,
        private readonly AccountRedirect $accountRedirect,
        private readonly RedirectFactory $redirectFactory,
        private readonly ManagerInterface $messageManager,
        private readonly UrlInterface $url,
        private readonly CookieManagerInterface $cookieManager,
        private readonly CookieMetadataFactory $cookieMetadataFactory,
        private readonly LoggerInterface $logger,
        private readonly PendingLink $pendingLink
    ) {
    }

    public function execute(): ResultInterface
    {
        $code = $this->request->getParam('provider');
        $code = is_scalar($code) ? (string) $code : '';
        if ($this->request->isPost()) {
            $query = [];
            foreach (self::POST_PARAMS as $name) {
                $value = $this->request->getPost($name);
                if (is_string($value) && $value !== '' && strlen($value) < 4096) {
                    $query[$name] = $value;
                }
            }

            return $this->redirectFactory->create()
                ->setHttpResponseCode(303)
                ->setUrl($this->urls->callback($code) . '?' . http_build_query($query));
        }

        $stateParam = $this->request->getParam('state');
        $flow = $this->flows->take(is_scalar($stateParam) ? (string) $stateParam : '');
        $provider = $this->pool->getAvailableProvider($code);
        if (!$flow || !$provider || $flow->provider !== $code) {
            $this->messageManager->addErrorMessage(
                __('Your sign-in attempt has expired or was started in another browser. Please try again.')
            );

            return $this->redirect('customer/account/login');
        }
        $label = $provider->getLabel();
        if ($this->request->getParam('error')) {
            $this->messageManager->addNoticeMessage(__('Signing in with %1 was cancelled.', $label));

            return $this->back($flow);
        }

        try {
            $profile = $provider->profile($flow, $this->urls->callback($code), $this->request->getParams());
        } catch (ServiceException $e) {
            $this->logger->warning('Zero Social Login: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(__(
                'We couldn\'t sign you in with %1. Please try again, or use your email and password.',
                $label
            ));

            return $this->back($flow);
        }

        if ($flow->mode === Flow::MODE_LINK) {
            return $this->link($flow, $profile, $label);
        }
        if ($this->customerSession->isLoggedIn()) {
            return $this->redirect('customer/account');
        }
        try {
            $customer = $this->authenticator->login($profile);
        } catch (LinkPendingException $e) {
            // An existing account: connected after a sign-in here, or by the emailed link.
            $this->pendingLink->remember($e->customerId, $profile);
            $this->messageManager->addNoticeMessage($e->getMessage());
            $this->eventManager->dispatch('zero_sociallogin_link_pending', [
                'flow' => $flow,
                'profile' => $profile,
                'customer_id' => $e->customerId,
                'emailed' => $e->emailed,
            ]);

            return $this->redirect($flow->from === 'checkout' ? 'zero/checkout/index' : 'customer/account/login');
        } catch (LoginException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());

            return $this->back($flow);
        } catch (\Exception $e) {
            // E.g. a name Magento's customer validation rejects.
            $this->logger->error('Zero Social Login: ' . $e->getMessage(), ['exception' => $e]);
            $this->messageManager->addErrorMessage(__(
                'We couldn\'t sign you in with %1. Please try again, or use your email and password.',
                $label
            ));

            return $this->back($flow);
        }

        $this->customerSession->setCustomerDataAsLoggedIn($customer);
        // A service of this account waiting for a sign-in here is connected now.
        $connected = $this->pendingLink->complete((int) $customer->getId());

        // Other front ends (a headless storefront) follow this login from here.
        if ($connected !== null) {
            // The success message (with the account's services) is a session message of this theme;
            // a headless storefront learns of the connect from this event.
            $this->eventManager->dispatch('zero_sociallogin_link_completed', [
                'customer_id' => (int) $customer->getId(),
                'label' => $connected,
                'flow' => $flow,
            ]);
        }

        $this->eventManager->dispatch('zero_sociallogin_login', ['customer' => $customer, 'flow' => $flow, 'profile' => $profile]);
        // As core's loginPost: the browser's private content cache belongs to the guest.
        if ($this->cookieManager->getCookie('mage-cache-sessid')) {
            $this->cookieManager->deleteCookie(
                'mage-cache-sessid',
                $this->cookieMetadataFactory->createCookieMetadata()->setPath('/')
            );
        }
        if ($flow->from === 'checkout') {
            return $this->redirect('zero/checkout/index');
        }

        return $this->accountRedirect->getRedirect();
    }

    private function link(Flow $flow, Profile $profile, string $label): ResultInterface
    {
        if (!$this->customerSession->isLoggedIn()
            || (int) $this->customerSession->getCustomerId() !== $flow->customerId
        ) {
            $this->messageManager->addErrorMessage(__('Please sign in, then connect %1 again.', $label));

            return $this->redirect('customer/account/login');
        }
        try {
            $this->authenticator->link($profile, (int) $flow->customerId);
            $this->messageManager->addSuccessMessage(
                $this->authenticator->connectedMessage((int) $flow->customerId, $profile->provider)
            );
        } catch (LoginException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        return $this->redirect('sociallogin/account');
    }

    private function back(Flow $flow): ResultInterface
    {
        return $this->redirect(match ($flow->from) {
            'register' => 'customer/account/create',
            'checkout' => 'zero/checkout/index',
            'account' => 'sociallogin/account',
            default => 'customer/account/login',
        });
    }

    private function redirect(string $path): ResultInterface
    {
        return $this->redirectFactory->create()->setUrl($this->url->getUrl($path));
    }

    /**
     * No form key: the services can't send one. The state parameter, checked
     * against the session, protects the callback instead.
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
