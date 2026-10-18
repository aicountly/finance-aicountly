<?php
declare(strict_types=1);

namespace App\Database;

/**
 * Tiny forward-only migration runner.
 *
 *   database/migrations/NNN_name.sql   applied in file-name order, each in its own transaction
 *   schema_migrations                  version (file stem), checksum (sha256), applied_at
 *
 * A migration that was already applied but whose file changed since is
 * refused (checksum mismatch) — write a new migration instead of editing one.
 * A session advisory lock keeps two runs (two deploys) from interleaving.
 */
final class Migrator
{
    private const LOCK_KEY = 7_302_119_001;

    public function __construct(private \PDO $pdo, private string $dir)
    {
    }

    /** @return list<array{version:string, file:string, checksum:string}> */
    public function available(): array
    {
        $files = glob(rtrim($this->dir, '/') . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        $out = [];
        foreach ($files as $file) {
            $out[] = [
                'version'  => basename($file, '.sql'),
                'file'     => $file,
                'checksum' => hash_file('sha256', $file) ?: '',
            ];
        }

        return $out;
    }

    /** @return array<string,string> version => checksum */
    public function applied(): array
    {
        $this->ensureTable();
        $rows = $this->pdo->query('SELECT version, checksum FROM schema_migrations ORDER BY version')->fetchAll();
        $out  = [];
        foreach ($rows as $row) {
            $out[(string) $row['version']] = (string) $row['checksum'];
        }

        return $out;
    }

    /** @return list<string> versions not yet applied */
    public function pending(): array
    {
        $applied = $this->applied();

        return array_values(array_map(
            static fn (array $m) => $m['version'],
            array_filter($this->available(), static fn (array $m) => !isset($applied[$m['version']])),
        ));
    }

    /**
     * Apply every pending migration.
     *
     * @param callable(string):void|null $log
     * @return list<string> versions applied by this call
     */
    public function migrate(?callable $log = null): array
    {
        $log ??= static function (string $line): void {
        };
        $this->ensureTable();
        $this->pdo->query('SELECT pg_advisory_lock(' . self::LOCK_KEY . ')');

        try {
            $applied = $this->applied();
            foreach ($this->available() as $m) {
                if (isset($applied[$m['version']]) && $applied[$m['version']] !== $m['checksum']) {
                    throw new \RuntimeException("Migration {$m['version']} was changed after it was applied (checksum mismatch). Add a new migration instead.");
                }
            }

            $done = [];
            foreach ($this->available() as $m) {
                if (isset($applied[$m['version']])) {
                    continue;
                }
                $sql = (string) file_get_contents($m['file']);
                $this->pdo->beginTransaction();
                try {
                    $this->pdo->exec($sql);
                    $stmt = $this->pdo->prepare('INSERT INTO schema_migrations (version, checksum) VALUES (?, ?)');
                    $stmt->execute([$m['version'], $m['checksum']]);
                    $this->pdo->commit();
                } catch (\Throwable $e) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    throw new \RuntimeException("Migration {$m['version']} failed: " . $e->getMessage(), 0, $e);
                }
                $log("applied {$m['version']}");
                $done[] = $m['version'];
            }

            return $done;
        } finally {
            $this->pdo->query('SELECT pg_advisory_unlock(' . self::LOCK_KEY . ')');
        }
    }

    private function ensureTable(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS schema_migrations (
                version    TEXT PRIMARY KEY,
                checksum   TEXT NOT NULL,
                applied_at TIMESTAMPTZ NOT NULL DEFAULT now()
            )
        SQL);
    }
}
