<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\SocialLogin\Test\Unit\Model;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\AccountConfirmation;
use Magento\Customer\Model\CustomerRegistry;
use Magento\Customer\Model\Data\CustomerSecure;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\TestCase;
use Zero\SocialLogin\Model\Password;
use Zero\SocialLogin\Model\VerifiedEmail;

/**
 * Verified = the store requires email confirmation AND the account has confirmed (AM-B7-1); has a password = a
 * non-empty password hash.
 */
class VerifiedEmailTest extends TestCase
{
    private function customer(?string $confirmation): CustomerInterface
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn(5);
        $customer->method('getWebsiteId')->willReturn(2);
        $customer->method('getEmail')->willReturn('ann@example.com');
        $customer->method('getConfirmation')->willReturn($confirmation);

        return $customer;
    }

    private function verified(bool $required): VerifiedEmail
    {
        $confirmation = $this->createMock(AccountConfirmation::class);
        $confirmation->expects($this->atLeastOnce())->method('isConfirmationRequired')
            ->with(2, 5, 'ann@example.com')->willReturn($required);

        return new VerifiedEmail($confirmation);
    }

    public function testConfirmedWhereConfirmationIsRequiredIsVerified(): void
    {
        $this->assertTrue($this->verified(true)->isVerified($this->customer(null)));
        $this->assertTrue($this->verified(true)->isVerified($this->customer('')));
    }

    public function testAPendingConfirmationIsNotVerified(): void
    {
        $this->assertFalse($this->verified(true)->isVerified($this->customer('confirm-key')));
    }

    public function testWithoutRequiredConfirmationNoAccountIsVerified(): void
    {
        $this->assertFalse($this->verified(false)->isVerified($this->customer(null)));
    }

    public function testPasswordIsANonEmptyHash(): void
    {
        $secure = fn (?string $hash) => (new \ReflectionClass(CustomerSecure::class))->newInstanceWithoutConstructor()
            ->setData('password_hash', $hash);
        $hashes = [5 => '$2y$10$abc', 6 => '', 7 => null];
        $registry = $this->createStub(CustomerRegistry::class);
        $registry->method('retrieveSecureData')->willReturnCallback(
            fn (int $id) => array_key_exists($id, $hashes) ? $secure($hashes[$id]) : throw new NoSuchEntityException()
        );
        $password = new Password($registry);

        $this->assertTrue($password->has(5));
        $this->assertFalse($password->has(6));
        $this->assertFalse($password->has(7));
        $this->assertFalse($password->has(8), 'a missing account has none');
    }
}
