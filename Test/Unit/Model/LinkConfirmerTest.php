<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\SocialLogin\Test\Unit\Model;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Config\Share;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Zero\SocialLogin\Model\AccountReset;
use Zero\SocialLogin\Model\Authenticator;
use Zero\SocialLogin\Model\LinkConfirmer;
use Zero\SocialLogin\Model\LinkRequests;
use Zero\SocialLogin\Model\LoginException;
use Zero\SocialLogin\Model\Password;
use Zero\SocialLogin\Model\Profile;
use Zero\SocialLogin\Model\Provider\Pool;
use Zero\SocialLogin\Model\Provider\ProviderInterface;
use Zero\SocialLogin\Model\VerifiedEmail;

/**
 * The POST of the emailed link: the token is taken once; an account of another website (per-website sharing), one
 * with a password by now, a service that is off or connected to someone else is refused; an unverified account is
 * reset and connected, a verified one only connected (AM-B7-1); the event carries the customer and the provider.
 *
 * The collaborators are mocks so each test can add expectations to the ones it is about.
 */
#[AllowMockObjectsWithoutExpectations]
class LinkConfirmerTest extends TestCase
{
    private const TOKEN = 'AbCdEfGhIjKlMnOpQrStUvWxYz0123456789abcdefg';

    private LinkRequests&MockObject $requests;
    private Authenticator&MockObject $authenticator;
    private AccountReset&MockObject $reset;
    private Password&MockObject $password;
    private VerifiedEmail&MockObject $verified;
    private EventManagerInterface&MockObject $events;
    private bool $websiteScope = false;
    private int $customerWebsite = 1;
    private bool $googleOn = true;
    private bool $customerExists = true;
    /** @var list<array{0: int, 1: string}> */
    private array $messageAsked = [];

    protected function setUp(): void
    {
        $this->requests = $this->createMock(LinkRequests::class);
        $this->authenticator = $this->createMock(Authenticator::class);
        $this->authenticator->method('connectedMessage')->willReturnCallback(function (int $customerId, string $provider) {
            $this->messageAsked[] = [$customerId, $provider];

            return __('Google is connected. You can now sign in with it.');
        });
        $this->reset = $this->createMock(AccountReset::class);
        $this->password = $this->createMock(Password::class);
        $this->verified = $this->createMock(VerifiedEmail::class);
        $this->events = $this->createMock(EventManagerInterface::class);
    }

    private function confirmer(): LinkConfirmer
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn(5);
        $customer->method('getWebsiteId')->willReturnCallback(fn () => $this->customerWebsite);
        $customers = $this->createStub(CustomerRepositoryInterface::class);
        $customers->method('getById')->willReturnCallback(
            fn (int $id) => $this->customerExists && $id === 5 ? $customer : throw new NoSuchEntityException()
        );
        $store = $this->createStub(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(1);
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        $share = $this->createStub(Share::class);
        $share->method('isWebsiteScope')->willReturnCallback(fn () => $this->websiteScope);
        $google = $this->createStub(ProviderInterface::class);
        $google->method('getLabel')->willReturn('Google');
        $google->method('isAvailable')->willReturnCallback(fn () => $this->googleOn);

        return new LinkConfirmer(
            $this->requests,
            $this->authenticator,
            $this->reset,
            $this->password,
            $this->verified,
            $customers,
            $stores,
            $share,
            new Pool(['google' => $google]),
            $this->events
        );
    }

    private function aRequest(): void
    {
        $this->requests->expects($this->once())->method('take')->with(self::TOKEN)->willReturn([
            'link_id' => 3, 'customer_id' => 5, 'provider' => 'google', 'subject' => 'g-1', 'email' => 'ann@example.com',
        ]);
    }

    private function profile(): Profile
    {
        return new Profile('google', 'g-1', 'ann@example.com', true);
    }

    private function connected(int $id): CustomerInterface
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn($id);

