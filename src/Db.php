<?php
declare(strict_types=1);

namespace LabAdmin;

use PDO;

/**
 * SQLite3 storage (via PDO). Schema is created / migrated on first use.
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo) {
            return self::$pdo;
        }
        $file = $GLOBALS['LABADMIN_CONFIG']['data_dir'] . '/labadmin.sqlite';
        $pdo = new PDO('sqlite:' . $file, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        self::migrate($pdo);
        return self::$pdo = $pdo;
    }

    private static function migrate(PDO $pdo): void
    {
        $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
        if ($version < 1) {
            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS users (
                    id            INTEGER PRIMARY KEY,
                    username      TEXT NOT NULL UNIQUE,
                    password_hash TEXT NOT NULL,
                    created_at    TEXT NOT NULL DEFAULT (datetime('now'))
                );
                CREATE TABLE IF NOT EXISTS hosts (
                    id            INTEGER PRIMARY KEY,
                    name          TEXT NOT NULL,
                    hostname      TEXT NOT NULL,
                    port          INTEGER NOT NULL DEFAULT 22,
                    username      TEXT NOT NULL,
                    auth_type     TEXT NOT NULL CHECK (auth_type IN ('password','key')),
                    secret_enc    TEXT,          -- encrypted password or private key
                    passphrase_enc TEXT,         -- encrypted key passphrase
                    use_sudo      INTEGER NOT NULL DEFAULT 0,
                    host_key_fp   TEXT,          -- pinned SHA256 fingerprint (TOFU)
                    capabilities  TEXT,          -- JSON: detected docker/lxc flavors
                    tags          TEXT NOT NULL DEFAULT '',
                    notes         TEXT NOT NULL DEFAULT '',
                    sort_order    INTEGER NOT NULL DEFAULT 0,
                    created_at    TEXT NOT NULL DEFAULT (datetime('now')),
                    updated_at    TEXT NOT NULL DEFAULT (datetime('now'))
                );
                CREATE TABLE IF NOT EXISTS audit_log (
                    id         INTEGER PRIMARY KEY,
                    ts         TEXT NOT NULL DEFAULT (datetime('now')),
                    username   TEXT,
                    host_id    INTEGER,
                    host_name  TEXT,
                    action     TEXT NOT NULL,
                    target     TEXT,
                    command    TEXT,
                    success    INTEGER NOT NULL DEFAULT 1,
                    message    TEXT
                );
                CREATE INDEX IF NOT EXISTS idx_audit_ts ON audit_log(ts DESC);
                CREATE TABLE IF NOT EXISTS login_attempts (
                    ip TEXT NOT NULL,
                    ts INTEGER NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_login_ip ON login_attempts(ip, ts);
                PRAGMA user_version = 1;
            SQL);
        }
        if ($version < 2) {
            // One-time tokens that hand an authenticated browser session over to the terminal service.
            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS term_tokens (
                    token     TEXT PRIMARY KEY,
                    host_id   INTEGER NOT NULL,
                    engine    TEXT,
                    target    TEXT,
                    username  TEXT NOT NULL,
                    expires   INTEGER NOT NULL
                );
                PRAGMA user_version = 2;
            SQL);
        }
    }

    /** @return array<int,array<string,mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public static function one(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public static function run(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function lastId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }
}
