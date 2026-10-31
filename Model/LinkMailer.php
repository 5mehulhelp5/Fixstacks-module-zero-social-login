<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\SocialLogin\Model;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The email with the link that connects a service to an account without a
 * password, sent to the account's own address (from the sender of Magento's
 * password emails).
 */
class LinkMailer
{
    public const XML_PATH_TEMPLATE = 'zero_social_login/general/link_email_template';
    private const XML_PATH_IDENTITY = 'customer/password/forgot_email_identity';

    public function __construct(
        private readonly TransportBuilder $transportBuilder,
        private readonly StateInterface $inlineTranslation,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Provider\Pool $pool,
        private readonly LoggerInterface $logger
    ) {
    }

    public function send(CustomerInterface $customer, Profile $profile, string $token): void
    {
        $store = $this->storeManager->getStore();
        $storeId = (int) $store->getId();
        $name = trim($customer->getFirstname() . ' ' . $customer->getLastname());
        $this->inlineTranslation->suspend();
        try {
            $this->transportBuilder
                ->setTemplateIdentifier(
                    (string) $this->scopeConfig->getValue(self::XML_PATH_TEMPLATE, ScopeInterface::SCOPE_STORE, $storeId)
                        ?: 'zero_social_login_general_link_email_template'
                )
                ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $storeId])
                ->setTemplateVars([
                    'name' => $name,
                    'provider' => $this->pool->get($profile->provider)?->getLabel() ?? $profile->provider,
                    'provider_email' => $profile->email,
                    'confirm_url' => $store->getUrl('sociallogin/link/confirm', [
                        '_query' => ['token' => $token],
                        '_secure' => true,
                        '_nosid' => true,
                    ]),
                    'store_name' => (string) $store->getFrontendName(),
                ])
                ->setFromByScope(
                    (string) $this->scopeConfig->getValue(self::XML_PATH_IDENTITY, ScopeInterface::SCOPE_STORE, $storeId)
                        ?: 'support',
                    $storeId
                )
                ->addTo((string) $customer->getEmail(), $name)
                ->getTransport()
                ->sendMessage();
        } catch (\Throwable $e) {
            $this->logger->error(
                'Zero Social Login: the connect link email to customer ' . $customer->getId() . ' failed: ' . $e->getMessage()
            );
        } finally {
            $this->inlineTranslation->resume();
        }
    }
}
