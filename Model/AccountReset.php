<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\SocialLogin\Model;

use Magento\Customer\Api\SessionCleanerInterface;
use Magento\Customer\Model\CustomerRegistry;
use Magento\Framework\App\ResourceConnection;
use Magento\Integration\Api\CustomerTokenServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * The emailed link of an account without a password and without a verified
 * email: whoever made the account may have connected a service of theirs
 * (one that doesn't verify emails) or asked for a password reset. Opening
 * the link proves the mailbox, so the account keeps only the new service:
 * the other services, a reset token and the pending confirmation go, and
 * every session, "Remember Me" session and API token is ended. An account with a password is never
 * reset (it connects a service only after a sign-in with that password).
 */
class AccountReset
{
    private const LINK_TABLE = 'zero_social_login_link';
    private const IDENTITY_TABLE = 'zero_social_login_identity';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly IdentityStore $identities,
        private readonly SessionCleanerInterface $sessionCleaner,
        private readonly CustomerTokenServiceInterface $tokenService,
        private readonly CustomerRegistry $customerRegistry,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @throws LoginException The account has a password by now
     */
    public function resetAndConnect(int $customerId, Profile $profile, string $label): void
    {
        $connection = $this->resource->getConnection();
        $customerTable = $this->resource->getTableName('customer_entity');
        // "Remember Me" (Magento_Persistent, when installed): its cookie would sign the old browser back in.
        $persistent = $this->resource->getTableName('persistent_session');
        $persistent = $connection->isTableExists($persistent) ? $persistent : null;
        $connection->beginTransaction();
        try {
            $hash = $connection->fetchOne(
                $connection->select()
                    ->from($customerTable, ['password_hash'])
                    ->where('entity_id = ?', $customerId)
                    ->forUpdate(true)
            );
            if ((string) $hash !== '') {
                throw new LoginException(Authenticator::passwordFirst($label));
            }
            // Checked again under the lock: the service account connected to another account meanwhile.
            foreach ($this->identities->findCustomerIds($profile->provider, $profile->subject) as $connectedId) {
                if ($connectedId !== $customerId) {
                    throw new LoginException(
                        __('This %1 account is already connected to another account in our store.', $label)
                    );
                }
            }
            $connection->delete($this->resource->getTableName(self::IDENTITY_TABLE), ['customer_id = ?' => $customerId]);
            $this->identities->add($customerId, $profile);
            $connection->update($customerTable, [
                'password_hash' => null,
                'rp_token' => null,
                'rp_token_created_at' => null,
                'confirmation' => null,
                'lock_expires' => null,
                'failures_num' => 0,
            ], ['entity_id = ?' => $customerId]);
            $connection->delete($this->resource->getTableName(self::LINK_TABLE), ['customer_id = ?' => $customerId]);
            if ($persistent !== null) {
                $connection->delete($persistent, ['customer_id = ?' => $customerId]);
            }
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
        $this->customerRegistry->remove($customerId);
        $this->sessionCleaner->clearFor($customerId);
        try {
            $this->tokenService->revokeCustomerAccessToken($customerId);
        } catch (\Throwable $e) {
            $this->logger->info(
                'Zero Social Login: revoking the API tokens of customer ' . $customerId . ' failed: ' . $e->getMessage()
            );
        }
        $this->logger->info(sprintf(
            'Zero Social Login: customer %d connected %s by email link; other services and sessions removed.',
            $customerId,
            $profile->provider
        ));
    }
}
