<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\SocialLogin\Model;

use Magento\Framework\Phrase;

/**
 * A service profile matches an existing account by email: nobody is signed
 * in. The account connects the service after a sign-in in this browser
 * (Model\PendingLink) or, for an account without a password, through the
 * emailed link (`$emailed`).
 */
class LinkPendingException extends LoginException
{
    public function __construct(
        Phrase $phrase,
        public readonly int $customerId,
        public readonly bool $emailed
    ) {
        parent::__construct($phrase);
    }
}
