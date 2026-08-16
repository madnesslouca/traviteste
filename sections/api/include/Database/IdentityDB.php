<?php

namespace Database;

use PDO;
use RuntimeException;

/**
 * Connection to the identity/economy PostgreSQL database.
 *
 * Deliberately separate from DB (the game's MariaDB): identities, watch time and
 * the coin ledger are operated independently of a game world, and survive a
 * world being wiped and reinstalled.
 *
 * Configured entirely through environment variables so the same image can point
 * at the bundled postgres service or at an existing production instance.
 */
class IdentityDB extends PDO
{
    private static $instance;

    public function __construct()
    {
        $host = getenv('IDENTITY_DB_HOST') ?: 'identity_db';
        $port = getenv('IDENTITY_DB_PORT') ?: '5432';
        $name = getenv('IDENTITY_DB_NAME') ?: 'identity';
        $user = getenv('IDENTITY_DB_USER') ?: 'identity';
        $pass = getenv('IDENTITY_DB_PASSWORD') ?: 'identity';

        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $name);
        parent::__construct($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        //Everything this application owns lives in the `identity` schema, so it
        //can share a database with an unrelated system without colliding.
        $this->exec("SET search_path TO identity, public");
    }

    public static function getInstance()
    {
        if (!self::$instance) {
            try {
                self::$instance = new self();
            } catch (\PDOException $e) {
                throw new RuntimeException('Identity database unavailable: ' . $e->getMessage(), 0, $e);
            }
        }
        return self::$instance;
    }
}
