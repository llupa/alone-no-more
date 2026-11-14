<?php

declare(strict_types=1);

namespace App\World;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

/**
 * Builds connections to AzerothCore's own databases from a DSN string.
 *
 * DBAL 4 dropped URL parsing from DriverManager::getConnection(), so the DSN has to go through
 * DsnParser first. We connect to the server's schema directly rather than mapping it with the
 * ORM: those tables belong to AzerothCore and change with the core, not with us.
 */
final class ConnectionFactory
{
    private const SCHEME_MAP = [
        'mysql' => 'pdo_mysql',
        'mysql2' => 'pdo_mysql',
        'mariadb' => 'pdo_mysql',
    ];

    public static function create(string $dsn): Connection
    {
        return DriverManager::getConnection(
            (new DsnParser(self::SCHEME_MAP))->parse($dsn),
        );
    }
}