        return $customer;
    }

    private function expectRefusal(string $message): void
    {
        $this->authenticator->expects($this->never())->method('connectedMessage');
        $this->reset->expects($this->never())->method('resetAndConnect');
        $this->authenticator->expects($this->never())->method('link');
        $this->events->expects($this->never())->method('dispatch');
        $this->expectException(LoginException::class);
        $this->expectExceptionMessage($message);
    }

    public function testConnectsAndResetsAnUnverifiedAccount(): void
    {
        $this->aRequest();
        $this->password->method('has')->with(5)->willReturn(false);
        $this->authenticator->method('customerFor')->willReturn(null);
        $this->verified->expects($this->once())->method('isVerified')->willReturn(false);
        $this->reset->expects($this->once())->method('resetAndConnect')->with(5, $this->profile(), 'Google');
        $this->authenticator->expects($this->never())->method('link');
        $this->events->expects($this->once())->method('dispatch')
            ->with('zero_sociallogin_link_confirmed', ['customer_id' => 5, 'provider' => 'google']);

        $result = $this->confirmer()->confirm(self::TOKEN);

        $this->assertSame(5, $result['customer_id']);
        $this->assertSame('Google', $result['label']);
        $this->assertTrue($result['reset']);
        $this->assertSame('Google is connected. You can now sign in with it.', (string) $result['message']);
        $this->assertSame([[5, 'google']], $this->messageAsked);
    }

    public function testAVerifiedAccountIsOnlyConnected(): void
    {
        $this->aRequest();
        $this->password->method('has')->willReturn(false);
        $this->authenticator->method('customerFor')->willReturn(null);
        $this->verified->method('isVerified')->willReturn(true);
        $this->reset->expects($this->never())->method('resetAndConnect');
        $this->authenticator->expects($this->once())->method('link')->with($this->profile(), 5);
        $this->events->expects($this->once())->method('dispatch')->with('zero_sociallogin_link_confirmed');

        $result = $this->confirmer()->confirm(self::TOKEN);

        $this->assertFalse($result['reset'], 'sessions and password are kept');
        $this->assertSame(
            [[5, 'google']],
            $this->messageAsked,
            'the success message lists the services of the linked account (R-B7-5), not of the link row or another one'
        );
        $this->assertSame('Google is connected. You can now sign in with it.', (string) $result['message']);
    }

    public function testAlreadyConnectedToSameCustomerSkipsTheReset(): void
    {
        $this->aRequest();
        $this->password->method('has')->willReturn(false);
        $this->authenticator->method('customerFor')->with($this->profile())->willReturn($this->connected(5));
        $this->verified->expects($this->never())->method('isVerified');
        $this->reset->expects($this->never())->method('resetAndConnect');
        $this->authenticator->expects($this->never())->method('link');
        $this->events->expects($this->once())->method('dispatch')->with('zero_sociallogin_link_confirmed');

        $this->assertFalse($this->confirmer()->confirm(self::TOKEN)['reset']);
    }

    public function testConnectedToAnotherAccountIsRefused(): void
    {
        $this->aRequest();
        $this->password->method('has')->willReturn(false);
        $this->authenticator->method('customerFor')->willReturn($this->connected(9));

        $this->expectRefusal('This Google account is already connected to another account in our store.');
        $this->confirmer()->confirm(self::TOKEN);
    }

    public function testAnAccountThatHasAPasswordByNowIsRefused(): void
    {
        $this->aRequest();
        $this->password->method('has')->with(5)->willReturn(true);
        $this->verified->expects($this->never())->method('isVerified');
        $this->authenticator->expects($this->never())->method('customerFor');

        $this->expectRefusal('This account has a password. Sign in with it');
        $this->confirmer()->confirm(self::TOKEN);
    }

    public function testDisabledProviderIsRefused(): void
    {
        $this->googleOn = false;
        $this->aRequest();
        $this->password->expects($this->never())->method('has');

        $this->expectRefusal('This link has expired or was used already. Continue with the service again to get a new one.');
        $this->confirmer()->confirm(self::TOKEN);
    }

    public function testAnUnknownOrUsedTokenIsRefused(): void
    {
        $this->requests->expects($this->once())->method('take')->with(self::TOKEN . "\n")->willReturn(null);
        $this->password->expects($this->never())->method('has');

        $this->expectRefusal('This link has expired or was used already.');
        $this->confirmer()->confirm(self::TOKEN . "\n");
    }

    public function testCustomerOfAnotherWebsiteIsRefused(): void
    {
        $this->websiteScope = true;
        $this->customerWebsite = 2;
        $this->aRequest();
        $this->password->expects($this->never())->method('has');

        $this->expectRefusal('This link has expired or was used already.');
        $this->confirmer()->confirm(self::TOKEN);
    }

    public function testAnotherWebsiteIsFineWhenAccountsAreGlobal(): void
    {
        $this->customerWebsite = 2;
        $this->aRequest();
        $this->password->method('has')->willReturn(false);
        $this->authenticator->method('customerFor')->willReturn(null);
        $this->verified->method('isVerified')->willReturn(true);
        $this->authenticator->expects($this->once())->method('link')->with($this->profile(), 5);

        $this->assertSame(5, $this->confirmer()->confirm(self::TOKEN)['customer_id']);
    }

    public function testADeletedCustomerIsRefused(): void
    {
        $this->customerExists = false;
        $this->aRequest();

        $this->expectRefusal('This link has expired or was used already.');
        $this->confirmer()->confirm(self::TOKEN);
    }
}
