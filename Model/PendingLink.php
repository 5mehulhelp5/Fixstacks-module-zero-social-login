<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\SocialLogin\Model;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Message\ManagerInterface;
use Zero\SocialLogin\Model\Provider\Pool;

/**
 * A service that matched an existing account, remembered in this browser's
 * session for 15 minutes: when the same account signs in here (with its
 * password, a password set through "Forgot Your Password?", or a service
 * already connected), the service is connected. A sign-in of another
 * account drops it.
 */
class PendingLink
{
    private const SESSION_KEY = 'zero_social_login_pending_link';
    private const LIFETIME = 900;

    public function __construct(
        private readonly CustomerSession $session,
        private readonly Authenticator $authenticator,
        private readonly Pool $pool,
        private readonly ManagerInterface $messageManager
    ) {
    }

    public function remember(int $customerId, Profile $profile): void
    {
        $this->session->setData(self::SESSION_KEY, [
            'customerId' => $customerId,
            'provider' => $profile->provider,
            'subject' => $profile->subject,
            'email' => $profile->email,
            'createdAt' => time(),
        ]);
    }

    /**
     * After a sign-in of this customer.
     *
     * @return string|null The connected service's name
     */
    public function complete(int $customerId): ?string
    {
        $data = $this->session->getData(self::SESSION_KEY);
        if (!is_array($data)) {
            return null;
        }
        $this->session->unsetData(self::SESSION_KEY);
        if ((int) ($data['customerId'] ?? 0) !== $customerId
            || (int) ($data['createdAt'] ?? 0) <= time() - self::LIFETIME
        ) {
            return null;
        }
        $provider = $this->pool->getAvailableProvider((string) ($data['provider'] ?? ''));
        if (!$provider) {
            return null;
        }
        $profile = new Profile(
            (string) $data['provider'],
            (string) ($data['subject'] ?? ''),
            (string) ($data['email'] ?? ''),
            true
        );
        try {
            $this->authenticator->link($profile, $customerId);
        } catch (LoginException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());

            return null;
        }
        $this->messageManager->addSuccessMessage(
            $this->authenticator->connectedMessage($customerId, (string) $data['provider'])
        );

        return $provider->getLabel();
    }
}
