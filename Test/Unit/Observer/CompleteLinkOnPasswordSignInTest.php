<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\SocialLogin\Test\Unit\Observer;

use Magento\Customer\Model\Customer;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;
use Zero\SocialLogin\Model\PendingLink;
use Zero\SocialLogin\Observer\CompleteLinkOnPasswordSignIn;

/**
 * A storefront password sign-in (customer_customer_authenticated, frontend area only) completes the service this
 * account tried in the same browser.
 */
class CompleteLinkOnPasswordSignInTest extends TestCase
{
    private function observer(?Customer $customer): Observer
    {
        return new Observer(['event' => new Event(['model' => $customer, 'password' => 'secret'])]);
    }

    private function customer(?int $id): Customer
    {
        $customer = (new \ReflectionClass(Customer::class))->newInstanceWithoutConstructor();
        $customer->setData('id', $id);

        return $customer;
    }

    public function testThePasswordSignInCompletesThatCustomersPendingLink(): void
    {
        $links = $this->createMock(PendingLink::class);
        $links->expects($this->once())->method('complete')->with(5)->willReturn('Google');

        (new CompleteLinkOnPasswordSignIn($links))->execute($this->observer($this->customer(5)));
    }

    public function testNoCustomerNothingToComplete(): void
    {
        $links = $this->createMock(PendingLink::class);
        $links->expects($this->never())->method('complete');

        (new CompleteLinkOnPasswordSignIn($links))->execute($this->observer(null));
        (new CompleteLinkOnPasswordSignIn($links))->execute($this->observer($this->customer(null)));
    }

    public function testRegisteredForTheStorefrontOnly(): void
    {
        $etc = dirname(__DIR__, 3) . '/etc';
        $frontend = simplexml_load_file($etc . '/frontend/events.xml');
        $observers = $frontend->xpath('//event[@name="customer_customer_authenticated"]/observer/@instance');

        $this->assertSame([CompleteLinkOnPasswordSignIn::class], array_map('strval', $observers));
        foreach (['events.xml', 'adminhtml/events.xml', 'graphql/events.xml', 'webapi_rest/events.xml'] as $file) {
            if (is_file($etc . '/' . $file)) {
                $this->assertStringNotContainsString('CompleteLinkOnPasswordSignIn', (string) file_get_contents($etc . '/' . $file), $file);
            }
        }
    }
}
