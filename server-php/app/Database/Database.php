<?php
declare(strict_types=1);

namespace App\Database;

use App\Support\ApiException;

/**
 * PostgreSQL via PDO for the payout ledger.
 *
 *   FINANCE_DB_HOST      (required)  e.g. localhost
 *   FINANCE_DB_PORT      (default 5432)
 *   FINANCE_DB_NAME      (required)
 *   FINANCE_DB_USER      (required)
 *   FINANCE_DB_PASSWORD
 *
 * Opened lazily: health, SSO and every route that does not touch payouts keep
 * working while it is unset. Routes that need it answer 503 "not configured".
 * The session runs in Asia/Kolkata so `::date` casts are IST calendar days
 * (financial year, TDS quarter, value date).
 */
final class Database
{
    private static ?\PDO $pdo = null;

    public static function isConfigured(): bool
    {
        return finance_env('FINANCE_DB_HOST') !== ''
            && finance_env('FINANCE_DB_NAME') !== ''
            && finance_env('FINANCE_DB_USER') !== '';
    }

    public static function pdo(): \PDO
    {
        if (self::$pdo instanceof \PDO) {
            return self::$pdo;
        }
        if (!self::isConfigured()) {
            throw ApiException::notConfigured('The Finance database is not configured (FINANCE_DB_HOST / FINANCE_DB_NAME / FINANCE_DB_USER).');
        }

        try {
            self::$pdo = self::connect(
                finance_env('FINANCE_DB_HOST'),
                (int) (finance_env('FINANCE_DB_PORT') ?: 5432),
                finance_env('FINANCE_DB_NAME'),
                finance_env('FINANCE_DB_USER'),
                finance_env('FINANCE_DB_PASSWORD'),
            );
        } catch (\PDOException $e) {
            error_log('[finance] database connection failed: ' . $e->getMessage());
            throw ApiException::notConfigured('The Finance database is unreachable.');
        }

        return self::$pdo;
    }

    public static function connect(string $host, int $port, string $name, string $user, string $password): \PDO
    {
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $name);
        $pdo = new \PDO($dsn, $user, $password, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
            \PDO::ATTR_TIMEOUT            => 5,
        ]);
        $pdo->exec("SET TIME ZONE 'Asia/Kolkata'");

        return $pdo;
    }

    /** Tests inject a connection. */
    public static function use(?\PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    /** True when the database answers and the schema is migrated. */
    public static function isReady(): bool
    {
        if (!self::isConfigured()) {
            return false;
        }
        try {
            $pdo = self::pdo();
            $row = $pdo->query("SELECT to_regclass('public.payout_batches') IS NOT NULL AS ok")->fetch();

            return (bool) ($row['ok'] ?? false);
        } catch (\Throwable) {
            return false;
        }
    }
}
