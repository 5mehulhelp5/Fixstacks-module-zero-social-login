<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\SocialLogin\Test\Unit\Model;

use Magento\Customer\Api\SessionCleanerInterface;
use Magento\Customer\Model\CustomerRegistry;
use Magento\Integration\Api\CustomerTokenServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zero\SocialLogin\Model\AccountReset;
use Zero\SocialLogin\Model\IdentityStore;
use Zero\SocialLogin\Model\LoginException;
use Zero\SocialLogin\Model\Profile;
use Zero\SocialLogin\Test\Unit\DbStub;

/**
 * The emailed link of a passwordless, unverified account: under SELECT ... FOR UPDATE inside one transaction the
 * account is re-checked (no password, the service account not connected elsewhere), then its other services, reset
 * token, confirmation, lock, other links and "Remember Me" sessions go and the new service is connected. Sessions,
 * the registry and API tokens are ended after the commit; a revoke failure is logged, not thrown.
 */
class AccountResetTest extends TestCase
{
    use DbStub;

    /** @var string[] */
    private array $after = [];
    /** @var string[] */
    private array $logs = [];
    private bool $revokeFails = false;
    /** @var int[] */
    private array $connectedTo = [];
    private Profile $profile;

    protected function setUp(): void
    {
        $this->profile = new Profile('google', 'g-1', 'ann@example.com', true);
    }

    private function reset(array $answers): AccountReset
    {
        $identities = $this->createStub(IdentityStore::class);
        $identities->method('findCustomerIds')->willReturnCallback(function (string $provider, string $subject) {
            $this->dbCalls[] = ['identities.findCustomerIds', [$provider, $subject]];

            return $this->connectedTo;
        });
        $identities->method('add')->willReturnCallback(function (int $customerId, Profile $profile) {
            $this->dbCalls[] = ['identities.add', [$customerId, $profile]];
        });
        $cleaner = $this->createStub(SessionCleanerInterface::class);
        $cleaner->method('clearFor')->willReturnCallback(function (int $id) {
            $this->after[] = 'clearFor:' . $id;
            $this->dbCalls[] = ['sessions.clearFor', [$id]];
        });
        $tokens = $this->createStub(CustomerTokenServiceInterface::class);
        $tokens->method('revokeCustomerAccessToken')->willReturnCallback(function (int $id) {
            $this->after[] = 'revoke:' . $id;
            $this->dbCalls[] = ['tokens.revoke', [$id]];
            if ($this->revokeFails) {
                throw new \RuntimeException('token store down');
            }

            return true;
        });
        $registry = $this->createStub(CustomerRegistry::class);
        $registry->method('remove')->willReturnCallback(function (int $id) {
            $this->after[] = 'registry:' . $id;
            $this->dbCalls[] = ['registry.remove', [$id]];
        });
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(function (string $message) {
            $this->logs[] = $message;
        });

        return new AccountReset($this->resource($this->connection($answers)), $identities, $cleaner, $tokens, $registry, $logger);
    }

    public function testResetClearsPasswordConfirmationAndOtherServices(): void
    {
        $this->connectedTo = [];
        $this->reset(['fetchOne' => null, 'isTableExists' => true])->resetAndConnect(5, $this->profile, 'Google');

        $this->assertSame([
            'isTableExists',
            'beginTransaction',
            'select.from',
            'select.where',
            'select.forUpdate',
            'fetchOne',
            'identities.findCustomerIds',
            'delete',
            'identities.add',
            'update',
            'delete',
            'delete',
            'commit',
            'registry.remove',
            'sessions.clearFor',
            'tokens.revoke',
        ], $this->dbSequence(), 'the password is re-read FOR UPDATE inside the transaction, before anything is changed;'
            . ' the registry, sessions and API tokens are ended only after the commit');
        $this->assertContains([true], $this->callsOf('select.forUpdate'));
        $this->assertContains(['entity_id = ?', 5], $this->callsOf('select.where'));
        $this->assertSame([
            ['zero_social_login_identity', ['customer_id = ?' => 5]],
            ['zero_social_login_link', ['customer_id = ?' => 5]],
            ['persistent_session', ['customer_id = ?' => 5]],
        ], $this->callsOf('delete'));
        $this->assertSame([[5, $this->profile]], $this->callsOf('identities.add'));
        $this->assertSame([[
            'customer_entity',
            [
                'password_hash' => null,
                'rp_token' => null,
                'rp_token_created_at' => null,
                'confirmation' => null,
                'lock_expires' => null,
                'failures_num' => 0,
            ],
            ['entity_id = ?' => 5],
        ]], $this->callsOf('update'));
        $this->assertSame(['registry:5', 'clearFor:5', 'revoke:5'], $this->after, 'sessions and tokens end after the commit');
        $this->assertStringContainsString('customer 5 connected google by email link', end($this->logs));
    }

