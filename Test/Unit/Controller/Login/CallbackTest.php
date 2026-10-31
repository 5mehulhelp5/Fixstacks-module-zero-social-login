<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\SocialLogin\Test\Unit\Controller\Login;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Account\Redirect as AccountRedirect;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zero\SocialLogin\Controller\Login\Callback;
use Zero\SocialLogin\Model\Authenticator;
use Zero\SocialLogin\Model\Flow;
use Zero\SocialLogin\Model\FlowStore;
use Zero\SocialLogin\Model\LinkPendingException;
use Zero\SocialLogin\Model\PendingLink;
use Zero\SocialLogin\Model\Profile;
use Zero\SocialLogin\Model\Provider\Pool;
use Zero\SocialLogin\Model\Provider\ProviderInterface;
use Zero\SocialLogin\Model\Urls;

/**
 * The callback with an existing account: nobody is signed in, the service waits in the session (PendingLink), a
 * notice is shown and zero_sociallogin_link_pending carries `emailed`; a sign-in with a service already connected
 * completes a waiting one and fires zero_sociallogin_link_completed (the headless hand-off adds social=connected).
 */
class CallbackTest extends TestCase
{
    /** @var list<array{0: string, 1: array}> */
    private array $events = [];
    /** @var string[] */
    private array $steps = [];
    private ?string $url = null;
    private Profile $profile;
    private Flow $flow;

    protected function setUp(): void
    {
        $this->profile = new Profile('google', 'g-1', 'ann@example.com', true);
        $this->flow = new Flow('google', 'state1', 'nonce', 'verifier', Flow::MODE_LOGIN, 'login', null, time());
    }

    private function controller(\Closure $login, ?string $completed = null): Callback
    {
        $events = $this->createStub(EventManagerInterface::class);
        $events->method('dispatch')->willReturnCallback(function (string $name, array $data = []) {
            $this->events[] = [$name, $data];
        });
        $request = $this->createStub(HttpRequest::class);
        $request->method('isPost')->willReturn(false);
        $request->method('getParam')->willReturnCallback(fn (string $name) => match ($name) {
            'provider' => 'google',
            'state' => 'state1',
            default => null,
        });
        $request->method('getParams')->willReturn(['code' => 'c']);
        $google = $this->createStub(ProviderInterface::class);
        $google->method('getLabel')->willReturn('Google');
        $google->method('isAvailable')->willReturn(true);
        $google->method('profile')->willReturn($this->profile);
        $flows = $this->createStub(FlowStore::class);
        $flows->method('take')->willReturnCallback(fn (string $state) => $state === 'state1' ? $this->flow : null);
        $authenticator = $this->createStub(Authenticator::class);
        $authenticator->method('login')->willReturnCallback($login);
        $session = $this->createStub(CustomerSession::class);
        $session->method('isLoggedIn')->willReturn(false);
        $session->method('setCustomerDataAsLoggedIn')->willReturnCallback(function (CustomerInterface $c) use (&$session) {
            $this->steps[] = 'signedIn:' . $c->getId();

            return $session;
        });
        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setUrl')->willReturnCallback(function (string $url) use (&$redirect) {
            $this->url = $url;

            return $redirect;
        });
        $redirects = $this->createStub(RedirectFactory::class);
        $redirects->method('create')->willReturn($redirect);
        $messages = $this->createStub(ManagerInterface::class);
        foreach (['addNoticeMessage' => 'notice', 'addErrorMessage' => 'error'] as $method => $kind) {
            $messages->method($method)->willReturnCallback(function ($m) use ($kind, &$messages) {
                $this->steps[] = $kind . ': ' . $m;

                return $messages;
            });
        }
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(fn (string $path) => '/' . $path);
        $pending = $this->createStub(PendingLink::class);
        $pending->method('remember')->willReturnCallback(function (int $id, Profile $profile) {
            $this->steps[] = 'remember:' . $id . ':' . $profile->provider . ':' . $profile->subject;
        });
        $pending->method('complete')->willReturnCallback(function (int $id) use ($completed) {
            $this->steps[] = 'complete:' . $id;

            return $completed;
        });
        $accountRedirect = $this->createStub(AccountRedirect::class);
        $accountRedirect->method('getRedirect')->willReturn($redirect);

        return new Callback(
            $events,
            $request,
            new Pool(['google' => $google]),
            $flows,
            $this->createStub(Urls::class),
            $authenticator,
            $session,
            $accountRedirect,
            $redirects,
            $messages,
            $url,
            $this->createStub(CookieManagerInterface::class),
            $this->createStub(CookieMetadataFactory::class),
            $this->createStub(LoggerInterface::class),
            $pending
        );
    }

    private function eventNames(): array
    {
        return array_column($this->events, 0);
    }

    public function testAnExistingAccountWaitsInTheSessionAndNobodyIsSignedIn(): void
    {
        $this->controller(function () {
            throw new LinkPendingException(__('An account with ann@example.com already exists.'), 5, false);
        })->execute();

        $this->assertSame(['remember:5:google:g-1', 'notice: An account with ann@example.com already exists.'], $this->steps);
        $this->assertSame(['zero_sociallogin_link_pending'], $this->eventNames(), 'no zero_sociallogin_login');
        $this->assertSame(
            ['flow' => $this->flow, 'profile' => $this->profile, 'customer_id' => 5, 'emailed' => false],
            $this->events[0][1]
        );
        $this->assertSame('/customer/account/login', $this->url);
    }

    public function testAnEmailedLinkIsReportedAndCheckoutComesBackToTheCheckout(): void
    {
        $this->flow = new Flow('google', 'state1', 'nonce', 'verifier', Flow::MODE_LOGIN, 'checkout', null, time());

        $this->controller(function () {
            throw new LinkPendingException(__('open the link'), 5, true);
        })->execute();

        $this->assertTrue($this->events[0][1]['emailed']);
        $this->assertSame('/zero/checkout/index', $this->url);
    }

    public function testASignInThatCompletesAWaitingServiceSaysSo(): void
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn(5);

        $this->controller(fn () => $customer, 'Facebook')->execute();

        $this->assertSame(['signedIn:5', 'complete:5'], $this->steps);
        $this->assertSame(['zero_sociallogin_link_completed', 'zero_sociallogin_login'], $this->eventNames());
        $this->assertSame(['customer_id' => 5, 'label' => 'Facebook', 'flow' => $this->flow], $this->events[0][1]);
    }

    public function testASignInWithNothingWaitingFiresOnlyTheLogin(): void
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn(5);

        $this->controller(fn () => $customer)->execute();

        $this->assertSame(['signedIn:5', 'complete:5'], $this->steps, 'any sign-in clears a waiting service');
        $this->assertSame(['zero_sociallogin_login'], $this->eventNames());
    }
}
