<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use PDOStatement;

/**
 * A PDO subclass that tracks query counts and timings.
 * Uses ATTR_STATEMENT_CLASS so prepare() returns a real PDOStatement subclass.
 */
final class ProfiledPDO extends PDO
{
    public function __construct(string $dsn, ?string $username = null, ?string $password = null, ?array $options = null)
    {
        parent::__construct($dsn, $username, $password, $options);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [ProfiledStatement::class, [$this]]);
    }
}

/**
 * PDOStatement subclass that times execute() calls.
 * PDO internally instantiates this via ATTR_STATEMENT_CLASS.
 */
class ProfiledStatement extends PDOStatement
{
    /** PDO passes itself as first ctor arg via ATTR_STATEMENT_CLASS. */
    protected function __construct(private readonly PDO $pdo)
    {
    }

    public function execute(?array $params = null): bool
    {
        $start = hrtime(true);
        $result = parent::execute($params);
        $ms = (hrtime(true) - $start) / 1_000_000;
        Database::trackQuery($ms, $this->queryString);
        return $result;
    }
}
