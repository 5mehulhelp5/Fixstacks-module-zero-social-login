<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\SocialLogin\Test\Unit\Controller\Link;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Forward;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Zero\SocialLogin\Controller\Link\Confirm;
use Zero\SocialLogin\Model\Config;
use Zero\SocialLogin\Model\LinkRequests;
use Zero\SocialLogin\Test\Unit\SessionStub;

/**
 * The emailed link's GET: a valid token moves from the URL into the session (302, no-referrer); the page that follows
 * is NOINDEX and only asks to confirm. A GET never uses the token, so a mail scanner changes nothing.
 *
 * The collaborators are mocks so each test can add expectations to the ones it is about.
 */
#[AllowMockObjectsWithoutExpectations]
class ConfirmTest extends TestCase
{
    use SessionStub;

    private const TOKEN = 'AbCdEfGhIjKlMnOpQrStUvWxYz0123456789abcdefg';

    private LinkRequests&MockObject $requests;
    private ManagerInterface&MockObject $messages;
    private PageFactory&MockObject $pages;
    private ?string $param = null;
    private bool $enabled = true;
    /** @var array<string, mixed> */
    private array $redirected = [];

    protected function setUp(): void
    {
        $this->requests = $this->createMock(LinkRequests::class);
        $this->requests->expects($this->never())->method('take');
        $this->messages = $this->createMock(ManagerInterface::class);
        $this->pages = $this->createMock(PageFactory::class);
    }

    private function controller(): Confirm
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(fn (string $name) => $name === 'token' ? $this->param : null);
        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setPath')->willReturnCallback(function (string $path) use (&$redirect) {
            $this->redirected['path'] = $path;

            return $redirect;
        });
        $redirect->method('setHeader')->willReturnCallback(function (string $name, string $value) use (&$redirect) {
            $this->redirected[$name] = $value;

            return $redirect;
        });
        $redirects = $this->createStub(RedirectFactory::class);
        $redirects->method('create')->willReturn($redirect);
        $forward = $this->createMock(Forward::class);
        $forward->method('forward')->willReturnSelf();
        $forwards = $this->createStub(ForwardFactory::class);
        $forwards->method('create')->willReturn($forward);
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturnCallback(fn () => $this->enabled);

        return new Confirm(
            $request,
            $this->pages,
            $forwards,
            $redirects,
            $this->customerSession(),
            $this->messages,
            $this->requests,
            $config
        );
    }

    public function testIsAGetAndNotAPostAction(): void
    {
        $this->assertInstanceOf(HttpGetActionInterface::class, $this->controller());
        $this->assertNotInstanceOf(HttpPostActionInterface::class, $this->controller());
    }

    public function testTokenMovesFromTheUrlToTheSession(): void
    {
        $this->param = self::TOKEN;
        $this->requests->expects($this->once())->method('peek')->with(self::TOKEN)->willReturn(['customer_id' => 5]);
        $this->pages->expects($this->never())->method('create');

        $this->controller()->execute();

        $this->assertSame(self::TOKEN, $this->sessionData[LinkRequests::SESSION_KEY]);
        $this->assertSame(['path' => 'sociallogin/link/confirm', 'Referrer-Policy' => 'no-referrer'], $this->redirected);
    }

    public function testAForgedNewlineOrUsedTokenIsRefusedAndClearsTheSession(): void
    {
        $this->sessionData[LinkRequests::SESSION_KEY] = 'an older token';
        $this->param = self::TOKEN . "\n";
        $this->requests->expects($this->never())->method('peek');
        $this->messages->expects($this->once())->method('addErrorMessage')
            ->with('This link has expired or was used already. Continue with the service again to get a new one.');

        $this->controller()->execute();

        $this->assertArrayNotHasKey(LinkRequests::SESSION_KEY, $this->sessionData);
        $this->assertSame(['path' => 'customer/account/login', 'Referrer-Policy' => 'no-referrer'], $this->redirected);
    }

    public function testAnExpiredTokenIsRefused(): void
    {
        $this->param = self::TOKEN;
        $this->requests->method('peek')->willReturn(null);
        $this->messages->expects($this->once())->method('addErrorMessage');

        $this->controller()->execute();

        $this->assertArrayNotHasKey(LinkRequests::SESSION_KEY, $this->sessionData);
        $this->assertSame('customer/account/login', $this->redirected['path']);
    }

    public function testThePageIsNoindexWithoutReferrerAndOnlyPeeks(): void
    {
        $this->sessionData[LinkRequests::SESSION_KEY] = self::TOKEN;
        $this->requests->expects($this->once())->method('peek')->with(self::TOKEN)->willReturn(['customer_id' => 5]);
        $title = $this->createMock(Title::class);
        $title->expects($this->once())->method('set');
        $pageConfig = $this->createMock(PageConfig::class);
        $pageConfig->expects($this->once())->method('setRobots')->with('NOINDEX,NOFOLLOW');
        $pageConfig->method('getTitle')->willReturn($title);
        $page = $this->createMock(Page::class);
        $page->method('getConfig')->willReturn($pageConfig);
        $page->expects($this->once())->method('setHeader')->with('Referrer-Policy', 'no-referrer', true)->willReturnSelf();
        $this->pages->expects($this->once())->method('create')->willReturn($page);

        $this->assertSame($page, $this->controller()->execute());
        $this->assertSame(self::TOKEN, $this->sessionData[LinkRequests::SESSION_KEY], 'the token stays for the POST');
    }

    public function testThePageWithoutATokenSendsToTheSignInPage(): void
    {
        $this->pages->expects($this->never())->method('create');
        $this->messages->expects($this->once())->method('addErrorMessage');

        $this->controller()->execute();

        $this->assertSame('customer/account/login', $this->redirected['path']);
    }

    public function testOffMeansNotFound(): void
    {
        $this->enabled = false;
        $this->param = self::TOKEN;
        $this->requests->expects($this->never())->method('peek');

        $this->assertInstanceOf(Forward::class, $this->controller()->execute());
        $this->assertSame([], $this->sessionData);
    }
}
