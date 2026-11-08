<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use RuntimeException;

/**
 * Minimal forward-only migration runner. Applies database/migrations/*.sql in
 * filename order and records each file in a `migrations` table so it never
 * runs twice. No down migrations — for a tool this size, roll forward.
 */
final class Migrator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationsDir,
    ) {
    }

    /**
     * @return list<string> filenames applied in this run
     */
    public function migrate(): array
    {
        $this->ensureMigrationsTable();

        $applied = $this->appliedMigrations();
        $ran = [];

        foreach ($this->migrationFiles() as $path) {
            $filename = basename($path);

            if (in_array($filename, $applied, true)) {
                continue;
            }

            $sql = file_get_contents($path);

            if ($sql === false) {
                throw new RuntimeException(sprintf('Cannot read migration file %s', $path));
            }

            foreach (self::splitStatements($sql) as $statement) {
                $this->pdo->exec($statement);
            }

            $insert = $this->pdo->prepare('INSERT INTO migrations (filename) VALUES (:filename)');
            $insert->execute(['filename' => $filename]);

            $ran[] = $filename;
        }

        return $ran;
    }

    /**
     * Split a migration file into individual statements. Strips `--` comment
     * lines and splits on semicolons — fine for our DDL/INSERT files; if we
     * ever need triggers or procedures, revisit this.
     *
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $lines = array_filter(
            explode("\n", $sql),
            static fn(string $line): bool => !str_starts_with(ltrim($line), '--'),
        );

        $statements = explode(';', implode("\n", $lines));

        return array_values(array_filter(
            array_map(trim(...), $statements),
            static fn(string $statement): bool => $statement !== '',
        ));
    }

    private function ensureMigrationsTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                filename VARCHAR(255) NOT NULL,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_migrations_filename (filename)
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci'
        );
    }

    /**
     * @return list<string>
     */
    private function appliedMigrations(): array
    {
        $rows = $this->pdo->query('SELECT filename FROM migrations')->fetchAll(PDO::FETCH_COLUMN);

        return array_map(strval(...), $rows);
    }

    /**
     * @return list<string>
     */
    private function migrationFiles(): array
    {
        $files = glob($this->migrationsDir . '/*.sql');

        if ($files === false) {
            throw new RuntimeException(sprintf('Cannot list migrations in %s', $this->migrationsDir));
        }

        sort($files);

        return $files;
    }
}
