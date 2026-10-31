<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\SocialLogin\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Math\Random;

/**
 * zero_social_login_link: emailed links that connect a service to an
 * existing account without a password. One open link per account and
 * service (a new attempt replaces the token); the token is kept as its
 * SHA-256 only, lives an hour and is used once. Times are UTC from PHP.
 */
class LinkRequests
{
    public const TTL = 3600;
    /** Where the link's page keeps the token once it is out of the URL. */
    public const SESSION_KEY = 'zero_social_login_link_token';

    private const TABLE = 'zero_social_login_link';
    private const TOKEN_PATTERN = '/^[A-Za-z0-9]{43}\z/';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Random $random
    ) {
    }

    /**
     * @return string The token for the link (only its hash is stored)
     */
    public function create(int $customerId, Profile $profile): string
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $connection->delete($table, ['expires_at < ?' => gmdate('Y-m-d H:i:s')]);
        $token = $this->random->getRandomString(43, Random::CHARS_LOWERS . Random::CHARS_UPPERS . Random::CHARS_DIGITS);
        $connection->insertOnDuplicate($table, [
            'customer_id' => $customerId,
            'provider' => $profile->provider,
            'subject' => $profile->subject,
            'email' => $profile->email !== '' ? $profile->email : null,
            'token_hash' => hash('sha256', $token),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + self::TTL),
        ], ['subject', 'email', 'token_hash', 'expires_at']);

        return $token;
    }

    /**
     * The open request for this token, left in place (a GET may look).
     *
     * @return array{link_id: int, customer_id: int, provider: string, subject: string, email: string}|null
     */
    public function peek(string $token): ?array
    {
        $row = $this->find($token);

        return $row && $row['expires_at'] > gmdate('Y-m-d H:i:s') ? $this->normalize($row) : null;
    }

    /**
     * The open request for this token, removed so it can't be used again;
     * of two requests at once, only one gets it.
     *
     * @return array{link_id: int, customer_id: int, provider: string, subject: string, email: string}|null
     */
    public function take(string $token): ?array
    {
        $row = $this->find($token);
        if (!$row) {
            return null;
        }
        $deleted = $this->resource->getConnection()->delete($this->resource->getTableName(self::TABLE), [
            'link_id = ?' => (int) $row['link_id'],
            'token_hash = ?' => (string) $row['token_hash'],
        ]);
        if ($deleted !== 1 || $row['expires_at'] <= gmdate('Y-m-d H:i:s')) {
            return null;
        }

        return $this->normalize($row);
    }

    /**
     * The service got connected another way (a sign-in, My Account): the
     * emailed link must no longer do anything.
     */
    public function cancel(int $customerId, string $provider): void
    {
        $this->resource->getConnection()->delete($this->resource->getTableName(self::TABLE), [
            'customer_id = ?' => $customerId,
            'provider = ?' => $provider,
        ]);
    }

    public function cancelAll(int $customerId): void
    {
        $this->resource->getConnection()->delete($this->resource->getTableName(self::TABLE), [
            'customer_id = ?' => $customerId,
        ]);
    }

    public static function valid(string $token): bool
    {
        return preg_match(self::TOKEN_PATTERN, $token) === 1;
    }

    private function find(string $token): ?array
    {
        if (!self::valid($token)) {
            return null;
        }
        $hash = hash('sha256', $token);
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE))
                ->where('token_hash = ?', $hash)
        );

        return $row && hash_equals((string) $row['token_hash'], $hash) ? $row : null;
    }

    private function normalize(array $row): array
    {
        return [
            'link_id' => (int) $row['link_id'],
            'customer_id' => (int) $row['customer_id'],
            'provider' => (string) $row['provider'],
            'subject' => (string) $row['subject'],
            'email' => (string) $row['email'],
        ];
    }
}