    public function testWithoutThePersistentTableNoRememberMeRowsAreTouched(): void
    {
        $this->reset(['fetchOne' => ''])->resetAndConnect(5, $this->profile, 'Google');

        $this->assertNotContains(['persistent_session', ['customer_id = ?' => 5]], $this->callsOf('delete'));
        $this->assertCount(2, $this->callsOf('delete'));
        $this->assertCount(1, $this->callsOf('commit'));
    }

    public function testAnAccountThatHasAPasswordByNowIsNeverWiped(): void
    {
        try {
            $this->reset(['fetchOne' => '$2y$10$hash'])->resetAndConnect(5, $this->profile, 'Google');
            $this->fail('LoginException expected');
        } catch (LoginException $e) {
            $this->assertStringStartsWith('This account has a password. Sign in with it', $e->getMessage());
            $this->assertStringContainsString('then connect Google under My Account', $e->getMessage());
        }
        $this->assertSame([], $this->callsOf('delete'));
        $this->assertSame([], $this->callsOf('update'));
        $this->assertSame([], $this->callsOf('identities.add'));
        $this->assertSame([], $this->callsOf('commit'));
        $this->assertCount(1, $this->callsOf('rollBack'));
        $this->assertSame([], $this->after, 'no session or token is ended');
    }

    public function testAServiceAccountConnectedElsewhereMeanwhileIsRefusedUnderTheLock(): void
    {
        $this->connectedTo = [9];

        try {
            $this->reset(['fetchOne' => null])->resetAndConnect(5, $this->profile, 'Google');
            $this->fail('LoginException expected');
        } catch (LoginException $e) {
            $this->assertSame('This Google account is already connected to another account in our store.', $e->getMessage());
        }
        $sequence = $this->dbSequence();
        $this->assertLessThan(
            array_search('identities.findCustomerIds', $sequence, true),
            array_search('beginTransaction', $sequence, true),
            'the owner of the service account is checked again inside the transaction'
        );
        $this->assertSame([['google', 'g-1']], $this->callsOf('identities.findCustomerIds'));
        $this->assertSame([], $this->callsOf('delete'));
        $this->assertSame([], $this->callsOf('update'));
        $this->assertCount(1, $this->callsOf('rollBack'));
        $this->assertSame([], $this->after);
    }

    public function testTheSameAccountAlreadyConnectedIsNotARefusal(): void
    {
        $this->connectedTo = [5];

        $this->reset(['fetchOne' => null])->resetAndConnect(5, $this->profile, 'Google');

        $this->assertCount(1, $this->callsOf('commit'));
    }

    public function testAFailureInsideTheTransactionRollsBackAndEndsNoSession(): void
    {
        $reset = $this->reset(['fetchOne' => null, 'update' => function () {
            throw new \RuntimeException('deadlock');
        }]);

        try {
            $reset->resetAndConnect(5, $this->profile, 'Google');
            $this->fail('RuntimeException expected');
        } catch (\RuntimeException $e) {
            $this->assertSame('deadlock', $e->getMessage());
        }
        $this->assertCount(1, $this->callsOf('rollBack'));
        $this->assertSame([], $this->callsOf('commit'));
        $this->assertSame([], $this->after);
    }

    public function testAFailedCommitEndsNoSessionAndRevokesNoToken(): void
    {
        $reset = $this->reset(['fetchOne' => null, 'commit' => function () {
            throw new \RuntimeException('commit lost');
        }]);

        try {
            $reset->resetAndConnect(5, $this->profile, 'Google');
            $this->fail('RuntimeException expected');
        } catch (\RuntimeException $e) {
            $this->assertSame('commit lost', $e->getMessage());
        }
        $this->assertCount(1, $this->callsOf('rollBack'));
        $this->assertSame([], $this->after, 'nothing is ended while the reset may not be saved');
        $this->assertSame([], $this->logs);
    }

    public function testTokenRevokeFailureIsLoggedNotThrown(): void
    {
        $this->revokeFails = true;

        $this->reset(['fetchOne' => null])->resetAndConnect(5, $this->profile, 'Google');

        $this->assertSame(['registry:5', 'clearFor:5', 'revoke:5'], $this->after);
        $this->assertContains(
            'Zero Social Login: revoking the API tokens of customer 5 failed: token store down',
            $this->logs
        );
        $this->assertStringContainsString('connected google by email link', end($this->logs), 'the reset itself is still logged');
    }
}
