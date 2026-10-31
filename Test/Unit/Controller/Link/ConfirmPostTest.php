<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\SocialLogin\Test\Unit\Controller\Link;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Zero\SocialLogin\Controller\Link\ConfirmPost;
use Zero\SocialLogin\Model\Config;
use Zero\SocialLogin\Model\LinkConfirmer;
use Zero\SocialLogin\Model\LinkRequests;
use Zero\SocialLogin\Model\LoginException;
use Zero\SocialLogin\Test\Unit\SessionStub;

/**
 * "Connect" on the emailed link's page: a POST only (Magento checks the form key of an HttpPostActionInterface that
 * does not opt out of CSRF), the token comes from the session and is dropped from it; nobody is signed in, a reset
 * account signed in here is signed out, and the list of services goes to the signed-in owner only (R-B7-5).
 *
 * The collaborators are mocks so each test can add expectations to the ones it is about.
 */
#[AllowMockObjectsWithoutExpectations]
class ConfirmPostTest extends TestCase
{
    use SessionStub;

    private const TOKEN = 'AbCdEfGhIjKlMnOpQrStUvWxYz0123456789abcdefg';

    private LinkConfirmer&MockObject $confirmer;
    private ManagerInterface&MockObject $messages;
    private ?string $path = null;

    protected function setUp(): void
    {
        $this->confirmer = $this->createMock(LinkConfirmer::class);
        $this->messages = $this->createMock(ManagerInterface::class);
        $this->sessionData[LinkRequests::SESSION_KEY] = self::TOKEN;
    }

    private function controller(bool $loggedIn = false, ?int $customerId = null): ConfirmPost
    {
        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setPath')->willReturnCallback(function (string $path) use (&$redirect) {
            $this->path = $path;

            return $redirect;
        });
        $redirects = $this->createStub(RedirectFactory::class);
        $redirects->method('create')->willReturn($redirect);
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn(true);

        return new ConfirmPost(
            $redirects,
            $this->createStub(ForwardFactory::class),
            $this->customerSession($loggedIn, $customerId),
            $this->messages,
            $this->confirmer,
            $config
        );
    }

    private function confirms(bool $reset): void
    {
        $this->confirmer->expects($this->once())->method('confirm')->with(self::TOKEN)->willReturnCallback(
            function () use ($reset) {
                $this->assertArrayNotHasKey(LinkRequests::SESSION_KEY, $this->sessionData, 'dropped before it is used');

                return [
                    'customer_id' => 5,
                    'label' => 'Google',
                    'reset' => $reset,
                    'message' => __('Google is connected. You can now sign in with it. Connected services: Facebook, Google.'),
                ];
            }
        );
    }

    public function testIsAPostActionWithTheFormKeyChecked(): void
    {
        $controller = $this->controller();

        $this->assertInstanceOf(HttpPostActionInterface::class, $controller);
        $this->assertNotInstanceOf(HttpGetActionInterface::class, $controller, 'a GET (a mail scanner) never confirms');
        $this->assertNotInstanceOf(CsrfAwareActionInterface::class, $controller, 'no opt-out of the form key check');
    }

    public function testAResetAccountSignedInHereIsSignedOut(): void
    {
        $this->confirms(true);
        $this->messages->expects($this->once())->method('addSuccessMessage')->with($this->callback(
            fn ($m) => (string) $m === 'Google is connected. The account\'s other sign-in services were removed and every device was signed out. Continue with Google to sign in.'
        ));

        $this->controller(true, 5)->execute();

        $this->assertSame(['unsetData', 'logout', 'setLastCustomerId', 'regenerateId'], $this->sessionCalls);
        $this->assertSame('customer/account/login', $this->path);
    }

    public function testAnotherCustomerSignedInHereStaysSignedIn(): void
    {
        $this->confirms(true);

        $this->controller(true, 9)->execute();

        $this->assertNotContains('logout', $this->sessionCalls);
        $this->assertSame('customer/account/login', $this->path);
    }

    public function testTheSignedInOwnerSeesTheServicesList(): void
    {
        $this->confirms(false);
        $this->messages->expects($this->once())->method('addSuccessMessage')->with($this->callback(
            fn ($m) => (string) $m === 'Google is connected. You can now sign in with it. Connected services: Facebook, Google.'
        ));

        $this->controller(true, 5)->execute();

        $this->assertNotContains('logout', $this->sessionCalls, 'a verified account keeps its sessions');
        $this->assertSame('sociallogin/account', $this->path);
    }

    public function testABrowserThatIsNotSignedInGetsThePlainMessage(): void
    {
        $this->confirms(false);
        $this->messages->expects($this->once())->method('addSuccessMessage')->with($this->callback(
            fn ($m) => (string) $m === 'Google is connected. You can now sign in with it.'
        ));

        $this->controller()->execute();

        $this->assertSame('customer/account/login', $this->path, 'nobody is signed in by the link');
    }

    public function testARefusalShowsItsMessageAndSignsNobodyOut(): void
    {
        $this->confirmer->method('confirm')->willThrowException(new LoginException(__('This link has expired or was used already.')));
        $this->messages->expects($this->once())->method('addErrorMessage')->with('This link has expired or was used already.');
        $this->messages->expects($this->never())->method('addSuccessMessage');

        $this->controller(true, 5)->execute();

        $this->assertSame(['unsetData'], $this->sessionCalls);
        $this->assertArrayNotHasKey(LinkRequests::SESSION_KEY, $this->sessionData, 'a refused token is gone from the session too');
        $this->assertSame('customer/account/login', $this->path);
    }
}
