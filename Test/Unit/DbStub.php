<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\SocialLogin\Test\Unit;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;

/**
 * A DB connection that answers from `$answers` (a value, or a Closure given the call's arguments) and records every
 * call, the select builders' too, in order in `$dbCalls` as [method, args]. Table names are returned as given.
 */
trait DbStub
{
    /** @var list<array{0: string, 1: array}> */
    private array $dbCalls = [];

    /**
     * @param array<string, mixed> $answers By method
     */
    private function connection(array $answers = []): AdapterInterface
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturnCallback(fn () => $this->select());
        $connection->method('quote')->willReturnCallback(fn ($value) => "'" . addslashes((string) $value) . "'");
        $defaults = [
            'delete' => 0, 'update' => 0, 'insert' => 1, 'insertOnDuplicate' => 1,
            'fetchRow' => false, 'fetchOne' => false, 'fetchAll' => [], 'fetchCol' => [],
            'isTableExists' => false,
        ];
        foreach (array_keys($defaults) as $method) {
            $connection->method($method)->willReturnCallback(function (...$args) use ($method, $answers, $defaults) {
                $this->dbCalls[] = [$method, $args];
                $answer = $answers[$method] ?? $defaults[$method];

                return $answer instanceof \Closure ? $answer(...$args) : $answer;
            });
        }
        foreach (['beginTransaction', 'commit', 'rollBack'] as $method) {
            $connection->method($method)->willReturnCallback(function (...$args) use ($method, $answers, &$connection) {
                $this->dbCalls[] = [$method, $args];
                if (($answers[$method] ?? null) instanceof \Closure) {
                    $answers[$method](...$args);
                }

                return $connection;
            });
        }

        return $connection;
    }

    private function select(): Select
    {
        $select = $this->createStub(Select::class);
        foreach (['from', 'where', 'forUpdate', 'join', 'order', 'limit'] as $method) {
            $select->method($method)->willReturnCallback(function (...$args) use ($method, &$select) {
                // PHPUnit passes the defaults too (where()'s $type = null): drop the trailing nulls.
                while ($args && end($args) === null) {
                    array_pop($args);
                }
                $this->dbCalls[] = ['select.' . $method, $args];

                return $select;
            });
        }

        return $select;
    }

    private function resource(AdapterInterface $connection): ResourceConnection
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return $resource;
    }

    /**
     * @return list<array> The arguments of each call of $method
     */
    private function callsOf(string $method): array
    {
        return array_values(array_map(
            static fn (array $call) => $call[1],
            array_filter($this->dbCalls, static fn (array $call) => $call[0] === $method)
        ));
    }

    /**
     * @return string[] The methods called, in order
     */
    private function dbSequence(): array
    {
        return array_map(static fn (array $call) => $call[0], $this->dbCalls);
    }
}
