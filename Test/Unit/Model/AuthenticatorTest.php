<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\SocialLogin\Test\Unit\Model;

use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Customer\Model\Config\Share;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Zero\SocialLogin\Model\Authenticator;
use Zero\SocialLogin\Model\Confirmation;
use Zero\SocialLogin\Model\IdentityStore;
use Zero\SocialLogin\Model\LinkMailer;
use Zero\SocialLogin\Model\LinkPendingException;
use Zero\SocialLogin\Model\LinkRequests;
use Zero\SocialLogin\Model\LoginException;
use Zero\SocialLogin\Model\Password;
use Zero\SocialLogin\Model\Profile;
use Zero\SocialLogin\Model\Provider\Pool;
use Zero\SocialLogin\Model\Provider\ProviderInterface;

/**
 * A service sign-in never takes over an existing account by email (A5-10, P6-1): an account with a password waits
 * for a sign-in in this browser (no email), one without gets an emailed link; the success message names the
 * account's other services by label only (R-B7-5).
 *
 * The collaborators are mocks so each test can add expectations to the ones it is about.
 */
#[AllowMockObjectsWithoutExpectations]
class AuthenticatorTest extends TestCase
{
    private IdentityStore&MockObject $identities;
    private CustomerRepositoryInterface&MockObject $customers;
    private AccountManagementInterface&MockObject $accounts;
    private CustomerInterfaceFactory&MockObject $customerFactory;
    private Share&MockObject $share;
    private Confirmation&MockObject $confirmation;
    private Password&MockObject $password;
    private LinkRequests&MockObject $linkRequests;
    private LinkMailer&MockObject $linkMailer;
    private Pool $pool;
    /** @var array<int, CustomerInterface> */
    private array $byId = [];
    /** @var array<string, CustomerInterface> */
    private array $byEmail = [];

    protected function setUp(): void
    {
        $this->identities = $this->createMock(IdentityStore::class);
        $this->customers = $this->createMock(CustomerRepositoryInterface::class);
        $this->customers->method('getById')->willReturnCallback(
            fn ($id) => $this->byId[$id] ?? throw new NoSuchEntityException()
        );
        $this->customers->method('get')->willReturnCallback(
            fn ($email) => $this->byEmail[$email] ?? throw new NoSuchEntityException()
        );
        $this->accounts = $this->createMock(AccountManagementInterface::class);
        $this->customerFactory = $this->createMock(CustomerInterfaceFactory::class);
        $this->share = $this->createMock(Share::class);
        $this->confirmation = $this->createMock(Confirmation::class);
        $this->confirmation->method('isPending')->willReturnCallback(fn ($c) => (string) $c->getConfirmation() !== '');
        $this->password = $this->createMock(Password::class);
        $this->linkRequests = $this->createMock(LinkRequests::class);
        $this->linkMailer = $this->createMock(LinkMailer::class);
        $this->pool = new Pool([
            'google' => $this->provider('Google'),
            'facebook' => $this->provider('Facebook'),
            'apple' => $this->provider('Apple'),
        ]);
    }

    private function provider(string $label): ProviderInterface
    {
        $provider = $this->createStub(ProviderInterface::class);
        $provider->method('getLabel')->willReturn($label);
        $provider->method('isAvailable')->willReturn(true);

        return $provider;
    }

