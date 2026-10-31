<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\SocialLogin\Test\Unit\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Math\Random;
use PHPUnit\Framework\TestCase;
use Zero\SocialLogin\Model\LinkRequests;
use Zero\SocialLogin\Model\Profile;
use Zero\SocialLogin\Test\Unit\DbStub;

/**
 * The emailed connect link's token: 43 letters and digits (`\z`, so no trailing newline), kept as its SHA-256 only,
 * compared with hash_equals, an hour, used once (only one of two takes gets it), replaced by a newer attempt.
 */
class LinkRequestsTest extends TestCase
{
    use DbStub;

    private const TOKEN = 'AbCdEfGhIjKlMnOpQrStUvWxYz0123456789abcdefg';

    private function requests(array $answers = [], ?Random $random = null): LinkRequests
    {
        return new LinkRequests($this->resource($this->connection($answers)), $random ?? $this->createStub(Random::class));
    }

    private function row(string $expiresAt, ?string $hash = null): array
    {
        return [
            'link_id' => '3', 'customer_id' => '5', 'provider' => 'google', 'subject' => 'g-1',
            'email' => 'ann@example.com', 'token_hash' => $hash ?? hash('sha256', self::TOKEN), 'expires_at' => $expiresAt,
        ];
    }

    private function future(): string
    {
        return gmdate('Y-m-d H:i:s', time() + 600);
    }

    public function testTokenMustBe43LettersAndDigits(): void
    {
        $this->assertSame(43, strlen(self::TOKEN));
        $this->assertTrue(LinkRequests::valid(self::TOKEN));
        $this->assertFalse(LinkRequests::valid(self::TOKEN . "\n"), 'a trailing newline passes $ but not \z');
        $this->assertFalse(LinkRequests::valid(substr(self::TOKEN, 1)));
        $this->assertFalse(LinkRequests::valid(self::TOKEN . 'a'));
        $this->assertFalse(LinkRequests::valid(substr(self::TOKEN, 1) . '-'));
        $this->assertFalse(LinkRequests::valid(''));
    }

    public function testAMalformedTokenNeverReachesTheDatabase(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects($this->never())->method('getConnection');
        $requests = new LinkRequests($resource, $this->createStub(Random::class));

        $this->assertNull($requests->peek(self::TOKEN . "\n"));
        $this->assertNull($requests->take(self::TOKEN . "\n"));
        $this->assertNull($requests->take('short'));
    }

    public function testCreateStoresOnlyTheHashAndReplacesTheOpenRequest(): void
    {
        $random = $this->createMock(Random::class);
        $random->expects($this->once())->method('getRandomString')
            ->with(43, Random::CHARS_LOWERS . Random::CHARS_UPPERS . Random::CHARS_DIGITS)
            ->willReturn(self::TOKEN);
        $before = time();

        $token = $this->requests([], $random)->create(5, new Profile('google', 'g-1', 'ann@example.com', true));

        $this->assertSame(self::TOKEN, $token);
        [$purge] = $this->callsOf('delete');
        $this->assertSame('zero_social_login_link', $purge[0]);
        $this->assertSame(['expires_at < ?'], array_keys($purge[1]), 'expired rows are purged');
        $this->assertEqualsWithDelta($before, strtotime($purge[1]['expires_at < ?'] . ' UTC'), 2);
        [$upsert] = $this->callsOf('insertOnDuplicate');
        [$table, $data, $update] = $upsert;
        $this->assertSame('zero_social_login_link', $table);
        $this->assertSame(hash('sha256', self::TOKEN), $data['token_hash']);
        $this->assertNotContains(self::TOKEN, $data, 'the raw token is never stored');
        $this->assertSame([5, 'google', 'g-1', 'ann@example.com'], [$data['customer_id'], $data['provider'], $data['subject'], $data['email']]);
        $this->assertEqualsWithDelta($before + LinkRequests::TTL, strtotime($data['expires_at'] . ' UTC'), 2, 'an hour, in UTC');
        $this->assertSame(['subject', 'email', 'token_hash', 'expires_at'], $update, 'a new attempt replaces the open token');
        $this->assertSame(['delete', 'insertOnDuplicate'], $this->dbSequence(), 'purge first, then the upsert');
    }

    public function testCreateKeepsNoEmptyEmail(): void
    {
        $random = $this->createStub(Random::class);
        $random->method('getRandomString')->willReturn(self::TOKEN);

        $this->requests([], $random)->create(5, new Profile('apple', 'a-1', '', true));

        $this->assertNull($this->callsOf('insertOnDuplicate')[0][1]['email']);
    }

    public function testLookUpIsByTheHash(): void
    {
        $this->requests(['fetchRow' => $this->row($this->future())])->peek(self::TOKEN);

        $this->assertContains(['token_hash = ?', hash('sha256', self::TOKEN)], $this->callsOf('select.where'));
    }

    public function testPeekLeavesTheRequestInPlace(): void
    {
        $requests = $this->requests(['fetchRow' => $this->row($this->future())]);

        $this->assertSame(
            ['link_id' => 3, 'customer_id' => 5, 'provider' => 'google', 'subject' => 'g-1', 'email' => 'ann@example.com'],
            $requests->peek(self::TOKEN)
        );
        $this->assertSame([], $this->callsOf('delete'), 'a GET may look, never use');
    }

    public function testPeekRefusesAnExpiredRequest(): void
    {
        $this->assertNull($this->requests(['fetchRow' => $this->row(gmdate('Y-m-d H:i:s', time() - 1))])->peek(self::TOKEN));
    }

    public function testAHashTheDatabaseMatchedLooselyIsRefused(): void
    {
        // A case-insensitive collation would match the upper-case hash; hash_equals does not.
        $row = $this->row($this->future(), strtoupper(hash('sha256', self::TOKEN)));

        $this->assertNull($this->requests(['fetchRow' => $row, 'delete' => 1])->take(self::TOKEN));
        $this->assertSame([], $this->callsOf('delete'));
    }

    public function testTakeIsSingleUse(): void
    {
        $deletes = [1, 0];
        $requests = $this->requests([
            'fetchRow' => $this->row($this->future()),
            'delete' => function () use (&$deletes) {
                return array_shift($deletes);
            },
        ]);

        $this->assertSame(5, $requests->take(self::TOKEN)['customer_id']);
        $this->assertNull($requests->take(self::TOKEN), 'the second of two takes gets nothing');
        $this->assertSame(
            ['zero_social_login_link', ['link_id = ?' => 3, 'token_hash = ?' => hash('sha256', self::TOKEN)]],
            $this->callsOf('delete')[0]
        );
    }

    public function testExpiredTokenIsRefusedAndSpent(): void
    {
        $requests = $this->requests(['fetchRow' => $this->row(gmdate('Y-m-d H:i:s', time() - 5)), 'delete' => 1]);

        $this->assertNull($requests->take(self::TOKEN));
        $this->assertCount(1, $this->callsOf('delete'), 'an expired token is removed too');
    }

    public function testCancelRemovesTheOpenRequestOfThatService(): void
    {
        $this->requests()->cancel(5, 'google');
        $this->requests()->cancelAll(9);

        $this->assertSame([
            ['zero_social_login_link', ['customer_id = ?' => 5, 'provider = ?' => 'google']],
            ['zero_social_login_link', ['customer_id = ?' => 9]],
        ], $this->callsOf('delete'));
    }
}
