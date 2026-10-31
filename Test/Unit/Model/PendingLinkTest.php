<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\SocialLogin\Test\Unit\Model;

use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Zero\SocialLogin\Model\Authenticator;
use Zero\SocialLogin\Model\LoginException;
use Zero\SocialLogin\Model\PendingLink;
use Zero\SocialLogin\Model\Profile;
use Zero\SocialLogin\Model\Provider\Pool;
use Zero\SocialLogin\Model\Provider\ProviderInterface;
use Zero\SocialLogin\Test\Unit\SessionStub;

/**
 * A service that met an existing account waits in this browser's session for 15 minutes; only a sign-in of that
 * same account connects it, and any sign-in clears it.
 *
 * The collaborators are mocks so each test can add expectations to the ones it is about.
 */
#[AllowMockObjectsWithoutExpectations]
class PendingLinkTest extends TestCase
{
    use SessionStub;

    private const KEY = 'zero_social_login_pending_link';

    private Authenticator&MockObject $authenticator;
    private ManagerInterface&MockObject $messages;
    private bool $googleOn = true;

    protected function setUp(): void
    {
        $this->authenticator = $this->createMock(Authenticator::class);
        $this->messages = $this->createMock(ManagerInterface::class);
    }

    private function pendingLink(): PendingLink
    {
        $google = $this->createStub(ProviderInterface::class);
        $google->method('getLabel')->willReturn('Google');
        $google->method('isAvailable')->willReturnCallback(fn () => $this->googleOn);

        return new PendingLink($this->customerSession(), $this->authenticator, new Pool(['google' => $google]), $this->messages);
    }

    private function profile(): Profile
    {
        return new Profile('google', 'g-1', 'ann@example.com', true, 'Ann', 'Lee');
    }

    public function testRememberKeepsTheServiceAccountForThisCustomer(): void
    {
        $before = time();
        $this->pendingLink()->remember(5, $this->profile());

        $data = $this->sessionData[self::KEY];
        $this->assertSame(
            ['customerId' => 5, 'provider' => 'google', 'subject' => 'g-1', 'email' => 'ann@example.com'],
            array_diff_key($data, ['createdAt' => true])
        );
        $this->assertEqualsWithDelta($before, $data['createdAt'], 1);
    }

    public function testCompletesForTheSameCustomerAndReturnsTheService(): void
    {
        $links = $this->pendingLink();
        $links->remember(5, $this->profile());
        $this->authenticator->expects($this->once())->method('link')
            ->with(new Profile('google', 'g-1', 'ann@example.com', true), 5);
        $message = __('Google is connected. You can now sign in with it. Connected services: Facebook, Google.');
        $this->authenticator->expects($this->once())->method('connectedMessage')->with(5, 'google')->willReturn($message);
        $this->messages->expects($this->once())->method('addSuccessMessage')->with($message);

        $this->assertSame('Google', $links->complete(5));
        $this->assertArrayNotHasKey(self::KEY, $this->sessionData);
        $this->assertNull($links->complete(5), 'used once');
    }

    public function testCompletesOnlyForTheSameCustomerAndAnySignInClearsIt(): void
    {
        $links = $this->pendingLink();
        $links->remember(5, $this->profile());
        $this->authenticator->expects($this->never())->method('link');
        $this->messages->expects($this->never())->method('addSuccessMessage');

        $this->assertNull($links->complete(9), 'another account signed in');
        $this->assertArrayNotHasKey(self::KEY, $this->sessionData, 'that sign-in dropped it');
        $this->assertNull($links->complete(5), 'so the right account signing in later connects nothing');
    }

    public function testAfter15MinutesNothingIsConnected(): void
    {
        $links = $this->pendingLink();
        $this->sessionData[self::KEY] = [
            'customerId' => 5, 'provider' => 'google', 'subject' => 'g-1', 'email' => 'ann@example.com',
            'createdAt' => time() - 900,
        ];
        $this->authenticator->expects($this->never())->method('link');

        $this->assertNull($links->complete(5));
        $this->assertArrayNotHasKey(self::KEY, $this->sessionData);
    }

    public function testWithin15MinutesItStillConnects(): void
    {
        $links = $this->pendingLink();
        $this->sessionData[self::KEY] = [
            'customerId' => 5, 'provider' => 'google', 'subject' => 'g-1', 'email' => 'ann@example.com',
            'createdAt' => time() - 880,
        ];
        $this->authenticator->method('connectedMessage')->willReturn(__('ok'));
        $this->authenticator->expects($this->once())->method('link');

        $this->assertSame('Google', $links->complete(5));
    }

    public function testAServiceSwitchedOffMeanwhileConnectsNothing(): void
    {
        $this->googleOn = false;
        $links = $this->pendingLink();
        $links->remember(5, $this->profile());
        $this->authenticator->expects($this->never())->method('link');

        $this->assertNull($links->complete(5));
    }

    public function testARefusedLinkBecomesAnErrorMessage(): void
    {
        $links = $this->pendingLink();
        $links->remember(5, $this->profile());
        $this->authenticator->method('link')->willThrowException(
            new LoginException(__('This Google account is already connected to another account in our store.'))
        );
        $this->messages->expects($this->once())->method('addErrorMessage')
            ->with('This Google account is already connected to another account in our store.');
        $this->messages->expects($this->never())->method('addSuccessMessage');

        $this->assertNull($links->complete(5));
    }

    public function testNothingPendingNothingHappens(): void
    {
        $this->authenticator->expects($this->never())->method('link');

        $this->assertNull($this->pendingLink()->complete(5));
    }
}