    private function authenticator(): Authenticator
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(1);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new Authenticator(
            $this->identities,
            $this->customers,
            $this->accounts,
            $this->customerFactory,
            $storeManager,
            $this->share,
            $this->confirmation,
            $this->pool,
            $this->password,
            $this->linkRequests,
            $this->linkMailer
        );
    }

    private function customer(int $id, string $email, int $websiteId = 1, ?string $confirmation = null): CustomerInterface
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn($id);
        $customer->method('getEmail')->willReturn($email);
        $customer->method('getWebsiteId')->willReturn($websiteId);
        $customer->method('getConfirmation')->willReturn($confirmation);
        $this->byId[$id] = $customer;
        $this->byEmail[$email] = $customer;

        return $customer;
    }

    private function profile(bool $verified = true, string $email = 'ann@example.com', string $first = 'Ann'): Profile
    {
        return new Profile('google', 'g-1', $email, $verified, $first, $first !== '' ? 'Lee' : '');
    }

    /**
     * Nothing connects, nobody is signed in and no account is made or confirmed.
     */
    private function expectNoSignIn(): void
    {
        $this->identities->expects($this->never())->method('add');
        $this->identities->expects($this->never())->method('touch');
        $this->accounts->expects($this->never())->method('createAccount');
        $this->confirmation->expects($this->never())->method('confirm');
    }

    private function pending(callable $call): LinkPendingException
    {
        try {
            $call();
        } catch (LinkPendingException $e) {
            return $e;
        }
        $this->fail('LinkPendingException expected');
    }

    public function testConnectedAccountSignsIn(): void
    {
        $customer = $this->customer(7, 'old@example.com');
        $this->identities->method('findCustomerIds')->with('google', 'g-1')->willReturn([7]);
        $this->identities->expects($this->never())->method('add');
        $this->identities->expects($this->once())->method('touch')->with(7);
        $this->linkRequests->expects($this->never())->method('create');

        $this->assertSame($customer, $this->authenticator()->login($this->profile()));
    }

    public function testConnectionOnAnotherWebsiteDoesNotCountWhenAccountsArePerWebsite(): void
    {
        $this->customer(7, 'ann@example.com', 2);
        unset($this->byEmail['ann@example.com']);
        $this->share->method('isWebsiteScope')->willReturn(true);
        $this->identities->method('findCustomerIds')->willReturn([7]);
        $created = $this->expectNewAccount();

        $this->assertSame($created, $this->authenticator()->login($this->profile()));
    }

    public function testVerifiedEmailOnAPasswordAccountWaitsForASignInAndSendsNoEmail(): void
    {
        $this->customer(5, 'ann@example.com');
        $this->identities->method('findCustomerIds')->willReturn([]);
        $this->identities->method('forCustomer')->willReturn([]);
        $this->password->expects($this->once())->method('has')->with(5)->willReturn(true);
        $this->linkRequests->expects($this->never())->method('create');
        $this->linkMailer->expects($this->never())->method('send');
        $this->expectNoSignIn();

        $e = $this->pending(fn () => $this->authenticator()->login($this->profile()));

        $this->assertSame(5, $e->customerId);
        $this->assertFalse($e->emailed);
        $this->assertSame(
            'An account with ann@example.com already exists. To connect Google, sign in now with your password'
            . ' (forgot it? Use "Forgot Your Password?", then sign in) or with a service already connected to it.',
            $e->getMessage()
        );
    }

    public function testVerifiedEmailOnAPasswordlessAccountEmailsALinkAndSignsNobodyIn(): void
    {
        $customer = $this->customer(5, 'ann@example.com');
        $profile = $this->profile();
        $this->identities->method('findCustomerIds')->willReturn([]);
        $this->identities->method('forCustomer')->willReturn([]);
        $this->password->method('has')->with(5)->willReturn(false);
        $this->linkRequests->expects($this->once())->method('create')->with(5, $profile)->willReturn('TOKEN');
        $this->linkMailer->expects($this->once())->method('send')->with($customer, $profile, 'TOKEN');
        $this->expectNoSignIn();

        $e = $this->pending(fn () => $this->authenticator()->login($profile));

        $this->assertSame(5, $e->customerId);
        $this->assertTrue($e->emailed);
        $this->assertSame(
            'An account with ann@example.com already exists. To connect Google, open the link we\'ve emailed to'
            . ' ann@example.com, or sign in now with a service already connected to it.',
            $e->getMessage()
        );
    }

    public function testExistingUnconfirmedAccountIsNotConfirmedBySocialSignIn(): void
    {
        $this->customer(5, 'ann@example.com', 1, 'confirm-key');
        $this->identities->method('findCustomerIds')->willReturn([]);
        $this->identities->method('forCustomer')->willReturn([]);
        $this->password->method('has')->willReturn(true);
        $this->confirmation->expects($this->never())->method('sendLink');
        $this->expectNoSignIn();

        $e = $this->pending(fn () => $this->authenticator()->login($this->profile()));

        $this->assertSame(5, $e->customerId);
    }

    public function testUnverifiedEmailNeverTakesOverAnExistingAccount(): void
    {
        $this->customer(5, 'ann@example.com');
        $this->identities->method('findCustomerIds')->willReturn([]);
        $this->password->expects($this->never())->method('has');
        $this->linkRequests->expects($this->never())->method('create');
        $this->linkMailer->expects($this->never())->method('send');
        $this->expectNoSignIn();

        try {
            $this->authenticator()->login($this->profile(false));
            $this->fail('LoginException expected');
        } catch (LoginException $e) {
            $this->assertNotInstanceOf(LinkPendingException::class, $e, 'no pending link for an unverified email');
            $this->assertSame(
                'An account with ann@example.com already exists. Sign in with your password, then connect Google under My Account > Sign-In Methods.',
                $e->getMessage()
            );
        }
    }

    public function testExistingAccountWithAnotherAccountOfTheSameServiceIsNotOverwritten(): void
    {
        $this->customer(5, 'ann@example.com');
        $this->identities->method('findCustomerIds')->willReturn([]);
        $this->identities->method('forCustomer')->willReturn(['google' => ['identity_id' => 1]]);
        $this->linkRequests->expects($this->never())->method('create');
        $this->linkMailer->expects($this->never())->method('send');
        $this->expectNoSignIn();

        try {
            $this->authenticator()->login($this->profile());
            $this->fail('LoginException expected');
        } catch (LoginException $e) {
            $this->assertNotInstanceOf(LinkPendingException::class, $e);
            $this->assertSame(
                'Your account is already connected to a different Google account. Disconnect it under My Account > Sign-In Methods first.',
                $e->getMessage()
            );
        }
    }

    public function testNoEmailNoAccount(): void
    {
        $this->identities->method('findCustomerIds')->willReturn([]);
        $this->accounts->expects($this->never())->method('createAccount');

        $this->expectException(LoginException::class);
        $this->expectExceptionMessage('Your Google account didn\'t share an email address with us.');
        $this->authenticator()->login($this->profile(true, ''));
    }

    public function testNewAccountWithoutPasswordAndNamesFromTheEmailWhenMissing(): void
    {
        $this->identities->method('findCustomerIds')->willReturn([]);
        $created = $this->expectNewAccount('Ann Lee', '-');
        $this->identities->expects($this->once())->method('add')->with(9);
        $this->linkRequests->expects($this->never())->method('create');

        $this->assertSame($created, $this->authenticator()->login($this->profile(true, 'ann.lee@example.com', '')));
    }

    public function testVerifiedEmailConfirmsAPendingAccount(): void
    {
        $this->customer(5, 'ann@example.com', 1, 'confirm-key');
        $this->identities->method('findCustomerIds')->willReturn([5]);
        $this->confirmation->expects($this->once())->method('confirm')->with(5);
        $this->confirmation->expects($this->never())->method('sendLink');

        $this->authenticator()->login($this->profile());
    }

    public function testUnverifiedEmailMustBeConfirmedFirst(): void
    {
        $this->customer(5, 'ann@example.com', 1, 'confirm-key');
        $this->identities->method('findCustomerIds')->willReturn([5]);
        $this->confirmation->expects($this->never())->method('confirm');
        $this->confirmation->expects($this->once())->method('sendLink');

        $this->expectException(LoginException::class);
        $this->expectExceptionMessage('confirmation link');
        $this->authenticator()->login($this->profile(false));
    }

    public function testLinkRefusesAServiceAccountConnectedToSomeoneElse(): void
    {
        $this->customer(7, 'other@example.com');
        $this->identities->method('findCustomerIds')->willReturn([7]);
        $this->identities->expects($this->never())->method('add');
        $this->linkRequests->expects($this->never())->method('cancel');

        $this->expectException(LoginException::class);
        $this->expectExceptionMessage('This Google account is already connected to another account in our store.');
        $this->authenticator()->link($this->profile(), 5);
    }

    public function testLinkConnectsAndIsIdempotent(): void
    {
        $this->customer(5, 'me@example.com');
        $this->identities->method('findCustomerIds')->willReturnOnConsecutiveCalls([], [5]);
        $this->identities->method('forCustomer')->willReturn([]);
        $this->identities->expects($this->once())->method('add')->with(5);

        $this->authenticator()->link($this->profile(false), 5);
        $this->authenticator()->link($this->profile(false), 5);
    }

    public function testLinkFromMyAccountCancelsAPendingEmailLink(): void
    {
        $this->identities->method('findCustomerIds')->willReturn([]);
        $this->identities->method('forCustomer')->willReturn([]);
        $calls = [];
        $this->identities->method('add')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'add';
        });
        $this->linkRequests->expects($this->once())->method('cancel')->with(5, 'google')
            ->willReturnCallback(function () use (&$calls) {
                $calls[] = 'cancel';
            });

        $this->authenticator()->link($this->profile(), 5);

        $this->assertSame(['add', 'cancel'], $calls, 'the emailed link stops working once the service is connected');
    }

    public function testLinkToAnotherServiceAccountOfTheSameProviderCancelsNothing(): void
    {
        $this->identities->method('findCustomerIds')->willReturn([]);
        $this->identities->method('forCustomer')->willReturn(['google' => ['identity_id' => 3]]);
        $this->identities->expects($this->never())->method('add');
        $this->linkRequests->expects($this->never())->method('cancel');

        $this->expectException(LoginException::class);
        $this->expectExceptionMessage('already connected to a different Google account');
        $this->authenticator()->link($this->profile(), 5);
    }

    public function testConnectedMessageWithNoOtherServiceIsThePlainMessage(): void
    {
        $this->identities->method('forCustomer')->with(5)->willReturn([
            'google' => ['identity_id' => 11, 'provider' => 'google', 'email' => 'ann@example.com'],
        ]);

        $this->assertSame(
            'Google is connected. You can now sign in with it.',
            (string) $this->authenticator()->connectedMessage(5, 'google')
        );
    }

    public function testConnectedMessageNamesEveryServiceSortedByLabelAndNothingElse(): void
    {
        $this->identities->method('forCustomer')->with(5)->willReturn([
            'google' => ['identity_id' => 11, 'provider' => 'google', 'email' => 'ann@example.com'],
            'facebook' => ['identity_id' => 12, 'provider' => 'facebook', 'email' => 'stranger@evil.example'],
            'apple' => ['identity_id' => 13, 'provider' => 'apple', 'email' => 'relay@privaterelay.appleid.com'],
        ]);

        $message = (string) $this->authenticator()->connectedMessage(5, 'google');

        $this->assertSame(
            'Google is connected. You can now sign in with it. Connected services: Apple, Facebook, Google.'
            . ' If you don\'t recognise one, disconnect it under My Account > Sign-In Methods.',
            $message
        );
        foreach (['ann@example.com', 'stranger@evil.example', 'relay@', '11', '12', '13'] as $secret) {
            $this->assertStringNotContainsString($secret, $message, 'labels only, never ids or emails');
        }
    }

    public function testConnectedMessageFallsBackToTheCodeOfAServiceMissingFromThePool(): void
    {
        $this->identities->method('forCustomer')->willReturn([
            'google' => ['identity_id' => 11],
            'linkedin' => ['identity_id' => 14],
        ]);

        $this->assertStringContainsString(
            'Connected services: Google, linkedin.',
            (string) $this->authenticator()->connectedMessage(5, 'google')
        );
        $this->assertStringStartsWith(
            'yahoo is connected.',
            (string) $this->authenticator()->connectedMessage(5, 'yahoo'),
            'the connected service itself is missing from the Pool too'
        );
    }

    public function testPasswordFirstNamesTheService(): void
    {
        $this->assertSame(
            'This account has a password. Sign in with it (or reset it with "Forgot Your Password?" and sign in), then connect Apple under My Account > Sign-In Methods.',
            (string) Authenticator::passwordFirst('Apple')
        );
    }

    private function expectNewAccount(string $first = 'Ann', string $last = 'Lee'): CustomerInterface
    {
        $new = $this->createMock(CustomerInterface::class);
        foreach (['setEmail', 'setFirstname', 'setLastname', 'setWebsiteId', 'setStoreId'] as $setter) {
            $new->method($setter)->willReturnSelf();
        }
        $new->expects($this->once())->method('setFirstname')->with($first);
        $new->expects($this->once())->method('setLastname')->with($last);
        $this->customerFactory->method('create')->willReturn($new);
        $created = $this->customer(9, 'created@example.com');
        $this->accounts->expects($this->once())->method('createAccount')->with($new)->willReturn($created);

        return $created;
    }
}
