<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\SocialLogin\Test\Unit;

use Magento\Customer\Model\Session as CustomerSession;

/**
 * A customer session whose data lives in `$sessionData`. setData/unsetData/setLastCustomerId are magic (__call) on
 * Magento's session, so they are answered from the array; `$sessionCalls` records the calls in order.
 */
trait SessionStub
{
    /** @var array<string, mixed> */
    private array $sessionData = [];
    /** @var string[] */
    private array $sessionCalls = [];

    private function customerSession(bool $loggedIn = false, ?int $customerId = null): CustomerSession
    {
        $session = $this->createStub(CustomerSession::class);
        $session->method('isLoggedIn')->willReturn($loggedIn);
        $session->method('getCustomerId')->willReturn($customerId);
        $session->method('getData')->willReturnCallback(fn ($key = '') => $this->sessionData[$key] ?? null);
        $session->method('logout')->willReturnCallback(function () use (&$session) {
            $this->sessionCalls[] = 'logout';

            return $session;
        });
        $session->method('regenerateId')->willReturnCallback(function () use (&$session) {
            $this->sessionCalls[] = 'regenerateId';

            return $session;
        });
        $session->method('__call')->willReturnCallback(function (string $name, array $args) use (&$session) {
            $this->sessionCalls[] = $name;
            if ($name === 'setData') {
                $this->sessionData[$args[0]] = $args[1];
            } elseif ($name === 'unsetData') {
                unset($this->sessionData[$args[0]]);
            }

            return $session;
        });

        return $session;
    }
}
