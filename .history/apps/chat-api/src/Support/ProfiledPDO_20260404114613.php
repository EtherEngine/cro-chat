<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use PDOStatement;

/**
 * A PDO subclass that tracks query counts and timings.
 * Drop-in replacement — all existing code using Database::connection()->prepare() works unchanged.
 */
final class ProfiledPDO extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new ProfiledStatement(parent::prepare($query, $options), $query);
    }
}

/**
 * Wraps PDOStatement to time execute() calls.
 */
final class ProfiledStatement
{
    public function __construct(
        private readonly PDOStatement $inner,
        private readonly string $sql
    ) {
    }

    public function execute(?array $params = null): bool
    {
        $start = hrtime(true);
        $result = $this->inner->execute($params);
        $ms = (hrtime(true) - $start) / 1_000_000;
        Database::trackQuery($ms, $this->sql);
        return $result;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): mixed
    {
        return $this->inner->fetch($mode, ...$args);
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->inner->fetchAll($mode, ...$args);
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->inner->fetchColumn($column);
    }

    public function rowCount(): int
    {
        return $this->inner->rowCount();
    }

    public function __call(string $name, array $arguments): mixed
    {
        return $this->inner->$name(...$arguments);
    }
}
