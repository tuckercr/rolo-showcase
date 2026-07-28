<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/**
 * Lazy PDO connection — nothing touches the database until the first query,
 * so pages that never need the DB (login screen, static views) don't pay for
 * or fail on a connection.
 */
final class Database
{
    private ?PDO $pdo = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=utf8mb4',
                $this->config->dbHost,
                $this->config->dbName,
            );

            $this->pdo = new PDO($dsn, $this->config->dbUser, $this->config->dbPassword, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                // Everything is stored in UTC (CLAUDE.md "Dates & Time") — this
                // makes CURRENT_TIMESTAMP/CURDATE() agree with that rule.
                \Pdo\Mysql::ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
            ]);
        }

        return $this->pdo;
    }
}
